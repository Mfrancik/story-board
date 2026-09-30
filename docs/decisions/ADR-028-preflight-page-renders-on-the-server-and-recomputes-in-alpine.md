# ADR-028 — The Preflight page renders on the server and recomputes in Alpine

Date: 2026-09-29 · Status: accepted

## Context

SB-16's filters must not call the server. The figures (runs, median wall time, median tokens over two
30-day windows) and both charts must follow the filters. HTTP-level Pest tests still need to see rows,
figures and counts without a browser. The chart drawing is well over what fits in an inline `x-data`.

## Decision

- The server renders everything that is true for the unfiltered page: every row, the three figures
  (`ReadPreflightHistory::figures()`), the counts and the skipped notice. Dates are rendered in UTC.
- The page passes a slim copy of the runs (`id, ts, branch, where, mode, wall, tokens, flagged`) and
  the server's `now` to `preflightHistory` in `resources/js/preflight-history.js`. This is the first
  `Alpine.data` registration in the codebase, imported from `resources/js/app.js`.
- Alpine hides rows with `x-show`, rewrites dates into the viewer's time zone, recomputes the figures
  over the visible runs, and draws two inline SVG charts via `x-html`. There is no chart library.
- The x-axis spans every run, not the visible ones, so filtering never rescales time.
- Median and the `m:ss` and compact-token formats exist twice, in PHP (`ReadPreflightHistory::median()`,
  `wall()`, `tokens()`) and in JS (`median`, `fmtWall`, `fmtTok`). This is on purpose.

Alternatives rejected:
- **Livewire-side filtering.** It needs a round trip per filter, which the story rules out.
- **Client-only rendering.** HTTP tests could no longer see rows or figures, and the page would be empty
  before Alpine starts.
- **A chart library.** It is a new dependency, which needs owner approval, for two single-series line
  charts.
- **Row filtering by CSS class (ADR-026).** It would work for rows, but the figures and charts must
  recompute over the visible set anyway, so the component already needs the data. With tens of rows, a
  per-row `x-show` costs nothing.

## Consequences

- The PHP and JS copies of median and the formats must change together. If they drift, the figures
  jump the moment a filter is touched.
- Passing the server's `now` keeps the two windows identical on both sides.
- Row counts stay small (one per preflight run), so per-row reactivity is acceptable here, unlike the
  Stories page.
