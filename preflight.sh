#!/usr/bin/env bash
# preflight.sh — Laravel edition. Hard gates for /preflight.
# Place at repo root, `chmod +x preflight.sh`. Exits non-zero if any gate fails.
#
# These gates are DETERMINISTIC by design, and that is why work keeps migrating
# INTO this file: a check here costs wall clock and ~0 tokens, while the same
# check in the /preflight audit costs tokens on every run and grows with the
# codebase. Anything scriptable belongs here, not there (lesson ladder rung 1).
#
# Escape hatches (env vars):
#   PF_QUICK=1      skip the expensive tail (vite build, pest, journeys) — for
#                   fast iteration only. NEVER the run that clears a story.
#   PF_FAIL_FAST=1  stop at the first failing gate instead of reporting all.
#   PF_PEST_ARGS    extra args appended to the unit+feature pest run.
#   PF_MAX_BYTES    cap on captured failure output per gate (default 4000).
#   PF_PACK_MAX_BYTES / PF_PACK_CELL_MAX / PF_PACK_BASE   audit-pack header (see below).
#   PF_ROW_MAX_INDEX / PF_ROW_MAX_INVENTORY   warn-only docs row length (300 / 600 B).
#   PF_DB_WARN / PF_WT_WARN / PF_LOG_WARN_MB  warn-only workspace growth thresholds
#                   (25 orphaned test DBs / 5 disposable worktrees / 512 MB of logs).
#
# Modes: `./preflight.sh` (all gates) · `story-status` · `gate <name>` (one gate) ·
#        `audit-pack [section]` (the audit header; prints, never fails).

set -uo pipefail
fail=0

# Per-run scratch dir. A fixed /tmp/pf.out is shared state: two worktrees (or two
# agents) running preflight at once overwrite each other's failure output and
# report the wrong reason. mktemp -d + trap keeps runs isolated and self-cleaning.
SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/preflight.XXXXXX") || { echo "mktemp -d failed"; exit 2; }
trap 'rm -rf "$SCRATCH"' EXIT

PF_MAX_BYTES="${PF_MAX_BYTES:-4000}"

emit_out() { # emit_out <file> — indent a gate's output, capped by BYTES not lines
  # Byte cap, not `head -20`: one pathological line (a 1.87 MB single-line JSON
  # blob, a minified stack trace) blows the context budget while satisfying any
  # line limit. Cap bytes first, then lines, and always say what was withheld —
  # silent truncation reads as "that's the whole error".
  local f="$1" total
  total=$(wc -c <"$f" | tr -d ' ')
  head -c "$PF_MAX_BYTES" "$f" | head -40 | sed 's/^/    /'
  if [ "$total" -gt "$PF_MAX_BYTES" ]; then
    echo "    ... truncated: $total bytes total (PF_MAX_BYTES=$PF_MAX_BYTES)"
  fi
}

check() { # check "name" "command"
  printf '%-30s' "$1"
  if eval "$2" >"$SCRATCH/pf.out" 2>&1; then
    echo "PASS"
  else
    echo "FAIL"; emit_out "$SCRATCH/pf.out"; fail=1
    if [ "${PF_FAIL_FAST:-}" = 1 ]; then
      echo "PF_FAIL_FAST=1 — stopping at first failure"; exit 1
    fi
  fi
}

warn() { # warn "name" "command" — like check(), but a failure is WARN and never fails the run
  # For gates being introduced against a live codebase: loud, with the evidence,
  # but exit 0. Flipping a warn to a check is a one-word edit once the backlog of
  # existing violations is cleared; a new hard FAIL on every existing project
  # teaches people to ignore the gates.
  printf '%-30s' "$1"
  if eval "$2" >"$SCRATCH/pf.out" 2>&1; then
    echo "PASS"
  else
    echo "WARN"; emit_out "$SCRATCH/pf.out"
  fi
}

reap() { # reap "name" <pid> <outfile> — report a gate started in the background
  # Concurrency shape: the cheap-but-slow linters run alongside the test suite
  # rather than after it, and are reaped in expected-completion order so a fast
  # failure surfaces early. Same gates, same verdicts, less wall clock.
  local name="$1" pid="$2" out="$3" rc=0
  wait "$pid" || rc=$?
  printf '%-30s' "$name"
  if [ "$rc" -eq 0 ]; then
    echo "PASS"
  else
    echo "FAIL"; emit_out "$out"; fail=1
    if [ "${PF_FAIL_FAST:-}" = 1 ]; then
      echo "PF_FAIL_FAST=1 — stopping at first failure"; exit 1
    fi
  fi
}

# Absolute path to THIS script's dir, so the story gate resolves `stories/` against
# the repo, not the caller's CWD — `cd elsewhere && ../preflight.sh` must not be
# able to silence the gate by making stories/ look absent.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- story status vs git (see codebase-standards.md "Docs assert facts") ---
# Every story with a build commit (feat|fix|test) on main must say `Status: built`.
# Build IDs are read through GIT_LOG_CMD so the gate is testable without real
# commits (override it with a stub). Vocabulary: stories/README.md §Status.
#
# KNOWN LIMIT: a story built BEFORE this commit-format convention has no
# conventional build commit, so its ID appears in no subject and cannot be seen
# here. This gate PREVENTS RECURRENCE; it does NOT find pre-existing legacy drift.
#
# set -e note: -e is LIVE only at the bare call site (`story_status_gate; exit $?`
# in story-status mode). At the full-run call site, check() invokes the gate as
# `if eval "$2"`, and bash ignores -e for everything inside a command used as an
# `if` condition — subshells included — so -e never actually reaches the gate on a
# normal run. The guards below (|| true / || rc=$? / explicit if — no bare
# `test && cmd` lists, which die under -e when the test is false) exist so the bare
# site, and any future refactor that drops the `if` wrapper, behave identically to
# the -e-off path. They are insurance, not something the current full run exercises.

