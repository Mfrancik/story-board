#!/usr/bin/env bash
# Report — and, with --apply, reclaim — the build artifacts that concurrent
# agent work leaves behind: worktrees, their derived test databases, stale
# worktree registrations, and log growth.
#
# Usage: bin/workspace-sweep.sh [--report|--quiet|--apply]
#   --report  (default) full evaluation: every worktree against Rule 3 in full,
#             real `du` on the ones that pass. Changes nothing.
#   --quiet   one-screen summary using only cheap signals — this is what the
#             preflight gate runs. Exits 1 when a threshold trips.
#   --apply   dispose of every worktree that passes Rule 3 in full, drop orphan
#             databases from a frozen snapshot, prune stale registrations.
#
# The rules it enforces live in docs/standards/disposal-standards.md.
set -uo pipefail

MODE="${1:---report}"
PRIMARY="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Take git's spelling of the primary checkout — see the same note in
# dispose-worktree.sh. Comparing a $0-derived path against `git worktree list`
# on a case-insensitive filesystem silently fails to match, and the primary
# checkout then appears in its own candidate list.
GITMAIN="$(git -C "$PRIMARY" worktree list --porcelain 2>/dev/null | head -1 | sed 's/^worktree //')"
[ -n "$GITMAIN" ] && PRIMARY="$GITMAIN"
PROJECT="$(basename "$PRIMARY" | tr '[:upper:]-' '[:lower:]_' | tr -cd 'a-z0-9_')"
WT_ROOT="$PRIMARY/.claude/worktrees"
MYSQL="${PF_MYSQL:-mysql -u root}"

# Thresholds. Chosen in disposal-standards.md §Rule 5 — change them there too.
DB_WARN="${PF_DB_WARN:-25}"
WT_WARN="${PF_WT_WARN:-5}"
LOG_WARN_MB="${PF_LOG_WARN_MB:-512}"
# Order-of-magnitude only, for the --quiet line. --report measures with `du`.
WT_AVG_MB="${PF_WT_AVG_MB:-450}"

SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/sweep.XXXXXX") || { echo "mktemp -d failed" >&2; exit 2; }
trap 'rm -rf "$SCRATCH"' EXIT
cd "$PRIMARY" || exit 2

# --- worktrees: registrations vs disk ---------------------------------------
git worktree list --porcelain 2>/dev/null |
  awk '/^worktree /{p=substr($0,10); b="-"} /^branch /{b=substr($0,8); sub("refs/heads/","",b)} /^$/{if(p!="")print p"\t"b; p=""}
       END{if(p!="")print p"\t"b}' >"$SCRATCH/registered.txt"
# The primary checkout is itself a registration; it is never a disposal candidate.
grep -v "^$PRIMARY	" "$SCRATCH/registered.txt" >"$SCRATCH/trees.txt" || true

REG=$(wc -l <"$SCRATCH/trees.txt" | tr -d ' ')
DISK=0; [ -d "$WT_ROOT" ] && DISK=$(find "$WT_ROOT" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l | tr -d ' ')
# A registration whose directory is gone: `git worktree prune` fodder, and the
# reason `git worktree list` and `ls` disagree.
STALE=0
while IFS=$'\t' read -r p _; do [ -n "$p" ] && [ ! -d "$p" ] && STALE=$((STALE + 1)); done <"$SCRATCH/trees.txt"

# --- cheap branch safety (Rule 3a, in bulk) ---------------------------------
# Per-branch `git branch -r --contains` is one process per worktree; at the
# scale this script exists for (hundreds) that alone is the runtime. Two bulk
# calls answer it instead: everything merged into main, and every commit that
# exists on NO remote. A tip absent from the second set is on a remote.
git branch --merged main --format='%(refname:short)' 2>/dev/null >"$SCRATCH/merged.txt" || : >"$SCRATCH/merged.txt"
git rev-list --all --not --remotes 2>/dev/null >"$SCRATCH/unpushed.txt" || : >"$SCRATCH/unpushed.txt"

: >"$SCRATCH/candidates.txt"; : >"$SCRATCH/protected.txt"
while IFS=$'\t' read -r p b; do
  [ -n "$p" ] && [ -d "$p" ] || continue
  if [ "$b" = "main" ] || [ "$b" = "-" ]; then echo "$p	$b	holds main or detached" >>"$SCRATCH/protected.txt"; continue; fi
  tip="$(git rev-parse --verify --quiet "$b" 2>/dev/null)"
  if grep -qFx "$b" "$SCRATCH/merged.txt" 2>/dev/null; then echo "$p	$b" >>"$SCRATCH/candidates.txt"
  elif [ -n "$tip" ] && ! grep -qFx "$tip" "$SCRATCH/unpushed.txt" 2>/dev/null; then echo "$p	$b" >>"$SCRATCH/candidates.txt"
  else echo "$p	$b	unmerged AND unpushed — only copy" >>"$SCRATCH/protected.txt"
  fi
