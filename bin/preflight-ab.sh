#!/usr/bin/env bash
# preflight-ab.sh — prove a preflight tooling change before merging it.
#
# WHY THIS EXISTS: every change to the audit (the pack shape, the agent file, the
# dispatch note) is a claim about what a Sonnet auditor will do with it, and the
# only honest test is to replay a real story — once as shipped, once with a known
# defect planted — and count what the auditor caught and what it cost. The six-run
# A/B that motivated the 2026-08 pack rewrite found that projecting the indexes
# changed nothing (±7%) and that the dispatch note changed everything (2/2 vs
# 0/4). A tooling change that was not replayed this way is an opinion.
#
# Three subcommands:
#
#   probe [bytes]
#       Emit exactly <bytes> of realistic text on stdout. Run it FROM A CLAUDE
#       SESSION at a ladder of sizes (24000 28000 32000 40000 48000) and watch
#       which results arrive inline and which come back as a persisted-output
#       notice — that is your harness's cutoff, and PF_PACK_MAX_BYTES must sit
#       under it. Measured 2026-08-22: ~28 KiB main session, 30–39 KiB subagent,
#       and a downstream session reported ~50 KB; it moves with the harness, so
#       measure, do not assume. With no argument, prints these instructions.
#
#   setup <replay-commit> <base-commit> <seeded-commit> [--dir DIR] [--defects FILE]
#       Create two detached worktrees of the CURRENT repo: A at <replay-commit>
#       (the story as shipped) and B at <seeded-commit> (the same story with the
#       planted defects), both with the pack base pinned to <base-commit> via
#       PF_PACK_BASE so the diff is exactly the story's. Builds each pack once,
#       prints its size and whether it lands inline under PF_PACK_MAX_BYTES, and
#       prints the dispatch-note template to use verbatim in BOTH arms. Then you
#       run the audit N times per arm from Claude Code (cd into the worktree,
#       `/preflight`, mode scoped, same note every run) and call `measure`.
#
#   measure <session.jsonl | subagents-dir> --defects FILE [--since ISO]
#       Parse the audit subagent transcripts and print one row per audit: turns,
#       tool calls, cached-input tokens, total tokens, pack bytes and whether it
#       arrived inline or persisted, and a hit/miss per seeded defect (the defect's
#       regex matched in the auditor's final report). Ends with hits/runs per
#       defect. This is the table a PR description should carry.
#
# THE SEEDED-DEFECT PATTERN (how to build <seeded-commit>):
#   Branch from <replay-commit> and plant ONE defect per pillar, each a single
#   small edit that a standards-reading auditor must flag:
#     Design   — a hardcoded colour/px in a changed view, or a second form for an
#                entity that already has one
#     DB/code  — a query in a Livewire component, or a schema tweak with no migration
#     Logging  — remove the log call from a write path the story touches
#     Tests    — skip() one acceptance-criterion test
#     Docs     — change behaviour the feature doc describes, leave the doc stale
#   PLUS one defect that is detectable ONLY through inventory content: a new
#   component whose header comment duplicates an inventoried component's Purpose
#   while its NAME shares no stem (e.g. `record-editor` vs `user-form-modal`).
#   That one measures whether the projected inventory + DUPLICATION CANDIDATES
#   still carry the signal the full inventory did. The sharpest plant is the one
#   the dispatch note can name without giving away: a gate bypassed in a view the
#   story did not touch — it is what 2/2 vs 0/4 was measured on.
#
#   The --defects file has one defect per line:  <label><TAB><regex>
#   e.g.   licence-bypass	licen[cs]e|withhold|bypass
#          hardcoded-colour	#3[0-9a-f]{5}|hardcoded
#   A defect "hits" when its regex matches (case-insensitive) the auditor's final
#   report. Write the regex against the FILE or RULE the report would name, not
#   the word "CRITICAL" — a false PASS also contains the word.
#
# Worktrees are detached and disposable: `git worktree remove --force DIR/A DIR/B`
# cleans up; `git worktree prune` after deleting DIR by hand.
set -euo pipefail

KIT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cmd="${1:-}"; shift || true

case "$cmd" in
  probe)
    if [ -z "${1:-}" ]; then
      sed -n '/^#   probe \[bytes\]/,/^#$/p' "$0" | sed 's/^# \{0,3\}//'
      echo "ladder: for n in 24000 28000 32000 40000 48000; do bin/preflight-ab.sh probe \$n; done  (one tool call each)"
      exit 0
    fi
    python3 - "$1" <<'PY'