# Default build-ID source: prefer origin/main, fall back to local main. If NEITHER
# resolves, this function returns 3; the gate treats any non-zero build-ID command
# (the 3 here, or any code from a GIT_LOG_CMD override) uniformly — it exits 2 with
# the reason surfaced. An unresolvable ref must NEVER look like "no build commits".
# DECISION: origin/main is preferred over local main, so the gate reads the SHARED
# main. "built" means a commit is on main, not on your laptop — a locally-committed
# but unpushed build is intentionally invisible here. Trade-off: a stale origin ref
# (no recent fetch) can green the gate. Intended; do not "fix" it to local main.
default_git_log() {
  if   git rev-parse --verify --quiet origin/main >/dev/null 2>&1; then git log --format=%s origin/main
  elif git rev-parse --verify --quiet main        >/dev/null 2>&1; then git log --format=%s main
  else echo "cannot resolve origin/main or main" >&2; return 3
  fi
}
GIT_LOG_CMD="${GIT_LOG_CMD:-default_git_log}"

story_status_gate() (
  cd "$SCRIPT_DIR" 2>/dev/null || { echo "cannot cd to script dir: $SCRIPT_DIR"; exit 2; }

  # stories/ ABSENT (deleted, renamed, wrong repo) is a FAIL; an empty scaffold is
  # a pass. These must be distinguished — an absent dir is not "no stories yet".
  if [ ! -d stories ]; then
    echo "stories/ directory not found under $SCRIPT_DIR — cannot verify status"
    exit 2
  fi

  # Empty scaffold (only README, or no story files) → legitimate silent pass.
  files=$(find stories -type f -name '*.md' 2>/dev/null | grep -v '/README\.md$') || true
  if [ -z "$files" ]; then exit 0; fi

  # Read build-commit subjects. A FAILED command FAILS the gate (rc 2) with the
  # error surfaced — never swallowed into an empty "no builds" result.
  errfile=$(mktemp "${TMPDIR:-/tmp}/pf_gitlog.XXXXXX") || { echo "mktemp failed"; exit 2; }
  trap 'rm -f "$errfile"' EXIT           # covers every exit path below, present and future
  rc_log=0
  subjects=$(eval "$GIT_LOG_CMD" 2>"$errfile") || rc_log=$?
  if [ "$rc_log" -ne 0 ]; then
    echo "build-ID command failed (exit $rc_log):"
    sed 's/^/    /' "$errfile"
    exit 2
  fi

  # feat|fix|test mark a story built; docs|chore|refactor deliberately do not.
  # Optional breaking-change marker allowed, e.g. feat(AP-1)!: subject.
  built_ids=$(printf '%s\n' "$subjects" \
    | grep -oE '^(feat|fix|test)\([A-Z]{2,}-[0-9]+[a-z]?\)!?:' \
    | grep -oE '[A-Z]{2,}-[0-9]+[a-z]?' \
    | sort -u) || true
  # Debug echo only in story-status/verbose mode — never noise on a normal run.
  if [ "${PF_STORY_DEBUG:-}" = 1 ]; then
    echo "build ids on main = [$(printf '%s' "$built_ids" | tr '\n' ' ')]" >&2
  fi
  # Command SUCCEEDED and returned no build commits → legitimate silent pass.
  if [ -z "$built_ids" ]; then exit 0; fi

  rc=0
  for id in $built_ids; do
    # Match slugged `<ID>-<slug>.md` (the /story convention) OR bare `<ID>.md`, so
    # a slugless-but-present file is NOT a false "no story file". Filename-convention
    # is a separate concern, deliberately not enforced by this status gate.
    matches=$(find stories -type f \( -name "${id}-*.md" -o -name "${id}.md" \) 2>/dev/null | sort) || true
    if [ -z "$matches" ]; then
      echo "${id}  <no story file>  status=missing"; rc=1; continue
    fi
    n=$(printf '%s\n' "$matches" | wc -l | tr -d ' ')
    if [ "$n" -gt 1 ]; then                     # ambiguous — never head -1 it away
      echo "${id}  <ambiguous: $(printf '%s' "$matches" | tr '\n' ' ')>  status=ambiguous"; rc=1; continue
    fi
    # Anchor on the "Status:" label (optionally **bold**), take first word — robust
    # to `Status: draft   Journey: …`, `**Status:** built`, `draft — pending`.
    status=$(grep -m1 -E '^\**Status:' "$matches" 2>/dev/null \
      | sed -E 's/^\**Status:\**[[:space:]]*//' \
      | grep -oE '^[A-Za-z]+' | head -1) || true
    if [ "$status" != "built" ]; then
      echo "${id}  ${matches}  status=${status:-<none>}"; rc=1
    fi
  done
  exit $rc
)

# --- story format (SB-1) ---
# Every story file at HEAD must parse: a Status: value from stories/README.md
# §Status, a filename that starts with a story ID, an H1. bin/story-index is the
# one parser (its JSON is the contract a board reads); this gate is its --check
# mode, so a project that drifts from the format is flagged when it happens, not
# discovered later as a blank row. It reads HEAD, not the working tree: the
# committed format is what every other reader sees. Sits BESIDE story_status_gate
# rather than replacing its parsing — that gate's logic is deliberately untouched.
story_format_gate() (
  cd "$SCRIPT_DIR" 2>/dev/null || { echo "cannot cd to script dir: $SCRIPT_DIR"; exit 2; }
  if [ ! -x bin/story-index ]; then echo "bin/story-index missing or not executable"; exit 2; fi
  bin/story-index --check . HEAD
)

