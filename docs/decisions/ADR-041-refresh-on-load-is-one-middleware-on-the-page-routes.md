# ADR-041 — Refresh on load is one middleware on the board's page routes

Date: 2026-10-11 · Status: accepted · Story: RD-2

## Context

Before this change, the refresh of stale snapshots on page load lived in `Home::mount()`, which refreshed
every stale project, and in `ProjectPage::mount()`, which refreshed only its own. Both pages are scrapped in
RD-9, and no other page triggered a refresh. RD-2 requires that loading any board page queue one
`RefreshProjectJob` for each stale enabled project.

## Decision

Add `app/Http/Middleware/RefreshStaleProjects.php`. It dispatches one job for each enabled project where
`needsRefresh()` is true and logs `board.stale_refresh_dispatched` for each. It applies to a route group in
`routes/web.php` that wraps every Livewire page route. Remove the dispatches from both `mount()` methods, so
one load cannot queue the same refresh twice.

The file routes (`shots.file`, `mockups.file`, `mockups.frame`) stay outside the group, because one page's
frames and images hit them many times per load. Livewire update requests do not run route middleware.

Alternatives rejected:
- **Keep the per-page dispatch and add it to each new page.** Every RD page would have to remember it, and
  the coverage would die with the pages RD-9 deletes.
- **Keep the `mount()` dispatches alongside the middleware and let `ShouldBeUnique` drop the duplicate.** That
  would make it double-dispatch by design. Because `RefreshProjectJob` is `ShouldBeUnique`, the duplicate
  would be dropped silently, even under `Queue::fake`, so tests could not catch it if uniqueness ever
  changed.
- **Global web middleware.** This would also run on every mockup frame and shot request.

## Consequences

- Behaviour change: `/p/{project}` now refreshes every stale enabled project, not only its own.
  `ProjectPageTest` was updated, with a comment that cites RD-2.
- A new page route gets refresh on load only if it is declared inside the group.
- The refresh trigger is tested at the middleware (`tests/Feature/Board/DarkShellTest.php`), independent of
  any page.
