# ADR-025 — The Stories page reuses the progress rollup over one query

Date: 2026-09-29 · Status: accepted

## Context

SB-15's Stories page must order initiatives exactly as SB-10's Progress by initiative does: most open
work first, ties A–Z, "No initiative" last. It must also load every on-ref story of the project in
**one** query at coins' size (946 stories, 57 initiatives). `ReadProjectProgress::handle()` already
owned that order, but it runs two grouped queries (initiatives and off-main). Its output shape is
read by SB-10 and was on the story's Do-NOT-touch list.

## Decision

The folding step of `ReadProjectProgress::initiatives()` becomes a public
`ReadProjectProgress::rollup(iterable<stdClass>)`. It covers per-status counts, `total`, `built`,
`open`, `parked` and the sort. SB-10 feeds it the rows from its grouped SQL, and the output is unchanged.
`ReadProjectStories::handle()` runs one `Story::onRef()` select, builds the same
`(initiative, status, n, parked)` rows in PHP from the stories it already holds, and passes them to
`rollup()`.

Alternatives rejected:
- **Call `ReadProjectProgress::handle()` alongside the story select.** The page would run three
  queries, which breaks the story's one-query rule, and it would read the off-main counts for nothing.
- **Re-derive the order in `ReadProjectStories`.** One query, but a second copy of the ordering rule
  that would drift from the dashboard the first time either changes.

## Consequences

- The two pages cannot disagree on order or counts unless their input rows differ. A test
  (`ProjectStoriesTest`, "orders the initiatives exactly as ReadProjectProgress does") compares the
  two outputs directly.
- The input rows can differ in one way: MySQL groups initiative names case-insensitively
  (collation), and PHP's `groupBy` is case-sensitive. Names that differ only by case would split here
  and merge on the dashboard.
- `rollup()` takes plain objects, so an Eloquent collection must be passed through `->toBase()`
  (PHPStan rejects a model collection as `iterable<stdClass>`).