# --- journey references vs reality (was an audit bullet; now deterministic) ---
# Two dangling-reference classes a diff-scoped audit structurally cannot see, both
# of which grow with journey count: a journey citing a story that has no file, and
# a journey naming a test path that does not exist (renamed/deleted test = a flow
# with no guard, silently).
#
# Deliberately NOT checked here: whether a journey doc's build status matches git.
# Per LESSONS L-2, a doc must not restate a machine-readable fact — build status is
# read from git by story_status_gate, and duplicating it into journey docs is the
# drift this project already banned. (docs/journeys/README.md still asks for
# "build status" per journey; that line contradicts L-2 and needs a /lesson call.)
journey_refs_gate() (
  cd "$SCRIPT_DIR" 2>/dev/null || { echo "cannot cd to script dir: $SCRIPT_DIR"; exit 2; }

  # ABSENT vs EMPTY, same distinction as story_status_gate: a missing dir is a
  # broken repo, an empty scaffold is a legitimate pass.
  if [ ! -d docs/journeys ]; then
    echo "docs/journeys/ not found under $SCRIPT_DIR — cannot verify journeys"
    exit 2
  fi
  journeys=$(find docs/journeys -type f -name '*.md' 2>/dev/null | grep -v '/README\.md$') || true
  if [ -z "$journeys" ]; then exit 0; fi

  rc=0
  for j in $journeys; do
    # Dangling story references.
    ids=$(grep -oE '\b[A-Z]{2,}-[0-9]+[a-z]?\b' "$j" 2>/dev/null | sort -u) || true
    for id in $ids; do
      m=$(find stories -type f \( -name "${id}-*.md" -o -name "${id}.md" \) 2>/dev/null) || true
      if [ -z "$m" ]; then echo "$j  references $id  <no story file>"; rc=1; fi
    done
    # Dangling journey-test paths. Matches any tests/... .php token in the doc.
    paths=$(grep -oE '\btests/[A-Za-z0-9_/.-]+\.php\b' "$j" 2>/dev/null | sort -u) || true
    for p in $paths; do
      if [ ! -f "$p" ]; then echo "$j  names test $p  <file missing>"; rc=1; fi
    done
  done
  exit $rc
)

# --- UI inventory currency (was an audit bullet; now deterministic) ---
# design-standards §Reuse before build only works if the inventory is complete —
# an un-inventoried component is invisible to the next story's reuse check, which
# is how duplicate UI gets built. Checking this in the audit meant re-reading the
# component tree every run; here it is a grep.
#
# Loose on purpose: it asserts the component's BASENAME appears somewhere in
# UI-INVENTORY.md, not that the row is well-formed. Tolerates false negatives
# (a name mentioned in prose passes) to avoid false positives that would train
# people to ignore the gate. Catches the case that matters: built, never listed.
ui_inventory_gate() (
  cd "$SCRIPT_DIR" 2>/dev/null || { echo "cannot cd to script dir: $SCRIPT_DIR"; exit 2; }

  comps=$(find resources/views/components app/Livewire -type f \
            \( -name '*.blade.php' -o -name '*.php' \) 2>/dev/null | sort) || true
  if [ -z "$comps" ]; then exit 0; fi          # no UI yet → nothing to inventory

  if [ ! -f docs/UI-INVENTORY.md ]; then
    echo "docs/UI-INVENTORY.md missing but $(printf '%s\n' "$comps" | wc -l | tr -d ' ') components exist"
    exit 2
  fi

  rc=0
  for c in $comps; do
    name=$(basename "$c" | sed -E 's/\.blade\.php$//; s/\.php$//')
    if ! grep -qF "$name" docs/UI-INVENTORY.md; then
      echo "$c  <not in UI-INVENTORY.md>"; rc=1
    fi
  done
  exit $rc
)

# --- docs row length (warn-only) ---
# docs/INDEX.md and docs/UI-INVENTORY.md are read by every scoped audit, so a row
# that grows is a cost paid on every run forever. Measured downstream: the indexes
# grew ~2.7 KB per story because /document appended prose to rows instead of
# moving it into the feature doc — 93% of a 901 KB audit pack was the two indexes.
# A row is a POINTER (name, one sentence, status, date, link); the detail belongs in
# the feature doc, where it is read once by whoever needs it rather than on every
# audit. WARN-only for now: flipping this to a hard FAIL is a later, deliberate
# change once downstream rows have been trimmed — a new gate that reds every
# existing project on its first pull teaches people to ignore gates.
PF_ROW_MAX_INDEX="${PF_ROW_MAX_INDEX:-300}"
PF_ROW_MAX_INVENTORY="${PF_ROW_MAX_INVENTORY:-600}"

docs_row_length_gate() (
  cd "$SCRIPT_DIR" 2>/dev/null || { echo "cannot cd to script dir: $SCRIPT_DIR"; exit 2; }
  rc=0
  # LC_ALL=C so awk's length() counts BYTES, which is what the pack cap measures.
  for spec in "docs/INDEX.md:$PF_ROW_MAX_INDEX" "docs/UI-INVENTORY.md:$PF_ROW_MAX_INVENTORY"; do
    f="${spec%%:*}"; max="${spec##*:}"
    [ -f "$f" ] || continue
    over=$(LC_ALL=C awk -v m="$max" '/^\|/ && length($0) > m { printf "  %s:%d  (%d B > %d B)\n    %s\n", FILENAME, NR, length($0), m, substr($0, 1, 160) "..." }' "$f") || true
    if [ -n "$over" ]; then
      echo "$f — rows over ${max} B — move detail to the feature doc, keep the row a pointer:"
      printf '%s\n' "$over"
      rc=1
    fi
  done
  exit $rc
)

# --- workspace growth (warn-only) ---
# Per-agent build artifacts — worktrees and their derived test databases — are
# created by every concurrent build and removed by nothing. Measured downstream
# after ~3 months: 586 worktrees (218.7 GB), 3,491 leftover test databases
# (43 GB), a volume 86% full, and 248 of 1,145 test failures in three weeks that
# were SQLSTATE exhaustion errors read as product bugs before anyone recognised
# them as infrastructure. The number belongs on screen every run, while a sweep
# is a minute's work, rather than at the point the disk stops.
#
# WARN-ONLY, permanently — not "for now" like the docs-row gate. Reclaiming disk
# is never more urgent than the build in front of you, and a gate that can block
# a build on housekeeping teaches people to skip gates. The counting is cheap
# (two bulk git calls and one SHOW DATABASES); the disposal decision is NOT made
# here — see bin/workspace-sweep.sh and docs/standards/disposal-standards.md.
workspace_growth_gate() {
  [ -x "$SCRIPT_DIR/bin/workspace-sweep.sh" ] || { echo "bin/workspace-sweep.sh not installed — skipped"; return 0; }
  "$SCRIPT_DIR/bin/workspace-sweep.sh" --quiet
}

