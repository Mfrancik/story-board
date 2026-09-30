# ADR-031 — The daily production snapshot reads in-process, and the scheduler is documented, not bundled

Date: 2026-09-29 · Status: accepted · Story: SB-18

## Context

`prod_snapshots` gets a row whenever `/prod` reads a project. A day nobody opens the page would have no
point, so SB-18 adds `board:prod-snapshot`, scheduled daily at 23:55 in `board.timezone`. Two questions:
how the command reads, and how the scheduler gets run in development.

## Decision

1. **The command reads each project itself, one after another, in its own process**, through the same
   `ReadProductionProject` action the queued job uses. It does not dispatch `ReadProductionMetrics`.
   The schedule then works with no queue worker and no open page. A failing project is logged and never
   stops the rest; the command always exits 0. Switched-off projects are not read and are counted as
   `skipped` in `board.prod_snapshot_run`.
2. **`composer run dev` is not changed to start the scheduler** (owner decision 2026-09-29). It runs
   server, queue, pail and Vite. The feature doc and RUNBOOK say that `php artisan schedule:work` must also
   run for the nightly snapshot.

## Alternatives rejected

- **Dispatch the job per project.** Parallel, but a missing worker would silently lose the night's point,
  and the whole point of the command is to cover the case where nothing else is running.
- **Add `schedule:work` to `composer run dev`.** Owner chose doc-only for now. Revisit if nightly gaps
  show up in practice.

## Consequences

- The command runs serially: worst case about 5 s connect plus 5 s per metric per project. Fine at a
  handful of projects; `withoutOverlapping()` guards a slow night.
- Without the scheduler, a day with no page visit is a gap in the trend and "—" in the changes that
  compare against it.