done <"$SCRATCH/trees.txt"
CAND=$(wc -l <"$SCRATCH/candidates.txt" | tr -d ' ')
PROT=$(wc -l <"$SCRATCH/protected.txt" | tr -d ' ')

# --- databases: FROZEN SNAPSHOT, never a live pattern ------------------------
# This is the rule most easily got wrong. `DROP` driven by a live
# `SHOW DATABASES LIKE 'prefix%'` re-evaluated at drop time deletes databases a
# concurrent session created AFTER the sweep started — observed once, four
# minutes in. Capture once to a file, filter the file, drop from the file.
DBS=0; ORPHAN=0
: >"$SCRATCH/orphans.txt"
if command -v mysql >/dev/null 2>&1 && $MYSQL -N -B -e "SHOW DATABASES;" >"$SCRATCH/databases.txt" 2>/dev/null; then
  # Allow-list: only this project's per-worktree prefix is ever a candidate.
  # This server also hosts unrelated projects and a production dump; a pattern
  # loose enough to be convenient is loose enough to reach them.
  grep -E "^${PROJECT}_test_" "$SCRATCH/databases.txt" >"$SCRATCH/candidates_db.txt" || : >"$SCRATCH/candidates_db.txt"
  DBS=$(wc -l <"$SCRATCH/candidates_db.txt" | tr -d ' ')
  # Live set: the DB of every registered worktree, plus its _test_<n> parallel
  # workers, plus the primary checkout's own workers (${PROJECT}_test_<n>).
  : >"$SCRATCH/live_db.txt"
  while IFS=$'\t' read -r p _; do
    [ -n "$p" ] || continue
    echo "${PROJECT}_test_$(basename "$p" | tr '[:upper:]-' '[:lower:]_' | tr -cd 'a-z0-9_')" >>"$SCRATCH/live_db.txt"
  done <"$SCRATCH/registered.txt"
  awk 'NR==FNR{live[$0]=1; next}
       { keep=0
         if ($0 in live) keep=1
         if ($0 ~ /_test_[0-9]+$/) { base=$0; sub(/_test_[0-9]+$/,"",base); if (base in live || base == PRJ "_test" || base == PRJ) keep=1 }
         if (!keep) print }' PRJ="$PROJECT" "$SCRATCH/live_db.txt" "$SCRATCH/candidates_db.txt" >"$SCRATCH/orphans.txt"
  ORPHAN=$(wc -l <"$SCRATCH/orphans.txt" | tr -d ' ')
  DB_OK=1
else
  DB_OK=0
fi

# --- logs --------------------------------------------------------------------
LOG_MB=0; BIGLOG=""
if [ -d "$PRIMARY/storage/logs" ]; then
  LOG_MB=$(( $(du -sk "$PRIMARY/storage/logs" 2>/dev/null | awk '{print $1}') / 1024 ))
  BIGLOG=$(find "$PRIMARY/storage/logs" -type f -size +100000k 2>/dev/null | head -3)
fi

trip=0
[ "$ORPHAN" -ge "$DB_WARN" ] && trip=1
[ "$CAND"   -ge "$WT_WARN" ] && trip=1
[ "$LOG_MB" -ge "$LOG_WARN_MB" ] && trip=1
[ "$STALE"  -gt 0 ] && trip=1

if [ "$MODE" = "--quiet" ]; then
  # Warn-only by design: this number must never be the reason a build stops.
  if [ "$trip" -eq 1 ]; then
    est=$((CAND * WT_AVG_MB)); est_h="${est} MB"; [ "$est" -ge 1024 ] && est_h="$((est / 1024)) GB"
    echo "worktrees ${REG} registered / ${DISK} on disk (${STALE} stale) · ${CAND} branch-safe candidates (~${est_h} est.) · ${PROT} protected (unmerged+unpushed)"
    [ "$DB_OK" -eq 1 ] && echo "test databases ${DBS} · ${ORPHAN} orphaned (no worktree owns them)"
    echo "logs ${LOG_MB} MB"
    echo "thresholds: orphan DBs>=${DB_WARN} · candidates>=${WT_WARN} · logs>=${LOG_WARN_MB}MB · any stale registration"
    echo "reclaim with: bin/workspace-sweep.sh --report   (then --apply)"
    exit 1
  fi
  exit 0