# --- audit pack: the HEADER the audit subagent reads in ONE tool call ---
# `./preflight.sh audit-pack` prints a header that must land in a single tool
# result: scope, the changed files with per-file diff sizes, the exact commands to
# fetch the diff in chunks, the index docs PROJECTED to their pointer columns, a
# duplication-candidate scan, the story files, and the mechanical scans.
#
# WHY A HEADER AND NOT THE WHOLE PACK: the harness persists any tool result over
# its cutoff to a file and hands the agent a 2 KB preview. Measured 2026-08-22:
# a downstream session saw 49 KB inline / 50.8 KB persisted; probed here the same
# day, the main session cut at ~28 KiB (27.3 inline, 29.3 persisted) and a
# subagent somewhere in 30–39 KiB (30 inline, 39.1 persisted). The cutoff is a
# harness property, not a constant — hence the default of 28000, which landed in
# every context measured, and the env override. The previous pack
# (inline diff capped at 60 KB + both indexes cat'd whole under a comment reading
# "Index docs inline: small") had grown to 901 KB, 93% of it the two indexes, and
# had NEVER arrived in one call: every real audit sliced it with head/tail/sed and
# then explored — 28–62 turns against the agent file's "~6 further tool calls",
# 1.3–4.0M cached-input tokens per audit. A cost assumption written as a comment is
# not a check. So the header is capped (PF_PACK_MAX_BYTES), measured, and gated:
# `audit pack <= cap` below FAILS the run naming the largest section and the
# remedy, and the pack itself prints an overflow banner FIRST so that it is what
# survives a 2 KB preview.
#
# WHY THE DIFF IS FETCHED, NOT INLINED: the diff is the one part that does NOT grow
# with story count, yet it was the only part that was capped. Listing per-file
# sizes and batched `git diff` commands chunked under the cap costs one turn per
# chunk — the same turns the agent was already spending to slice the blob, minus
# the guessing. App code is chunked first so the audit reads what it judges before
# what it merely confirms.
#
# WHY THE INDEXES ARE PROJECTED: a six-run A/B on a real story with a known
# CRITICAL found that cutting index cells to 110 chars changed neither the catch
# rate nor the cost (±7%), and that no run used the inventory for duplication
# detection — it only checked new components were listed. The prose in the rows is
# therefore cost without yield for the audit. Rows become pointers here (and per
# the /document rule, in the files themselves); the DUPLICATION CANDIDATES scan
# below does the inventory comparison mechanically and hands the agent rows to
# JUDGE rather than a table to search.
#
# Everything here is mechanical: no finding is made, only material to judge.
#
# Env: PF_PACK_MAX_BYTES (header cap, default 28000 — set it to YOUR harness's
#      measured cutoff, `bin/preflight-ab.sh probe` measures it)
#      PF_PACK_CELL_MAX  (max chars per projected cell, default 110)
#      PF_PACK_BASE      (pin the diff base to a ref/sha — the A/B harness uses it)
PF_PACK_MAX_BYTES="${PF_PACK_MAX_BYTES:-28000}"
PF_PACK_CELL_MAX="${PF_PACK_CELL_MAX:-110}"

pack_base() {
  if [ -n "${PF_PACK_BASE:-}" ]; then echo "$PF_PACK_BASE"; return; fi
  if git rev-parse --verify --quiet origin/main >/dev/null 2>&1; then echo origin/main; else echo main; fi
}

# pack_py <script-name> <args...> — run one of the embedded python helpers.
# python3 is required by bin/preflight-meter.py already, so the pack shares the
# dependency rather than adding a second implementation in perl that would drift.
# Cell capping is CHARACTER-aware on purpose: awk substr / head -c split multibyte
# characters and hand the agent mojibake; python slices code points.
pack_py() {
  if ! command -v python3 >/dev/null 2>&1; then
    echo "(NOT PROJECTED: python3 missing — audit must mark Docs/Design NOT AUDITED)"
    return 0
  fi
  python3 - "$@" <<'PY'
import re, sys

def cap(s, n):
    s = s.strip()
    return s if len(s) <= n else s[: max(0, n - 1)] + "…"

def rows_of(path):
    """(header_cells, [row_cells...], [raw_row_lines...]) of the FIRST markdown table in path."""
    header, rows, raw = None, [], []
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.rstrip("\n")
            if not line.startswith("|"):
                if header is not None and rows:
                    break
                continue
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            if header is None:
                header = cells
                continue
            if all(re.fullmatch(r":?-{2,}:?", c or "--") for c in cells):
                continue  # separator row
            rows.append(cells)
            raw.append(line)
    return header or [], rows, raw

def col(header, *names):
    low = [h.lower() for h in header]
    for n in names:
        for i, h in enumerate(low):
            if h.startswith(n):
                return i
    return None

def cell(row, i):
    return row[i] if i is not None and i < len(row) else ""

cmd = sys.argv[1]

if cmd == "project":
    # project <file> <index|inventory> <cell_max> <needles_file> <related_only 0|1>
    path, kind, cmax, needles_path, related_only = sys.argv[2], sys.argv[3], int(sys.argv[4]), sys.argv[5], sys.argv[6] == "1"
    header, rows, raw = rows_of(path)
    needles = open(needles_path, encoding="utf-8", errors="replace").read().lower() if needles_path != "-" else ""
    if not header:
        print("(no table found)")
        sys.exit(0)
    if kind == "index":
        ci = [col(header, "feature", "name"), col(header, "status"), col(header, "last updated", "updated", "date"), col(header, "doc", "link")]
        print("| Feature | Status | Updated | Doc |")
        print("|---|---|---|---|")
    else:
        ci = [col(header, "component", "name"), col(header, "type")]
        print("| Component | Type |   (full row shown when the component is named in the diff)")
        print("|---|---|")
    shown = omitted = 0
    for r, line in zip(rows, raw):
        name = re.sub(r"[`*]", "", cell(r, ci[0])).strip().lower()
        in_diff = bool(name) and name in needles
        if related_only and not in_diff:
            omitted += 1
            continue
        if kind == "inventory" and in_diff:
            print("| " + " | ".join(cap(c, cmax) for c in r) + " |   <-- named in diff")
        else:
            print("| " + " | ".join(cap(cell(r, i), cmax) for i in ci) + " |")
        shown += 1
    tag = "rows named in the diff" if related_only else "rows"
    print(f"({shown} {tag}" + (f", {omitted} omitted — `./preflight.sh audit-pack {'index' if kind=='index' else 'inventory'}` prints the full projection" if omitted else "") + ")")

elif cmd == "dups":
    # dups <inventory_file> <added_files_list>
    inv, listing = sys.argv[2], sys.argv[3]
    STOP = set("""this that with from into when then than will have been were what which where while also only each more most
    some such very your they them their there these those here over under after before about because should would could does
    done being make made uses used using component components livewire blade view views render renders class returns return
    file files page pages show shows list lists item items data value values field fields null true false""".split())
    header, rows, raw = rows_of(inv)
    inv_tokens = [(set(re.findall(r"[a-z0-9]+", line.lower())), line) for line in raw]
    any_out = False
    for f in open(listing, encoding="utf-8", errors="replace").read().split():
        base = re.sub(r"\.blade\.php$|\.php$", "", f.rsplit("/", 1)[-1])
        stem = {t.lower() for t in re.findall(r"[A-Z]?[a-z0-9]+|[A-Z]+(?![a-z])", base) if len(t) >= 3} - STOP
        try:
            head = open(f, encoding="utf-8", errors="replace").read(1500)
        except OSError:
            head = ""
        m = re.search(r"\{\{--(.*?)--\}\}|/\*\*(.*?)\*/|<!--(.*?)-->|((?:^\s*(?://|#).*\n?)+)", head, re.S | re.M)
        comment = next((g for g in (m.groups() if m else ()) if g), "")[:600]
        words = {w for w in re.findall(r"[a-z]{4,}", comment.lower())} - STOP
        hits = []
        for toks, line in inv_tokens:
            if (stem & toks) or len(words & toks) >= 2:
                hits.append(line)
        any_out = True
        print(f"NEW {f}")
        print(f"  stem: {' '.join(sorted(stem)) or '-'}   header words: {' '.join(sorted(words)[:12]) or '-'}")
        if hits:
            print("  candidates (inventory rows sharing a stem token or >=2 header words — JUDGE, not findings):")
            for h in hits[:8]:
                print("    " + h)
            if len(hits) > 8:
                print(f"    ... {len(hits) - 8} more")
        else:
            print("  candidates: none by name stem or header words (say so; the full sweep is the definitive check)")
    if not any_out:
        print("(no component / Livewire files added by this diff)")
PY
}

