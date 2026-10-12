# ADR-061 — Project overview tiles reuse the home overview's readers and stored data

Date: 2026-10-12 · Status: accepted · Story: RD-11

## Context

The project overview shows, for one project, things the board already shows elsewhere: what needs the owner and
live sessions (home overview), status counts (Stories page), production numbers (home project tiles). Each could
get its own per-project query, or reuse the existing reader.

## Decision

- **Needs you / Live now** call `ListNeedsMe::handle()` and `ListLiveSessions::overview()` with
  `ProjectFilter::only([<project>])` — the home overview's own filter value (ADR-051). No new filtering Action.
- **Story progress** counts with `ReadProjectStories::kind()`, so the tile and the Stories page always agree.
  Built % excludes cancelled stories (cancelled work is not owed).
- **Production** reads stored `ProdSnapshot` rows only (`ReadProjectProduction`), over the home tiles' 7-day window
  (`ListProjectTiles::PROD_DAYS`). Loading the page never opens a production connection; `ProductionReader` stays
  the only code that does (ADR-029).

Alternatives rejected:
- **Per-project queries for needs and sessions.** A second copy of the rules (a pick outranks draft/ready,
  ADR-047) that would drift from the home page.
- **A live production read on page load.** Slow, can fail on a page meant for a glance, and a second path to a
  production database.

## Consequences

- A rule change in `ListNeedsMe`, `ListLiveSessions` or `kind()` shows on both pages at once.
- Production figures are as fresh as the last nightly snapshot.
