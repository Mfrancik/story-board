#!/usr/bin/env bash
# Dispose of ONE agent build worktree and every database derived from it.
# The exact inverse of provision-worktree.sh — and it derives the worktree path
# and the test-DB name with the SAME expressions, so the pair cannot drift.
#
# Usage: bin/dispose-worktree.sh [--check] <name|path>
#   --check   evaluate and report only; drop nothing, remove nothing
#
# Exit: 0 disposed (or, under --check, disposable) · 1 SKIPPED with the failing
#       condition named · 2 usage/environment error.
#
# It NEVER prompts and NEVER forces. Every condition in
# docs/standards/disposal-standards.md §Rule 3 must hold or this skips and says
# which one failed. Age is not one of the conditions: a three-week-old worktree
# holding the only copy of unpushed commits and a three-week-old finished one
# look identical by mtime, and the difference is someone's work. The conjunction
# IS the safety argument, so it is evaluated in full before anything is
# destroyed, not checked opportunistically on the way through.
set -uo pipefail

CHECK=0
if [ "${1:-}" = "--check" ]; then CHECK=1; shift; fi
ARG="${1:-}"
[ -n "$ARG" ] || { echo "usage: dispose-worktree.sh [--check] <name|path>" >&2; exit 2; }

PRIMARY="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# git spells worktree paths the way the filesystem holds them. A path assembled
# from $0 — or typed by hand — can differ in CASE on macOS's case-insensitive
# filesystem and then match no registration at all, which would read as "not a
# worktree" and skip a tree that is perfectly disposable. Take git's spelling of
# both the primary checkout and the target.
GITMAIN="$(git -C "$PRIMARY" worktree list --porcelain 2>/dev/null | head -1 | sed 's/^worktree //')"
[ -n "$GITMAIN" ] && PRIMARY="$GITMAIN"
PROJECT="$(basename "$PRIMARY" | tr '[:upper:]-' '[:lower:]_' | tr -cd 'a-z0-9_')"
case "$ARG" in
  /*) WT="$ARG" ;;
  *)  WT="$PRIMARY/.claude/worktrees/$ARG" ;;
esac
CANON="$(git -C "$WT" rev-parse --show-toplevel 2>/dev/null || true)"
[ -n "$CANON" ] && WT="$CANON"
NAME="$(basename "$WT")"
# Same derivation as provision-worktree.sh. If you change one, change both.
TESTDB="${PROJECT}_test_$(echo "$NAME" | tr '[:upper:]-' '[:lower:]_' | tr -cd 'a-z0-9_')"
MYSQL="${PF_MYSQL:-mysql -u root}"
IDLE_MIN="${PF_IDLE_MINUTES:-30}"

# Ignored paths that do NOT block disposal (Rule 3b). Every entry is a promise
# that losing that path costs nothing, because `composer install`, `npm ci` or
# the next test run recreates it. The list is short on purpose: that promise is
# the whole thing 3b protects. Append per project with PF_IGNORED_ALLOW="a b".
IGNORED_ALLOW="vendor node_modules public/build public/hot public/storage bootstrap/cache .phpunit.cache storage/app/public/coins ${PF_IGNORED_ALLOW:-}"

SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/dispose.XXXXXX") || { echo "mktemp -d failed" >&2; exit 2; }
trap 'rm -rf "$SCRATCH"' EXIT

blockers=0
block() { echo "  BLOCKED: $*"; blockers=$((blockers + 1)); }

# ignored_blocks <path relative to the worktree> — 0 if this ignored entry must
# stop disposal. Three shapes, because git reports ignored paths at the
# shallowest level it can:
#   1. the entry is itself allow-listed (or inside one) — skip WITHOUT walking
#      it, or node_modules would cost 100k stat calls on every evaluation;
#   2. the entry is a PARENT that git collapsed because everything under it is
#      ignored — `public` reported instead of `public/build`, `storage/app`
#      instead of `storage/app/public/coins`. Descend, pruning the allow-listed
#      subtrees, and block only on what is left. Missing this case is the
#      difference between "disposable" and "kept forever";
#   3. anything else — block if it holds a file with bytes in it. An empty
#      ignored directory is evidence of nothing and must not strand a worktree.
ignored_blocks() {
  local pth="$1" a hit
  for a in $IGNORED_ALLOW; do
    case "$pth" in "$a"|"$a"/*) return 1 ;; esac
  done
  local -a prune=()
  for a in $IGNORED_ALLOW; do
    case "$a" in "$pth"/*) prune+=( -path "$WT/$a" -o ) ;; esac
  done
  if [ "${#prune[@]}" -gt 0 ]; then
    unset "prune[$(( ${#prune[@]} - 1 ))]"   # trailing -o
    hit="$(find "$WT/$pth" \( "${prune[@]}" \) -prune -o -type f -size +0c -print -quit 2>/dev/null)"
  else
    hit="$(find "$WT/$pth" -type f -size +0c -print -quit 2>/dev/null)"
  fi
  [ -n "$hit" ]
}

echo "worktree: $WT"
echo "test DB:  $TESTDB (+ its _test_<n> workers)"

# --- Rule 3, evaluated as a conjunction -------------------------------------
if ! git -C "$PRIMARY" worktree list --porcelain | grep -qx "worktree $WT"; then
  # A directory with no registration is NOT ours to delete by this path: it may
  # be a hand-made copy. `workspace-sweep.sh` reports it; a human removes it.
  block "not a registered worktree of $PRIMARY"
  echo "SKIPPED: $WT"; exit 1
fi

BRANCH="$(git -C "$WT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo HEAD)"
echo "branch:   $BRANCH"

# (c) main checked out here strands the primary checkout — git will not let the
# primary check main out again while this tree holds it.
if [ "$BRANCH" = "main" ]; then block "(c) main is checked out in this worktree"; fi
if [ "$BRANCH" = "HEAD" ]; then block "(c) detached HEAD — no branch to verify against main"; fi

# (a) merged into main OR present on a remote. Either one means the commits
# exist somewhere other than this directory. Neither means this directory is
# the only copy.
if [ "$BRANCH" != "HEAD" ]; then
  if ! git -C "$PRIMARY" rev-parse --verify --quiet main >/dev/null; then
    block "(a) cannot resolve 'main' in $PRIMARY"
  else
    merged="$(git -C "$PRIMARY" branch --merged main --format='%(refname:short)' | grep -Fx "$BRANCH" || true)"
    onremote="$(git -C "$PRIMARY" branch -r --contains "$BRANCH" 2>/dev/null || true)"
    if [ -z "$merged" ] && [ -z "$onremote" ]; then
      block "(a) $BRANCH is neither merged into main nor on any remote — these commits exist nowhere else"
    fi
  fi
fi

# (b) uncommitted, untracked, or PRESERVED IGNORED work. Untracked counts: a
# measurement written to a file and never committed is exactly the thing Rule 4
# exists to stop losing.
#
# --ignored is load-bearing, not thoroughness for its own sake. `git worktree
# remove` already refuses on modified and untracked files, so checking plain
# --porcelain here gives 3b and the remove guard ONE blind spot rather than two
# independent checks — neither sees ignored files. Measured on the live repo:
# 30 of 123 worktrees held non-empty `shots-sell36/`,
# `storage/app/browser-evidence/` or `shots/`, captured screenshots with no copy
# anywhere else. `shots-sell36/` exists BECAUSE Pest purges
# tests/Browser/Screenshots at the start of every browser run — it is the
# preserved copy, not a regenerable one.
#
# --ignored defaults to "traditional", which reports an ignored directory as one
# entry without descending, so node_modules costs one line, not 100k.
dirty=""
if ! git -C "$WT" status --porcelain -z --ignored >"$SCRATCH/status.z" 2>/dev/null; then
  block "(b) cannot read git status in this worktree"
else
  while IFS= read -r -d '' rec; do
    st="${rec:0:2}"; path="${rec:3}"
    # Anything not ignored blocks, exactly as before.
    if [ "$st" != "!!" ]; then dirty="${dirty}${st} ${path}"$'\n'; continue; fi
    pth="${path%/}"
    ignored_blocks "$pth" || continue
    dirty="${dirty}!! ${pth}"$'\n'
  done <"$SCRATCH/status.z"
fi
if [ -n "$dirty" ]; then
  block "(b) uncommitted, untracked, or preserved ignored files present:"
  printf '%s' "$dirty" | head -10 | sed 's/^/    /'
fi

# (d) live work against this tree. Three cheap signals, any one of which means
# something is still running here:
#   - a process whose command line names the path (pest, preflight, an agent)
#   - a MySQL connection open on its test DB or a worker DB derived from it
#   - a write inside the tree within PF_IDLE_MINUTES
live_pids="$(pgrep -f "$WT" 2>/dev/null | grep -vx "$$" | grep -vx "$PPID" || true)"
[ -n "$live_pids" ] && block "(d) live process(es) against this path: $(echo "$live_pids" | tr '\n' ' ')"

if command -v mysql >/dev/null 2>&1; then
  conns="$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.processlist WHERE db = '$TESTDB' OR db LIKE '${TESTDB}\\_test\\_%';" 2>/dev/null || echo 0)"
  [ "${conns:-0}" -gt 0 ] 2>/dev/null && block "(d) $conns open MySQL connection(s) on $TESTDB"
fi

# PF_IDLE_MINUTES=0 turns this signal off, for a caller that already knows the
# tree is idle (a merge step disposing of the tree it just merged) and for tests.
if [ "$IDLE_MIN" -gt 0 ]; then
  recent="$(find "$WT" -maxdepth 1 -newermt "-${IDLE_MIN} minutes" 2>/dev/null | head -1)"
  [ -n "$recent" ] && block "(d) written within the last ${IDLE_MIN}m — treating as active"
fi

if [ "$blockers" -gt 0 ]; then
  echo "SKIPPED: $WT ($blockers condition(s) failed) — nothing dropped, nothing removed"
  exit 1
fi

echo "  all four conditions hold — disposable"
[ "$CHECK" -eq 1 ] && exit 0

# --- disposal ---------------------------------------------------------------
# Databases FIRST. Removing the worktree first and failing on the drop leaves an
# orphan DB with nothing on disk left to name it; the sweep would then have to
# infer ownership. Drop while the owner is still standing.
if command -v mysql >/dev/null 2>&1; then
  # Rule 2 applies even to one worktree: snapshot, filter the snapshot, drop from
  # the snapshot. Never `DROP ... LIKE` evaluated live.
  snap="$SCRATCH/databases.txt"; drops="$SCRATCH/droplist.txt"
  if $MYSQL -N -B -e "SHOW DATABASES;" >"$snap" 2>/dev/null; then
    awk -v b="$TESTDB" '$0 == b || $0 ~ "^" b "_test_[0-9]+$"' "$snap" >"$drops"
    # Protected names can never appear in a droplist; assert it rather than trust
    # the filter, because this is the step that cannot be undone.
    protect="$PROJECT ${PROJECT}_test mysql information_schema performance_schema sys ${PF_DB_PROTECT:-}"
    while read -r db; do
      [ -n "$db" ] || continue
      case " $protect " in *" $db "*) echo "  REFUSING to drop protected database: $db"; exit 2 ;; esac
      # Only [a-z0-9_] reaches an interpolated DROP. A database named by someone
      # else on this server must not be able to steer this statement.
      case "$db" in *[!a-z0-9_]*) echo "  REFUSING unexpected database name: $db"; exit 2 ;; esac
      if $MYSQL -e "DROP DATABASE \`$db\`;" 2>/dev/null; then echo "  dropped $db"; else echo "  FAILED to drop $db"; fi
    done <"$drops"
    [ -s "$drops" ] || echo "  no databases matched $TESTDB"
  else
    echo "  NOTE: could not read SHOW DATABASES — drop $TESTDB and its workers by hand"
  fi
fi

git -C "$PRIMARY" worktree remove "$WT" || { echo "  FAILED: git worktree remove $WT"; exit 2; }
echo "  removed worktree $WT"

# -d, never -D. git refuses to delete an unmerged branch, which is the same
# conditional as (a) enforced by git itself — a branch kept here is a branch
# whose commits live on a remote and nowhere else locally.
if git -C "$PRIMARY" branch -d "$BRANCH" >/dev/null 2>&1; then
  echo "  deleted branch $BRANCH (merged into main)"
else
  echo "  kept branch $BRANCH (not merged — its commits live on a remote)"
fi

git -C "$PRIMARY" worktree prune
echo "DISPOSED: $WT"