# build_pack_header <dir> — writes one file per section into <dir> (NN-name.txt),
# plus <dir>/SIZES ("bytes\tsection" lines, largest first) and <dir>/TOTAL.
build_pack_header() (
  cd "$SCRIPT_DIR" 2>/dev/null || { echo "cannot cd to script dir: $SCRIPT_DIR"; exit 2; }
  d="$1"; mkdir -p "$d"
  max="$PF_PACK_MAX_BYTES"
  base=$(pack_base)

  files=$(git diff --name-only "$base"...HEAD 2>/dev/null) || true
  n=$(printf '%s' "$files" | grep -c . || true)

  # Every changed file's diff, once, to a scratch file: sizes, needles and the
  # duplication scan all read from here instead of re-running git.
  dfile="$d/full.diff"
  git diff "$base"...HEAD -- . ':!*.lock' ':!package-lock.json' ':!public/build/**' ':!*.min.*' >"$dfile" 2>/dev/null || true

  { echo "=== SCOPE ==="
    echo "branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
    echo "base:   $base"
    echo "changed files: $n"
    echo "header cap: ${max} B (PF_PACK_MAX_BYTES) · cell cap: ${PF_PACK_CELL_MAX} chars"
    if [ "$n" -eq 0 ]; then
      echo "NOTE: no diff vs $base — a scoped audit cannot see anything. Use mode: full."
    fi
  } >"$d/01-scope.txt"

  # FILES CHANGED — status, diff bytes, path. Sizes drive the chunking below.
  sizes="$d/sizes.tsv"; : >"$sizes"
  git diff --name-status "$base"...HEAD 2>/dev/null | while IFS=$'\t' read -r st p1 p2; do
    p="${p2:-$p1}"   # renames: take the new path
    case "$p" in *.lock|package-lock.json|public/build/*|*.min.*) continue;; esac
    b=$(git diff "$base"...HEAD -- "$p" 2>/dev/null | wc -c | tr -d ' ')
    printf '%s\t%s\t%s\n' "$st" "$b" "$p"
  done >"$sizes"
  { echo "=== FILES CHANGED (status  diff-bytes  path) ==="
    if [ -s "$sizes" ]; then awk -F'\t' '{printf "%-3s %8s  %s\n", $1, $2, $3}' "$sizes"; else echo "(none)"; fi
  } >"$d/02-files.txt"

  # FETCH DIFF — batched commands, chunked under the cap, app code first.
  # Priority: what the audit JUDGES (app, views, routes, db) before what it
  # confirms (tests), before docs/stories (already in the pack as story files).
  { echo "=== FETCH DIFF (run EACH command in its own tool call; do not head/tail the pack) ==="
    if [ ! -s "$sizes" ]; then echo "(no diff)"; else
      awk -F'\t' '{
        p=$3; pr=5
        if (p ~ /^(app|resources|routes|database|config|bootstrap)\//) pr=1
        else if (p ~ /^tests\//) pr=3
        else if (p ~ /^(docs|stories)\//) pr=4
        printf "%d\t%s\t%s\n", pr, $2, p }' "$sizes" | sort -t$'\t' -k1,1n -k3,3 \
      | awk -F'\t' -v cap="$max" -v base="$base" '
        function flush(   i, cmd) {
          if (cnt == 0) return
          chunk++
          printf "# chunk %d — %d B — %s\n", chunk, sum, label
          cmd = "git diff " base "...HEAD --"
          for (i = 1; i <= cnt; i++) cmd = cmd " " q(list[i])
          print cmd
          cnt = 0; sum = 0
        }
        function q(s) { gsub(/\047/, "\047\\\047\047", s); return "\047" s "\047" }
        BEGIN { chunk = 0; cnt = 0; sum = 0 }
        {
          pr = $1; b = $2 + 0; p = $3
          l = (pr == 1) ? "app code" : (pr == 3) ? "tests" : (pr == 4) ? "docs/stories" : "other"
          if (b > cap) {
            flush()
            chunk++
            printf "# chunk %d — %d B — %s — EXCEEDS THE CAP ALONE: read it in slices (Read with offset/limit) or pipe through head -c %d\n", chunk, b, p, cap
            print "git diff " base "...HEAD -- " q(p)
            next
          }
          if (cnt > 0 && (sum + b > cap || label != l)) flush()
          label = l; list[++cnt] = p; sum += b
        }
        END { flush() }'
    fi
  } >"$d/03-fetch.txt"

  { echo "=== STORY FILES FOR IDS IN THIS BRANCH ==="
    ids=$(git log --format=%s "$base"..HEAD 2>/dev/null | grep -oE '[A-Z]{2,}-[0-9]+[a-z]?' | sort -u) || true
    if [ -z "$ids" ]; then echo "(no story IDs in commit subjects on this branch)"; fi
    for id in $ids; do
      find stories -type f \( -name "${id}-*.md" -o -name "${id}.md" \) 2>/dev/null || true
    done
  } >"$d/04-stories.txt"

  # DUPLICATION CANDIDATES — for every component/Livewire file ADDED, the
  # inventory rows that share a name stem or >=2 distinctive header-comment words.
  added="$d/added.txt"
  git diff --name-only --diff-filter=A "$base"...HEAD 2>/dev/null \
    | grep -E '^(resources/views/(components|livewire)/|app/Livewire/)' >"$added" || true
  { echo "=== DUPLICATION CANDIDATES (mechanical — candidates to JUDGE, never findings) ==="
    if [ -f docs/UI-INVENTORY.md ]; then pack_py dups docs/UI-INVENTORY.md "$added"
    else echo "(no docs/UI-INVENTORY.md)"; fi
  } >"$d/05-dups.txt"

  # Scans, CHANGED FILES ONLY — the greps the agent would otherwise issue singly.
  { echo "=== SCAN: hardcoded style values in changed files (design tokens) ==="
    for f in $(printf '%s\n' "$files" | grep -E '\.(php|blade\.php|js|vue)$' || true); do
      [ -f "$f" ] || continue
      grep -nE '#[0-9a-fA-F]{3,8}\b|[^-a-z][0-9]+px\b' "$f" 2>/dev/null | head -8 | sed "s|^|$f:|" || true
    done | head -60
    echo
    echo "=== SCAN: logging presence in changed PHP (logging-standards) ==="
    for f in $(printf '%s\n' "$files" | grep -E '\.php$' || true); do
      [ -f "$f" ] || continue
      c=$(grep -cE '\bLog::|logger\(' "$f" 2>/dev/null || true)
      echo "$f  log-calls=$c"
    done | head -60
    echo
    echo "=== SCAN: migrations added in this branch ==="
    printf '%s\n' "$files" | grep -E '^database/migrations/' || echo "(none)"
    echo
    echo "=== SCAN: skipped / incomplete tests in changed test files ==="
    for f in $(printf '%s\n' "$files" | grep -E '^tests/.*\.php$' || true); do
      [ -f "$f" ] || continue
      grep -nE '\b(skip|markTestSkipped|markTestIncomplete|todo)\s*\(' "$f" 2>/dev/null | sed "s|^|$f:|" || true
    done | head -30
  } >"$d/06-scans.txt"

  { echo "=== INDEX: journeys ==="
    if [ -d docs/journeys ]; then
      find docs/journeys -type f -name '*.md' 2>/dev/null | grep -v '/README\.md$' | sort || true
    else echo "(none)"; fi
  } >"$d/07-journeys.txt"

  # Indexes last: they are the only sections that grow with story count, so they
  # are the ones that degrade. Full projection if it fits in what is left of the
  # cap; otherwise only the rows the diff names, with the count of what was left
  # out and the command that prints the rest. The over-cap gate still judges the
  # TOTAL — degrading is how the header stays readable, not how the gate is dodged.
  needles="$d/needles.txt"
  { printf '%s\n' "$files"; cat "$dfile"; } >"$needles" 2>/dev/null
  project_indexes() { # project_indexes <related_only 0|1>
    { echo "=== INDEX: docs/INDEX.md (projected: Feature | Status | Updated | Doc) ==="
      if [ -f docs/INDEX.md ]; then pack_py project docs/INDEX.md index "$PF_PACK_CELL_MAX" "$needles" "$1"; else echo "(missing)"; fi
    } >"$d/08-index.txt"
    { echo "=== INDEX: docs/UI-INVENTORY.md (projected: Component | Type; full row if named in diff) ==="
      if [ -f docs/UI-INVENTORY.md ]; then pack_py project docs/UI-INVENTORY.md inventory "$PF_PACK_CELL_MAX" "$needles" "$1"; else echo "(missing)"; fi
    } >"$d/09-inventory.txt"
  }
  project_indexes 0
  core=$(cat "$d"/0[1-7]-*.txt | wc -c | tr -d ' ')
  idx=$(cat "$d"/0[89]-*.txt | wc -c | tr -d ' ')
  # +1 per section for the blank separator lines printed by audit_pack, +200 margin.
  if [ $((core + idx + 220)) -gt "$max" ]; then
    project_indexes 1
    { echo; echo "NOTE: full index projection (${idx} B) would push the header over ${max} B; only rows named in the diff are shown."; } >>"$d/09-inventory.txt"
  fi

  : >"$d/SIZES"
  for f in "$d"/0*-*.txt; do
    case "$f" in *06-scans.txt) label="SCANS (4 mechanical scans)";;
      *) label=$(head -1 "$f" | sed -E 's/^=== //; s/ ===$//; s/ \(.*//');; esac
    printf '%s\t%s\n' "$(wc -c <"$f" | tr -d ' ')" "$label" >>"$d/SIZES"
  done
  sort -t$'\t' -k1,1nr "$d/SIZES" -o "$d/SIZES"
  total=0; for f in "$d"/0*-*.txt; do total=$((total + $(wc -c <"$f" | tr -d ' ') + 1)); done
  echo "$total" >"$d/TOTAL"
)

