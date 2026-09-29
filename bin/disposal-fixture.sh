#!/usr/bin/env bash
# disposal-fixture.sh — build a throwaway repo whose worktrees cover every
# classification in disposal-standards.md §Rule 3, assert what the scripts decide
# about each one, and tear it down.
#
# This exists because the disposal scripts are the only tooling in the kit that
# DESTROYS things. A change to them is proven here before it ships — the same
# contract bin/preflight-ab.sh holds for the audit. Each case is named for the
# condition it exercises, so a failure line says which rule regressed.
#
# Usage: bin/disposal-fixture.sh [--keep]
#   --keep   leave the fixture and its databases on disk for inspection
#
# Needs: git. MySQL is optional — the database cases skip if it is unreachable.
set -uo pipefail

KEEP=0; [ "${1:-}" = "--keep" ] && KEEP=1
KIT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Fixed basename: the scripts derive the database prefix from it, so the names
# this fixture creates (and drops) are deterministic.
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/disposalfix.XXXXXX")"
FIX="$ROOT/disposalfix"; REMOTE="$ROOT/remote.git"
MYSQL="${PF_MYSQL:-mysql -u root}"
PROJECT=disposalfix
pass=0; fail=0

say() { printf '\n== %s\n' "$*"; }
ok()   { printf '  PASS  %s\n' "$*"; pass=$((pass + 1)); }
bad()  { printf '  FAIL  %s\n' "$*"; fail=$((fail + 1)); }

# assert_state <name> <DISPOSABLE|KEPT> [substring the reason must contain]
assert_state() {
  local name="$1" want="$2" reason="${3:-}" out rc got
  out="$(PF_IDLE_MINUTES=0 "$FIX/bin/dispose-worktree.sh" --check "$FIX/.claude/worktrees/$name" 2>&1)"; rc=$?
  [ "$rc" -eq 0 ] && got=DISPOSABLE || got=KEPT
  if [ "$got" != "$want" ]; then
    bad "$name: expected $want, got $got"; printf '%s\n' "$out" | sed 's/^/        /'; return
  fi
  if [ -n "$reason" ] && ! printf '%s' "$out" | grep -q -- "$reason"; then
    bad "$name: $got as expected, but reason did not mention '$reason'"; printf '%s\n' "$out" | sed 's/^/        /'; return
  fi
  ok "$name: $got${reason:+  ($reason)}"
}

mkdir -p "$FIX/bin" "$FIX/.claude/worktrees"
cp "$KIT/bin/dispose-worktree.sh" "$KIT/bin/workspace-sweep.sh" "$FIX/bin/"
cd "$FIX" || exit 2
git init -q -b main .
git config user.email fixture@example.test; git config user.name fixture; git config commit.gpgsign false
# A Laravel-shaped .gitignore: the allow-listed build output, AND the three
# preserved-screenshot directories measured on the live repo.
cat > .gitignore <<'G'
/vendor
/node_modules
/public/build
/.phpunit.cache
/.env
/storage/logs
shots/
shots-sell36/
storage/app/browser-evidence/
G
echo app > README.md; git add -A; git commit -qm init
git init -q --bare "$REMOTE"; git remote add origin "$REMOTE"; git push -q origin main

wt() { git worktree add -q -b "$2" ".claude/worktrees/$1" main; }
commit_in() { # commit_in <wt> <file> — one real commit so the branch diverges
  echo x > ".claude/worktrees/$1/$2"
  git -C ".claude/worktrees/$1" add -A
  git -C ".claude/worktrees/$1" commit -qm "feat(FX-1): $2"
}

