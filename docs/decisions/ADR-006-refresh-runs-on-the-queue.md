# ADR-006 — Snapshot refresh runs on the queue, one job per project, carrying the request ID

Date: 2026-09-29 · Status: accepted · Supersedes: ADR-005 §Decision 1

## Context

ADR-005 queued page-load refreshes with `RefreshProjectJob::dispatchAfterResponse()`. The reasoning
was that the owner might not be running a queue worker, so a queued job might never run. When SB-3
built the real home page, that choice turned out to be wrong for the dev server. `php artisan serve`
runs on PHP's built-in server, which cannot flush the response before after-response work starts. A
stale page load therefore stayed open for the entire refresh. Measured on 2026-09-29: a stale load
took 8.3 s against 0.09 s for a fresh one, and loads reached 30 s or more once coins' off-main scan
(SB-5) was included. That was long enough to time out a Playwright check. The "no worker" worry also
turned out to be groundless: `composer run dev` (`php artisan dev`) already starts
`queue:listen --tries=1`.

SB-3 also added the Refresh button and asked for request IDs (logging-standards §Correlation ID)
that carry into the refresh.

## Decision

1. **`RefreshProjectJob` implements `ShouldQueue`** and is sent with `dispatch()` from
   `app/Livewire/Board/Home.php` (`mount()` for stale projects, `refresh()` for the button). After
   the change, a stale page load measured 0.37 s.
2. **`ShouldBeUnique` per project** (`uniqueId()` = project id). `$uniqueFor = 300` matches
   `RefreshProject::LOCK_SECONDS`, so a burst of page loads or button presses queues one refresh per
   project, not one per request. The `Cache::lock` inside `RefreshProject` stays as the guard against
   two refreshes running at the same moment.
3. **The job carries the request ID.** `app/Http/Middleware/AssignRequestId.php` (prepended globally)
   puts `request_id` in Laravel `Context`. The job copies it into `$requestId` when constructed and
   restores it in `handle()`. As a result, the refresh's log lines on the worker carry the ID of the
   request that asked for them.

Alternatives rejected:
- Keeping `dispatchAfterResponse()` and moving to a server that can flush the response (php-fpm,
  Octane). That changes the dev stack, and a worker is already running.
- A synchronous refresh: seconds per project, which is worse than the bug.
- A queued job without uniqueness: every page load while a project is stale would add another job.

## Consequences

- **No worker, no refresh.** Under a bare `artisan serve`, jobs pile up in `jobs` and snapshots never
  change. The page still renders.
- `board.refresh_crashed` is still logged in `handle()`, not `failed()`, so that it carries the
  restored `request_id`.
- Tests assert `Bus::assertDispatched`, not `assertDispatchedAfterResponse`.
- `PHP_CLI_SERVER_WORKERS=4` is set in `.env.example`. The problem that prompted it is related: a
  single-worker built-in server lets one slow request (a mockup iframe) block the page's own Livewire
  calls.
