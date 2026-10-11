# ADR-058 — The legacy `/?project=` query is ignored, not redirected and not refused

Date: 2026-10-11 · Status: accepted · Story: RD-9

## Context

Since SB-7, `RedirectProjectFilter` turned `/?project=x` into a 301 to `/p/x`, the old project dashboard. RD-9
deleted that dashboard, so the redirect has no target. The home overview filters with `?projects=` (plural, RD-6),
which the middleware never touched.

## Decision

Delete the middleware and read nothing in its place. `/?project=x` renders the overview, 200 and unfiltered.

Alternatives rejected:
- **Redirect to `/p/x/stories`.** It keeps a middleware alive for bookmarks from before SB-7, and it picks one tab for
  the owner.
- **Translate it to `?projects=x`.** It adds a second spelling of the overview's filter, for URLs that only ever meant the old dashboard.
- **404.** It punishes an old bookmark for a parameter that does no harm.

## Consequences

- An old bookmark shows the whole board. `RetireOldPagesTest` pins this.
- `board.project_filter_redirected` is no longer logged.
