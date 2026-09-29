# ADR-018 — Portfolio figures ignore the filters, add no query, and report the stalest snapshot

Date: 2026-09-29 · Status: accepted

## Context

SB-9 adds In flight (approved and unbuilt, versions not on main, projects not `ok`), a not-on-main
count per project tile, and a header "Refreshed N ago". The page also keeps the initiative filter and
search. SB-3 had promised a query count independent of the number of projects, but no test pinned it.

## Decision

- **Tiles and In flight describe the whole portfolio.** Only the project (`$pinned` on
  `/p/{project}`) narrows them. The initiative filter and search narrow the cards and sections only.
- **No extra queries.** The not-on-main count joins SB-3's first grouped query in
  `ListWhatNeedsMe::projects()`, as `sum(location_kind is null)` and `sum(location_kind is not null)`
  per project and status. `inFlight()` sums the project summaries in PHP. A query-count test in
  `tests/Feature/Board/AllProjectsDashboardTest.php` adds projects and asserts the count is unchanged.
- **The header shows the oldest `indexed_at`** of the shown projects (`Home::render()`, `refreshedAt`).

Alternatives rejected:
- **Filter the figures too.** "Projects not ok" is a property of a project, not of an initiative, so
  it cannot be filtered that way. Filtering the others would need a second set of grouped queries
  per filter combination, for figures whose job is to show the portfolio's shape.
- **A separate query for not-on-main or In flight.** Each would be cheap, but it is still a query the
  page does not need. The query-count test is what keeps the next figure honest.
- **The newest `indexed_at` in the header.** One fresh project would hide a stale one. The page is
  only as fresh as its stalest snapshot.

## Consequences

- With a filter set, the cards shrink and the tiles and figures do not. That is expected.
- A status seen only off main has an on-ref count of 0 in the shared grouped query. It is filtered
  out of `counts`, so it draws no segment and no chip.
- One stuck project makes the header read old; its tile says which one.
