# ADR-005 — Story snapshots refresh after the response, go stale on fetch failure, and are replaced wholesale

Date: 2026-09-29 · Status: accepted; Decision 1 superseded by [ADR-006](ADR-006-refresh-runs-on-the-queue.md)

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
- As first shipped, `indexed_at` moved only on success, so a stale or unreachable project was
  re-dispatched on every page load and nothing deduped concurrent refreshes. Resolved the same day;
  see the amendment below.

## Amendment — 2026-09-29 (SB-2 fix, `fix(SB-2): stop retrying a failing project on every page load`)

- Staleness now keys on a new column, `projects.refresh_attempted_at`, stamped at the start of every
  attempt whatever its outcome. `Project::needsRefresh()` reads `refresh_attempted_at ?? indexed_at`,
  so a failing project is retried every `STALE_AFTER_MINUTES` rather than on every page load.
  `indexed_at` keeps its meaning: the last successful snapshot.
- `RefreshProject::handle()` holds `Cache::lock("board:refresh:{id}", 180)` for the whole refresh and
  releases it in `finally`. An overlapping refresh of the same project logs `board.refresh_skipped`
  and returns, so two snapshot replacements never run at once.
- Consequence: the dedupe depends on a cache store shared across processes (`database` today).

## Amendment — 2026-09-29 (SB-2 fix, `fix(SB-2): refresh lock outlives the worst-case git timeouts`)

- The 180 s lock TTL above equalled the sum of every git timeout in one refresh (30 + 60 + 30 + 60 s),
  so a refresh that hit all of them could lose its lock while still running and overlap the next. The
  TTL is now `RefreshProject::LOCK_SECONDS` (300 s), kept longer than that sum.

## Amendment — 2026-09-29 (SB-3, `feat(SB-3): the home page shows what needs me`)

- **Decision 1 is superseded.** The page-load refresh is no longer `dispatchAfterResponse()`: under
  `php artisan serve` it held a stale page open for the whole refresh (8–30 s+). `RefreshProjectJob`
  is now a queued, unique-per-project job → [ADR-006](ADR-006-refresh-runs-on-the-queue.md).
  Decisions 2 and 3 (stale-on-fetch-failure, wholesale replace) and the lock amendments stand.
