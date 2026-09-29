# ADR-020 — The project page's initiative rollup is one grouped query; off-main rows load on demand

Date: 2026-09-29 · Status: accepted

## Context

SB-10's Progress by initiative shows one status bar per initiative, and coins has 57 of them. Its Not
on main panel counts versions by location kind, and coins has 262. The story requires the page's
query count not to grow with the number of initiatives, and to mark parked initiatives.

## Decision

- `app/Actions/Board/ReadProjectProgress.php:initiatives()` is **one** query on the project's on-ref
  stories, grouped by `(initiative, status)`, selecting `count(*)` and `max(is_parked)`. PHP folds
  the rows into one entry per initiative. `max(is_parked)` works because `RefreshProject` sets
  `is_parked` on every story of a parked initiative, so it is the same value across the group.
- The query uses `toBase()`: the rows are aggregates, not stories, so they come back as plain objects
  (this also gave phpstan a type for `$row->n`).
- `offMain()` is one more grouped count by `location_kind`. The rows themselves load only while a kind
  is open (`toggleKind`), with one `section('offmain')` query grouped in PHP, and render full width
  below the grid.
- Stories with no initiative get a "No initiative" row, sorted after named rows on ties, rather than
  being left out.
- A query-count test in `tests/Feature/Board/ProjectPageTest.php` adds initiatives and asserts the
  count is unchanged.

Alternatives rejected:
- **Per-initiative queries or eager-loaded `Story` models.** The count would grow with initiatives,
  and loading every story to count them wastes memory on coins.
- **Read parked from the initiative README at render.** That is a git read; the page reads the
  snapshot only, and `is_parked` is already stored at refresh.
- **Load every off-main row up front.** Hundreds of rows for a panel that shows counts first.
- **Drop stories with no initiative.** Their work would vanish from the page; client-dashboard has 33.

## Consequences

- The initiative count includes the "No initiative" row, so it can read one more than the number of
  initiative folders (client-dashboard: 13 folders, 14 rows).
- Opening a kind adds exactly one query per render while it stays open.
- A future query per initiative would fail the query-count test, which is the point of it.