# --- Rule 3a: merged, or on a remote, or neither -----------------------------
wt merged feat/MERGED; commit_in merged a.txt; git merge -q --no-ff -m merge feat/MERGED
wt pushed feat/PUSHED; commit_in pushed b.txt; git push -q origin feat/PUSHED
wt onlycopy feat/ONLYCOPY; commit_in onlycopy c.txt          # unmerged AND unpushed
# --- Rule 3b: untracked, and the three ignored shapes -------------------------
wt dirty feat/DIRTY; git push -q origin feat/DIRTY
echo measurement > .claude/worktrees/dirty/measurement.txt   # untracked
wt ignshots feat/IGNSHOTS; git push -q origin feat/IGNSHOTS
mkdir -p .claude/worktrees/ignshots/shots-sell36
echo PNG > .claude/worktrees/ignshots/shots-sell36/checkout.png   # ignored, non-empty, ONLY copy
wt ignbuild feat/IGNBUILD; git push -q origin feat/IGNBUILD
mkdir -p .claude/worktrees/ignbuild/node_modules/x .claude/worktrees/ignbuild/vendor/y .claude/worktrees/ignbuild/public/build
echo lib > .claude/worktrees/ignbuild/node_modules/x/index.js
echo pkg > .claude/worktrees/ignbuild/vendor/y/autoload.php
echo css > .claude/worktrees/ignbuild/public/build/app.css            # ignored, allow-listed
wt ignempty feat/IGNEMPTY; git push -q origin feat/IGNEMPTY
mkdir -p .claude/worktrees/ignempty/shots                              # ignored, EMPTY
wt runtime feat/RUNTIME; git push -q origin feat/RUNTIME
mkdir -p .claude/worktrees/runtime/storage/logs
printf 'APP_KEY=base64:x\n' > .claude/worktrees/runtime/.env           # copied by the provisioner
echo log > .claude/worktrees/runtime/storage/logs/laravel.log
# --- Rule 3c / stale registration --------------------------------------------
git worktree add -q --detach .claude/worktrees/detached main
wt gone feat/GONE; rm -rf .claude/worktrees/gone                       # stale registration

say "Rule 3a — is the branch's work anywhere else?"
assert_state merged     DISPOSABLE
assert_state pushed     DISPOSABLE
assert_state onlycopy   KEPT "(a)"

say "Rule 3b — uncommitted, untracked, or preserved ignored files"
assert_state dirty      KEPT "measurement.txt"
# THE REGRESSION CASE: clean by `git status --porcelain`, and `git worktree
# remove` would not have refused it either. The only copy of that screenshot.
assert_state ignshots   KEPT "shots-sell36"
assert_state ignbuild   DISPOSABLE          # ignored but allow-listed build output
assert_state ignempty   DISPOSABLE          # ignored, empty — evidence of nothing
# As specified: .env and storage/logs are ignored, non-empty, and not on the
# allow-list, so a provisioned Laravel worktree is KEPT. Asserted so the
# consequence is visible rather than discovered on contact.
assert_state runtime    KEPT ".env"

say "Rule 3c — main or a detached HEAD in a worktree"
assert_state detached   KEPT "(c)"

# --- databases ---------------------------------------------------------------
if command -v mysql >/dev/null 2>&1 && $MYSQL -N -B -e "SELECT 1;" >/dev/null 2>&1; then
  for db in ${PROJECT}_test ${PROJECT}_test_7 ${PROJECT}_test_merged ${PROJECT}_test_merged_test_1 \
            ${PROJECT}_test_onlycopy ${PROJECT}_test_ghost_old ${PROJECT}_test_scratch_adhoc; do
    $MYSQL -e "CREATE DATABASE IF NOT EXISTS \`$db\`;" 2>/dev/null
  done
  say "Rule 2 — the sweep's droplist: prefix allow-list, base DB and live owners protected"
  rep="$(PF_IDLE_MINUTES=0 "$FIX/bin/workspace-sweep.sh" --report 2>&1)"
  for orphan in ${PROJECT}_test_ghost_old ${PROJECT}_test_scratch_adhoc; do
    printf '%s' "$rep" | grep -q "^  $orphan$" && ok "orphan listed: $orphan" || bad "orphan NOT listed: $orphan"
  done
  for keep in ${PROJECT}_test ${PROJECT}_test_7 ${PROJECT}_test_merged ${PROJECT}_test_merged_test_1 ${PROJECT}_test_onlycopy; do
    printf '%s' "$rep" | grep -q "^  $keep$" && bad "protected DB appeared in droplist: $keep" || ok "protected: $keep"
  done
  printf '%s' "$rep" | grep -q '1 stale registration' && ok "stale registration reported" || bad "stale registration not reported"
else
  echo "  SKIP  database cases (no reachable mysql)"
fi

printf '\n== %d passed, %d failed\n' "$pass" "$fail"
if [ "$KEEP" -eq 1 ]; then
  echo "fixture kept at $FIX (drop ${PROJECT}_* databases yourself)"
else
  cd /
  command -v mysql >/dev/null 2>&1 && $MYSQL -N -B -e "SHOW DATABASES;" 2>/dev/null | grep "^${PROJECT}" |
    while read -r db; do $MYSQL -e "DROP DATABASE \`$db\`;" 2>/dev/null; done
  rm -rf "$ROOT"
fi
[ "$fail" -eq 0 ]