# audit_pack [section] — print the header (or one named section).
audit_pack() (
  d="$SCRATCH/pack"
  build_pack_header "$d" || exit 0
  max="$PF_PACK_MAX_BYTES"
  if [ -n "${1:-}" ]; then
    case "$1" in
      scope) cat "$d/01-scope.txt";; files) cat "$d/02-files.txt";; fetch) cat "$d/03-fetch.txt";;
      stories) cat "$d/04-stories.txt";; dups) cat "$d/05-dups.txt";; scans) cat "$d/06-scans.txt";;
      journeys) cat "$d/07-journeys.txt";;
      # Full, undegraded projections on request — this is the command the header
      # names when it had to fall back to diff-named rows only.
      index)     { echo "=== INDEX: docs/INDEX.md (projected: Feature | Status | Updated | Doc) ==="; pack_py project docs/INDEX.md index "$PF_PACK_CELL_MAX" - 0; };;
      inventory) { echo "=== INDEX: docs/UI-INVENTORY.md (projected: Component | Type) ==="; pack_py project docs/UI-INVENTORY.md inventory "$PF_PACK_CELL_MAX" "$d/needles.txt" 0; };;
      *) echo "unknown section '$1' — one of: scope files fetch stories dups scans journeys index inventory"; exit 0;;
    esac
    exit 0
  fi
  total=$(cat "$d/TOTAL")
  if [ "$total" -gt "$max" ]; then
    # The banner goes FIRST: if this result is persisted, the 2 KB preview is all
    # the agent sees, and it must say what happened and how to proceed.
    echo "!!! PACK HEADER OVER CAP: ${total} B > PF_PACK_MAX_BYTES=${max} — this result was probably persisted; you may be reading a 2 KB preview."
    largest=$(head -1 "$d/SIZES" | cut -f2)
    echo "!!! Largest section: ${largest} ($(head -1 "$d/SIZES" | cut -f1) B). Remedy: $(pack_remedy "$largest")"
    echo "!!!         (or PF_PACK_MAX_BYTES=<your harness's measured cutoff>; bin/preflight-ab.sh probe measures it)"
    echo "!!! Proceed by fetching sections singly, each in its own call: ./preflight.sh audit-pack <scope|files|fetch|stories|dups|scans|journeys|index|inventory>"
    echo "!!! Sections by size:"; awk -F'\t' '{printf "!!!   %7s B  %s\n", $1, $2}' "$d/SIZES"
    echo
  fi
  for f in "$d"/0*-*.txt; do cat "$f"; echo; done
  echo "=== END PACK (${total} B of ${max} B cap) ==="
)

