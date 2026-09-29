#!/usr/bin/env bash
# Provision an isolated build worktree for one agent. THE canonical script —
# never let a provision step rewrite phpunit.xml (see the NOTE below).
#
# Usage: bin/provision-worktree.sh <name> <branch> [base]
#   name    worktree directory name under .claude/worktrees/ (e.g. ap-5)
#   branch  branch to create (e.g. feat/AP-5-the-thing-it-does)
#   base    ref to cut from (default: origin/main — never local main)
set -euo pipefail

NAME="${1:?usage: provision-worktree.sh <name> <branch> [base]}"
BRANCH="${2:?usage: provision-worktree.sh <name> <branch> [base]}"
BASE="${3:-origin/main}"

# The primary checkout is wherever this script lives, so the kit ports between
# projects without editing a path.
PRIMARY="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT="$(basename "$PRIMARY" | tr '[:upper:]-' '[:lower:]_' | tr -cd 'a-z0-9_')"
WT="$PRIMARY/.claude/worktrees/$NAME"
# Per-worktree test DB name; MySQL identifiers keep [a-z0-9_].
TESTDB="${PROJECT}_test_$(echo "$NAME" | tr '[:upper:]-' '[:lower:]_' | tr -cd 'a-z0-9_')"

cd "$PRIMARY"
git fetch origin --quiet

# Never adopt a tree another session may be using (guard on the worktree
# registry, not the directory — a stale dir with no registration is also fatal).
if git worktree list --porcelain | grep -qx "worktree $WT"; then
  echo "REFUSING: $WT is already registered (branch $(git -C "$WT" rev-parse --abbrev-ref HEAD))."; exit 1
fi
[ -e "$WT" ] && { echo "REFUSING: $WT exists on disk but is not a registered worktree."; exit 1; }

git worktree add -b "$BRANCH" "$WT" "$BASE"
cp "$PRIMARY/.env" "$WT/.env"

cd "$WT"
composer install --no-interaction   # never symlink vendor
npm ci
npm run build
php artisan storage:link || true    # missing public/storage surfaces later as bogus browser reds

# NOTE: DO NOT rewrite phpunit.xml's DB_DATABASE here, and DB_DATABASE must not
# carry force="true". preflight.sh runs each gate as `env DB_DATABASE="$PF_TEST_DB" ...`
# and derives the per-worktree database itself. force="true" makes PHPUnit's value win
# over that env var, which silently sends every worktree's suite to ONE database and
# defeats preflight's concurrency lock (keyed on PF_TEST_DB, so it would guard a name
# nothing uses). The database below is still created, because preflight names it
# per-worktree.

mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$TESTDB\`;" 2>/dev/null \
  || echo "NOTE: create $TESTDB by hand if the suite complains"

grep -n 'DB_CONNECTION\|DB_DATABASE' phpunit.xml
git log --oneline -2
echo "PROVISION OK: $WT on $BRANCH (base $BASE, test DB $TESTDB)"
