#!/usr/bin/env bash
# Run a command under the ONE machine-wide browser-test lock (L-7).
#
# Usage: bin/browser-lock.sh <command...>   e.g. bin/browser-lock.sh php artisan test tests/Browser
#
# Browser tests from any session, worktree or project on this machine must run
# one at a time: Playwright's cookies are scoped by host, not port, so two runs
# on 127.0.0.1 log into each other's sessions however isolated their databases
# are. A per-session lock path (a scratchpad file) excludes nothing, so the path
# is fixed under ~/.claude, which every session on the machine shares.
#
# Exports BROWSER_LOCK_HELD=1 to the command. tests/Pest.php refuses to run a
# Browser test without it, and a nested call (preflight.sh's own browser gate
# inside an outer wrap) runs straight through instead of waiting on itself.
set -euo pipefail

[ $# -gt 0 ] || { echo "usage: browser-lock.sh <command...>" >&2; exit 2; }

if [ "${BROWSER_LOCK_HELD:-}" = 1 ]; then
  exec "$@"
fi

LOCK="${BROWSER_LOCK_FILE:-$HOME/.claude/browser.lock}"
mkdir -p "$(dirname "$LOCK")"
export BROWSER_LOCK_HELD=1
# -k keeps the lock file between runs, so waiters never race on its creation.
exec /usr/bin/lockf -k "$LOCK" "$@"