# pack_remedy <section-name> — the fix for the section that is largest. The
# indexes cannot blow the header on their own (they degrade to diff-named rows),
# so an overflow is almost always the diff's shape, and the remedy is the story's.
pack_remedy() {
  case "$1" in
    "FILES CHANGED"*|"FETCH DIFF"*) echo "the branch touches too many files for one audit — split the story, or land the mechanical part (formatting, renames) as its own commit on main first";;
    "STORY FILES"*)                 echo "too many story IDs in this branch — one story per branch";;
    "DUPLICATION CANDIDATES"*)      echo "many new components at once — split the story; or the inventory's Purpose cells are prose (keep rows pointers, see the docs-rows gate)";;
    "INDEX"*)                       echo "index rows are pointers: shorten Feature/Component names, move prose to the feature doc; the projection already degraded to diff-named rows";;
    "SCAN"*)                        echo "a scan hit its line cap on a very large diff — split the story";;
    *)                              echo "set PF_PACK_MAX_BYTES to your harness's measured cutoff (bin/preflight-ab.sh probe) if it is higher than ${PF_PACK_MAX_BYTES}";;
  esac
}

# Gate form of the cap — fails the run with the section named and the remedy.
audit_pack_gate() (
  d="$SCRATCH/packgate"
  build_pack_header "$d" || { echo "could not build the pack header"; exit 2; }
  total=$(cat "$d/TOTAL"); max="$PF_PACK_MAX_BYTES"
  if [ "$total" -gt "$max" ]; then
    largest=$(head -1 "$d/SIZES" | cut -f2); lbytes=$(head -1 "$d/SIZES" | cut -f1)
    echo "audit-pack header is ${total} B > PF_PACK_MAX_BYTES=${max} B — it will not land in one tool call."
    echo "largest section: ${largest} (${lbytes} B)"
    echo "remedy: $(pack_remedy "$largest")"
    echo "        (or PF_PACK_MAX_BYTES=<your measured cutoff>; bin/preflight-ab.sh probe measures it)"
    awk -F'\t' '{printf "  %7s B  %s\n", $1, $2}' "$d/SIZES"
    exit 1
  fi
  exit 0
)

# Single-gate test mode: `./preflight.sh story-status` runs ONLY this gate, with
# the build-ID debug line on, so exit code + resolved IDs are observable alone.
if [ "${1:-}" = "story-status" ]; then export PF_STORY_DEBUG=1; story_status_gate; exit $?; fi
# Generic single-gate mode: `./preflight.sh gate <name>` runs <name>_gate alone and
# exits with its code — how a gate's failure path is exercised on a fixture without
# the rest of the run (bin/preflight-fixture.sh builds one). Hyphens map to
# underscores, so `gate story-format` and `gate story_format` are the same gate.
if [ "${1:-}" = "gate" ]; then
  name="${2:-}"; fn="${name//-/_}_gate"
  if ! declare -F "$fn" >/dev/null; then echo "no such gate: ${2:-}  (try: $(declare -F | awk '{print $3}' | grep '_gate$' | sed 's/_gate$//' | tr '\n' ' '))"; exit 2; fi
  "$fn"; exit $?
fi

# Evidence-collection mode for the audit subagent. Runs no gates and starts no
# meter — it only prints. Always exits 0: an empty pack is a fact to report, not
# a gate failure.
if [ "${1:-}" = "audit-pack" ]; then audit_pack "${2:-}"; exit 0; fi