fi

echo "== WORKSPACE SWEEP ($PROJECT) =="
echo "worktrees:      $REG registered, $DISK on disk, $STALE stale registration(s)"
echo "branch-safe:    $CAND candidate(s) · $PROT protected (unmerged AND unpushed — the only copy)"
if [ "$DB_OK" -eq 1 ]; then echo "databases:      $DBS matching ${PROJECT}_test_*, $ORPHAN orphaned"
else echo "databases:      not readable (no mysql client, or \$PF_MYSQL cannot connect) — skipped"; fi
echo "logs:           ${LOG_MB} MB in storage/logs"
[ -n "$BIGLOG" ] && { echo "  single files over 100 MB:"; printf '%s\n' "$BIGLOG" | sed 's/^/    /'; }
echo

if [ "$PROT" -gt 0 ]; then
  echo "-- PROTECTED, never touched by --apply --"
  sed 's/^/  /' "$SCRATCH/protected.txt"
  echo
fi

# --report and --apply both run the FULL Rule 3 conjunction per candidate. The
# bulk check above only narrows the list; it is not the decision.
[ "$CAND" -gt 0 ] && echo "-- candidates, each evaluated against Rule 3 in full --"
disposable=0; reclaim_kb=0
while IFS=$'\t' read -r p b; do
  [ -n "$p" ] || continue
  if [ "$MODE" = "--apply" ]; then
    "$PRIMARY/bin/dispose-worktree.sh" "$p" && disposable=$((disposable + 1))
  else
    if "$PRIMARY/bin/dispose-worktree.sh" --check "$p" >"$SCRATCH/chk.out" 2>&1; then
      kb=$(du -sk "$p" 2>/dev/null | awk '{print $1}'); reclaim_kb=$((reclaim_kb + ${kb:-0}))
      disposable=$((disposable + 1))
      echo "  DISPOSABLE  $p  ($b, $(( ${kb:-0} / 1024 )) MB)"
    else
      echo "  KEPT        $p  ($b)"
      grep -E 'BLOCKED' "$SCRATCH/chk.out" | sed 's/^ */    /'
    fi
  fi
done <"$SCRATCH/candidates.txt"
echo

if [ "$MODE" = "--apply" ]; then
  echo "disposed $disposable worktree(s)"
  if [ "$DB_OK" -eq 1 ] && [ -s "$SCRATCH/orphans.txt" ]; then
    # The droplist is kept and its path printed: what was dropped is a record,
    # not a claim. Re-read the snapshot; it is the one frozen before any drop.
    drops="${TMPDIR:-/tmp}/${PROJECT}-droplist-$(date +%Y%m%d-%H%M%S).txt"
    cp "$SCRATCH/orphans.txt" "$drops"
    protect="$PROJECT ${PROJECT}_test mysql information_schema performance_schema sys ${PF_DB_PROTECT:-}"
    n=0
    while read -r db; do
      [ -n "$db" ] || continue
      case " $protect " in *" $db "*) echo "REFUSING protected database: $db"; exit 2 ;; esac
      case "$db" in *[!a-z0-9_]*) echo "REFUSING unexpected database name: $db"; exit 2 ;; esac
      $MYSQL -e "DROP DATABASE \`$db\`;" 2>/dev/null && n=$((n + 1))
    done <"$drops"
    echo "dropped $n orphaned database(s); droplist kept at $drops"
  fi
  git worktree prune && echo "pruned stale worktree registrations"
  # Branches outlive their worktrees — a tree deleted by hand leaves its branch
  # behind, and they accumulate faster than worktrees do (765 against 586,
  # measured). `-d` is the whole safety argument: git refuses to delete a branch
  # that is not merged into its upstream or HEAD, and refuses one checked out in
  # any worktree. An unmerged branch therefore CANNOT be lost here.
  b_n=0
  while read -r b; do
    [ -n "$b" ] && [ "$b" != "main" ] || continue
    git branch -d "$b" >/dev/null 2>&1 && b_n=$((b_n + 1))
  done < <(git branch --merged main --format='%(refname:short)')
  echo "deleted $b_n merged branch(es) (unmerged branches are refused by git and kept)"
else
  echo "$disposable disposable worktree(s), $(( reclaim_kb / 1024 )) MB measured"
  [ "$ORPHAN" -gt 0 ] && echo "$ORPHAN orphaned database(s) would be dropped (first 10):" && head -10 "$SCRATCH/orphans.txt" | sed 's/^/  /'
  [ "$STALE" -gt 0 ] && echo "$STALE stale registration(s) would be pruned"
  echo "run again with --apply to reclaim"
fi
exit 0
