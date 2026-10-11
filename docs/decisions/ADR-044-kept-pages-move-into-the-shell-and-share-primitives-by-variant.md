# ADR-044 — Kept pages move into the shell; primitives shared with old pages get `shell:` variants

Date: 2026-10-11 · Status: accepted · Story: RD-4

## Context

RD-4 moves the pages the owner kept (Stories, Handbook, Mockup gallery, Mockup viewer, Manage projects, App map)
into `layouts/shell`. Three pages are not kept as they are and stay on `layouts/board`: `Home` (`/`, replaced in
RD-5), `ProjectPage` (the old `/p/{project}` dashboard) and `ProductionDashboard` (`/prod`), both deleted or
replaced by RD-9. Several Blade primitives are rendered by both groups: `status-chip`, `status-bar` and
`project-tabs` (the old dashboard and Home use them too). The story requires restyling primitives in place, not
forking them (design-standards §Reuse before build).

## Decision

- Each kept page switches its `#[Layout]` attribute to `layouts.shell`. No logic, action or route changes.
- A primitive still rendered by an old page keeps its light/`dark:` classes and gains `shell:` classes beside
  them (the variant from ADR-040), so one file serves both shells until RD-9.
- A primitive that only shell pages render now (`project-header`, `prose`, `kit-badge`, `handbook-empty`,
  `mockup-state`, `mockup-current`, `confirm-modal`, `switch`, `map-screen`, `metric-result`) drops its light
  and `dark:` classes and carries the shell tokens directly. It has no light consumer left to serve.
- Owner (2026-10-11): the shell sidebar gains no Production item, filter, counts or live badge in this story,
  because none of the kept pages needs them (Production is backlog F-7).

Alternatives rejected:
- **Fork the shared primitives** (`shell/status-chip` beside `board/status-chip`). Two copies drift, and the
  old copy is deleted in RD-9 anyway.
- **Move the three old pages too.** They are being redesigned or deleted; re-skinning them is throwaway work.
- **Keep `dark:` pairs on shell-only primitives.** Dead classes with no light page to serve them.

## Consequences

- Until RD-9, a change to `status-chip`, `status-bar` or `project-tabs` must be checked on both shells.
- RD-9 can strip the `dark:`/light classes from those three once the old pages go.
- Tests that relied on the old sidebar's hooks (`data-sidebar-project`, the live badge) moved to the shell's
  (`data-nav-project`) or to a page still on `layouts/board`.