# --- cost meter ---
# Start the token/time meter for the cost label on line one of the report.
# It starts HERE, from inside a real full run, rather than from a hook that
# pattern-matches the command line: `grep preflight.sh`, `cat preflight.sh` or a
# test payload that merely CONTAINS the script name would all arm a hook, and a
# 500k-token label on a turn that never audited anything is worse than no label.
# Placed after the story-status early exit so the single-gate test mode stays free.
# CLAUDECODE guard: a plain terminal run has no turn to label.
if [ -n "${CLAUDECODE:-}" ] && [ -x "$SCRIPT_DIR/bin/preflight-meter.py" ]; then
  "$SCRIPT_DIR/bin/preflight-meter.py" start || true   # never let the meter fail a gate run
fi

echo "== PREFLIGHT HARD GATES (Laravel) =="

# ORDER IS DELIBERATE: every sub-second gate runs BEFORE the expensive tail.
# The debris/secret greps cost ~0.2s and used to sit behind a 578s test run, so a
# committed .env took ten minutes to report. Cheap gates first means the common
# failures surface in seconds.

# --- runtime & dependencies (sub-second) ---
check "php >= 8.3"           "php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'"
check "composer deps"        "test -d vendor && php artisan --version"
check "node deps (vite)"     "test -d node_modules"

# --- environment sanity (sub-second) ---
check ".env exists"          "test -f .env"
check "APP_KEY set"          "grep -qE '^APP_KEY=base64:' .env"
check "DB is MySQL (not sqlite)" "grep -qE '^DB_CONNECTION=mysql' .env"
# db:monitor, not db:show --json: same connect-or-fail signal (verified non-zero
# against a dead port) in ~51 B instead of ~1.87 MB of single-line JSON that the
# agent then has to carry.
check "DB reachable"         "php artisan db:monitor"
check "migrations current"   "! php artisan migrate:status | grep -q 'Pending'"

# --- git hygiene (sub-second) ---
check "git tree clean"       "test -z \"\$(git status --porcelain)\""
check "on feature branch"    "[ \"\$(git rev-parse --abbrev-ref HEAD)\" != 'main' ]"
check "story status vs git"  "story_status_gate"
check "story format"         "story_format_gate"

# --- debris & secrets (sub-second — moved ABOVE the tests, was below) ---
check "no dd()/dump()/ray()" "! grep -rnE '\\b(dd|dump|ray)\\(' app/ resources/views/ --include='*.php'"
check "no committed .env"    "! git ls-files | grep -qE '(^|/)\\.env$'"
check "secret grep"          "! git grep -nE '(api[_-]?key|secret|password|token)\\s*=>?\\s*[\"'\\''][A-Za-z0-9]{20,}' -- app/ config/ database/ ':!*.md'"

# --- docs vs reality (sub-second, scripted — see gates above) ---
check "journey refs resolve" "journey_refs_gate"
check "UI inventory current" "ui_inventory_gate"
check "audit pack <= cap"    "audit_pack_gate"
warn  "docs rows are pointers" "docs_row_length_gate"
warn  "workspace growth"      "workspace_growth_gate"

# --- expensive tail ---
if [ "${PF_QUICK:-}" = 1 ]; then
  echo "PF_QUICK=1 — SKIPPED: pint, phpstan, pest, journeys, vite build"
  echo "  NOT a valid preflight for clearing a story. Re-run without PF_QUICK."
else
  # pint and phpstan are slow but single-threaded and independent of the test DB,
  # so they run alongside the suite instead of queued behind it. Reaped after, in
  # cheapest-first order so a formatting failure is not withheld until phpstan ends.
  vendor/bin/pint --test                >"$SCRATCH/pint.out"    2>&1 & pint_pid=$!
  vendor/bin/phpstan analyse --no-progress >"$SCRATCH/phpstan.out" 2>&1 & stan_pid=$!

  # TEST SUITE SPLIT — unit+feature in parallel, Browser strictly serial.
  #
  # The Browser suite is serial by conservative choice, not by proven
  # constraint. The blocker is session-cookie collision, NOT the test database.
  # Pest 4's LaravelHttpServer::handleRequest() resolves the app through the
  # SAME in-process container as the test (app()->make(HttpKernel::class)), so
  # each paratest worker's server already holds that worker's _test_<token>
  # database — ServerManager even carries explicit Parallel::isWorker()
  # handling. What is NOT separated is the cookie jar: ServerManager::http()
  # binds every worker to DEFAULT_HOST '127.0.0.1' and varies only the port,
  # and browsers scope cookies by host while ignoring port. So parallel workers
  # overwrite each other's session, the logged-in user vanishes, and the test
  # lands on /register. Measured in the source project: 3/3 whole-suite
  # parallel runs failed, 2/2 Browser-alone runs passed.
  #
  # A fix (per-worker hostnames, or per-worker cookie isolation) is plausible
  # and unverified; serial holds until one is proven. If you change this, the
  # evidence is a --log-junit diff of test identities and statuses, not a count
  # or a green light — see codebase-standards.md §Testing (Pest).
  check "pest (unit+feature)"  "php artisan test --parallel --exclude-testsuite=Browser --compact ${PF_PEST_ARGS:-}"
  check "pest (browser)"       "php artisan test tests/Browser --compact"

  # Journey regression as a GATE, not an audit bullet. The audit used to re-run
  # these by hand, paying tokens for output it then had to read; here it is a
  # pass/fail. Runs ALL journeys, because later stories breaking an earlier link
  # in a flow is the whole point — and that risk only grows with journey count.
  if [ -d tests/Browser/Journeys ]; then
    check "journeys (all)"     "php artisan test tests/Browser/Journeys --compact"
  fi

  check "vite build"           "npm run build --silent"

  reap "pint (format)"    "$pint_pid" "$SCRATCH/pint.out"
  reap "phpstan (static)" "$stan_pid" "$SCRATCH/phpstan.out"
fi

echo "===================================="
if [ "$fail" -eq 0 ]; then echo "HARD GATES: PASS"; else echo "HARD GATES: FAIL — fix before building"; fi
exit $fail