import sys
n = int(sys.argv[1])
line = "| `component-%03d` | Blade | Purpose text for row %03d, realistic length, mixed tokens 2026-08-22 |\n"
out, i = [], 0
size = 0
while size < n:
    s = line % (i, i); out.append(s); size += len(s); i += 1
sys.stdout.write("".join(out)[:n])
PY
    ;;

  setup)
    replay="${1:-}"; base="${2:-}"; seeded="${3:-}"; shift 3 || { echo "usage: setup <replay> <base> <seeded> [--dir DIR] [--defects FILE]" >&2; exit 2; }
    dir=""; defects=""
    while [ $# -gt 0 ]; do case "$1" in --dir) dir="$2"; shift 2;; --defects) defects="$2"; shift 2;; *) echo "unknown flag $1" >&2; exit 2;; esac; done
    root=$(git rev-parse --show-toplevel)
    for c in "$replay" "$base" "$seeded"; do git rev-parse --verify --quiet "$c^{commit}" >/dev/null || { echo "not a commit: $c" >&2; exit 2; }; done
    base_sha=$(git rev-parse "$base")
    [ -z "$dir" ] && dir=$(mktemp -d "${TMPDIR:-/tmp}/pf-ab.XXXXXX")
    mkdir -p "$dir"
    git worktree add --detach -q "$dir/A" "$replay"
    git worktree add --detach -q "$dir/B" "$seeded"
    printf 'export PF_PACK_BASE=%s\n' "$base_sha" >"$dir/env"
    cap="${PF_PACK_MAX_BYTES:-28000}"
    echo "A/B worktrees under $dir  (base pinned: $base_sha)"
    for arm in A B; do
      if [ -x "$dir/$arm/preflight.sh" ]; then
        bytes=$(cd "$dir/$arm" && PF_PACK_BASE="$base_sha" ./preflight.sh audit-pack | tee "$dir/pack-$arm.txt" | wc -c | tr -d ' ')
        if [ "$bytes" -le "$cap" ]; then verdict="inline under cap $cap"; else verdict="OVER cap $cap — would be persisted; fix before measuring"; fi
        hunks=$(grep -c '^diff --git' "$dir/pack-$arm.txt" || true)
        echo "  $arm: $(git -C "$dir/$arm" rev-parse --short HEAD)  pack ${bytes} B — ${verdict}; inline diff hunks: ${hunks}"
      else
        echo "  $arm: $(git -C "$dir/$arm" rev-parse --short HEAD)  (no preflight.sh in this commit — pre-kit arm; pack not built)"
      fi
    done
    [ -n "$defects" ] && { cp "$defects" "$dir/defects.tsv"; echo "  defects: $(grep -c . "$dir/defects.tsv") seeded ($dir/defects.tsv)"; }
    cat <<EOF

NEXT — in Claude Code, for each arm (same note, same mode, N runs each):
  cd $dir/A && source $dir/env && /preflight      # mode: scoped
  cd $dir/B && source $dir/env && /preflight
Use this dispatch note VERBATIM in every run (fill the <...>; do NOT name the planted defects):

DISPATCH NOTE
Story: <two sentences; ID and story path>
Forbidden / CRITICAL if: <the invariant the feature rests on, the predicate/gate that enforces it,
  and "are there paths that bypass it? A leak here is CRITICAL">
Eye on:
- <thing> (→ design-standards §Reuse before build)
- <thing> (→ logging-standards §<section>)
- <thing> (→ codebase-standards §<section>)

THEN:  bin/preflight-ab.sh measure ~/.claude/projects/<slug>/<session>.jsonl --defects $dir/defects.tsv
CLEAN: git worktree remove --force $dir/A; git worktree remove --force $dir/B
EOF
    ;;

  measure)
    target="${1:-}"; shift || { echo "usage: measure <session.jsonl|subagents-dir> --defects FILE [--since ISO]" >&2; exit 2; }
    defects=""; since=""
    while [ $# -gt 0 ]; do case "$1" in --defects) defects="$2"; shift 2;; --since) since="$2"; shift 2;; *) echo "unknown flag $1" >&2; exit 2;; esac; done
    python3 - "$target" "$defects" "$since" <<'PY'
import json, re, sys
from pathlib import Path
from datetime import datetime

target, defects_path, since = sys.argv[1], sys.argv[2], sys.argv[3]
p = Path(target)
logs = sorted((p if p.is_dir() else p.parent / p.stem / "subagents").glob("agent-*.jsonl"))
if not logs:
    sys.exit(f"no agent-*.jsonl under {target}")
defects = []
if defects_path:
    for line in open(defects_path, encoding="utf-8"):
        if "\t" in line:
            lab, rx = line.rstrip("\n").split("\t", 1)
            defects.append((lab.strip(), re.compile(rx.strip(), re.I)))
since_dt = datetime.fromisoformat(since.replace("Z", "+00:00")) if since else None
PERSISTED = re.compile(r"Output too large \(([\d.]+)\s*KB\)", re.I)

rows = []
for log in logs:
    seen, calls, pack_ids, report = {}, {}, set(), ""
    pack_bytes, pack_state, is_audit, first_ts = 0, "-", False, None
    for raw in open(log, encoding="utf-8", errors="replace"):
        try: e = json.loads(raw)
        except json.JSONDecodeError: continue
        ts = e.get("timestamp")
        if since_dt and ts:
            try:
                if datetime.fromisoformat(ts.replace("Z", "+00:00")) < since_dt: continue
            except ValueError: pass
        msg = e.get("message") or {}; content = msg.get("content")
        if e.get("type") == "user" and isinstance(content, list):
            for b in content:
                if isinstance(b, dict) and b.get("type") == "tool_result" and b.get("tool_use_id") in pack_ids:
                    c = b.get("content"); text = "".join(x.get("text", "") for x in c if isinstance(x, dict)) if isinstance(c, list) else str(c or "")
                    m = PERSISTED.search(text)
                    pack_bytes = int(float(m.group(1)) * 1024) if m else len(text.encode()); pack_state = "persisted" if m else "inline"
            continue
        if e.get("type") != "assistant": continue
        first_ts = first_ts or ts
        mid = msg.get("id") or e.get("uuid")
        if isinstance(content, list):
            uses = [b for b in content if isinstance(b, dict) and b.get("type") == "tool_use"]
            calls[mid] = max(calls.get(mid, 0), len(uses))
            for u in uses:
                if "preflight.sh audit-pack" in str((u.get("input") or {}).get("command", "")):
                    is_audit = True; pack_ids.add(u.get("id"))
            texts = [b.get("text", "") for b in content if isinstance(b, dict) and b.get("type") == "text" and b.get("text")]
            if texts: report = "\n".join(texts)
        if msg.get("usage") and mid not in seen: seen[mid] = msg["usage"]
    if not is_audit: continue
    cached = sum(u.get("cache_read_input_tokens", 0) for u in seen.values())
    total = sum(u.get("input_tokens", 0) + u.get("cache_creation_input_tokens", 0) + u.get("cache_read_input_tokens", 0) + u.get("output_tokens", 0) for u in seen.values())
    hits = [bool(rx.search(report)) for _, rx in defects]
    rows.append((log.name, first_ts or "", len(seen), sum(calls.values()), cached, total, pack_bytes, pack_state, hits))

if not rows: sys.exit("no audit runs found (no subagent ran `preflight.sh audit-pack`)")
labels = [d[0] for d in defects]
print(f"{'run':<18} {'started':<20} {'turns':>5} {'calls':>5} {'cached':>9} {'total':>9} {'pack':>7} {'state':<9} " + " ".join(f"{l:<14}" for l in labels))
for name, ts, turns, calls, cached, total, pb, state, hits in rows:
    print(f"{name[:18]:<18} {ts[:19]:<20} {turns:>5} {calls:>5} {cached:>9} {total:>9} {pb:>7} {state:<9} " + " ".join(f"{'HIT' if h else 'miss':<14}" for h in hits))
print()
for i, l in enumerate(labels):
    n = sum(1 for r in rows if r[8][i]); print(f"{l}: {n}/{len(rows)}")
t = [r[2] for r in rows]; c = [r[4] for r in rows]
print(f"turns: min {min(t)} median {sorted(t)[len(t)//2]} max {max(t)} · cached tokens: min {min(c)} median {sorted(c)[len(c)//2]} max {max(c)} · runs: {len(rows)}")
PY
    ;;

  *)
    sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
    exit 2;;
esac
