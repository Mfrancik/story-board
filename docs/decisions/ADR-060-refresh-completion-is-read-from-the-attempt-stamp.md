# ADR-060 — "Refreshing…" ends when the project's refresh-attempt stamp moves, not by the clock

Date: 2026-10-12 · Status: accepted · Story: RD-11

## Context

Refresh this project (F-16) queues `RefreshProjectJob` and must show "Refreshing…" until that refresh is done. The
page cannot hear the job finish; it can only re-read the `projects` row. The obvious test — "`indexed_at` is after
the press" — fails both ways: MySQL stores whole seconds, and a sync queue (tests, or a fast worker) finishes in
the same second as the press, so the comparison either reports done before anything ran or never reports done
and waits out the timeout.

## Decision

At the press, `ProjectOverview::refresh()` remembers the project's current `refresh_attempted_at` (read *before*
dispatch, since a sync queue finishes inside `dispatch()`), in a `#[Locked]` property. The refresh has **started**
once the stored stamp differs from (is later than) that value, and has **finished** when `indexed_at >= attempt`
or the project is `stale` / `unreachable`. After `REFRESH_WAIT_SECONDS` (300 s, the job's uniqueness window) it
gives up. The page renders `wire:poll.5s` only while waiting.

Alternatives rejected:
- **Compare `indexed_at` with the press time.** Second-granularity makes it wrong under a fast queue, as above.
- **Poll always.** A request every 5 s on every overview tab, for a state that is idle almost all the time.
- **Broadcast completion.** Needs a websocket server the board does not run.

## Consequences

- Depends on `RefreshProject` stamping `refresh_attempted_at` first and `indexed_at` last, and setting a failed
  state on failure. Changing that order breaks the button.
- A queued job that never runs (no worker) shows "Refreshing…" for 5 minutes, then the button returns.
- The locked properties mean the browser cannot fake a refresh in progress.
