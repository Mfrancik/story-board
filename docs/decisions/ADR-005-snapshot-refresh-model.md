# ADR-005 — Story snapshots refresh after the response, go stale on fetch failure, and are replaced wholesale

Date: 2026-09-29 · Status: accepted

## Context

SB-2 refreshes a project's snapshot on page load when it is older than 5 minutes. A `git fetch` takes
about 2 s per project (4 projects in 8.7 s measured on 2026-09-29) and can hang up to its timeout when
offline. `QUEUE_CONNECTION=database` needs a worker, and the owner may not be running one. coins alone
has ~916 story rows.

## Decision

1. **Page-load refresh uses `RefreshProjectJob::dispatchAfterResponse()`**
   (`app/Http/Controllers/BoardController.php`). The job runs in the web process after the response
   is sent. Rejected: a queued job, because with no worker running the refresh would silently never
   happen. Also rejected: a synchronous refresh, because a fetch per project would block the page for
   seconds each.
2. **A failed fetch keeps the last snapshot and marks the project `stale`**
   (`app/Actions/Board/RefreshProject.php`). Rejected: re-indexing the local `origin/main` anyway,
   because that ref is old and indexing it would stamp an old state with a fresh `indexed_at`.
3. **The snapshot is replaced wholesale.** A single transaction deletes the project's rows, bulk-inserts
   the new rows in 500-row chunks, and updates the project row. Rejected: a per-row upsert, which is
   slower at coins' size and would need a diff/delete pass for removed stories. The single transaction
   means a reader never sees a half-written snapshot.

## Consequences

- `RefreshProjectJob::failed()` never runs on the page-load path, so `handle()` itself logs
  `board.refresh_crashed` before rethrowing.
- Story rows have no stable identity across refreshes. Anything that needs one must key on
  `(project_id, path)` or `story_id`, not the row `id`.
- `indexed_at` moves only on success, so a stale or unreachable project is re-dispatched on every page
  load, and nothing dedupes concurrent refreshes of the same project. Acceptable for a single-owner
  localhost board. Revisit if SB-6 hosts it.
