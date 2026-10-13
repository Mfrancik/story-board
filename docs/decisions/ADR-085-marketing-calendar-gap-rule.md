# ADR-085 — The calendar's gap rule: 4+ days with nothing approved, within 28 days

Date: 2026-10-12 · Status: accepted · Story: MK-10

## Context

The calendar shows a banner when nothing is approved for a stretch ("Nothing approved Sat 17 – Thu 22 Oct"). The
story says "4+ day gap" but not where to start, how far to look, or what counts as covered.

## Decision

`BuildMarketingCalendar::gap()` walks from today for `HORIZON_DAYS` (28) days and returns the **first** run of
`GAP_DAYS` (4) or more days with no `approved` or `posted` chip.

- **A missed post does not cover its day.** It went nowhere, so the audience saw nothing.
- **Drafts and ghosts do not cover a day.** Only something that will (or did) go out counts.
- **A run that reaches the horizon** returns `open: true`, and the banner says "… and beyond", because it may go on
  past what was checked.
- The range is computed once, inside the same build as the grid, so the banner does not depend on which month is
  on screen.

Alternatives rejected:
- **Only within the visible month.** The banner would appear and disappear as the owner pages, and a gap starting
  next month would be hidden on the last day of this one.
- **Every gap, not just the first.** More noise; the owner fixes the nearest one first. Only the first gap's days
  are tinted on the grid.

## Consequences

- A gap more than 28 days out is not bannered.
- The agenda (375 px) shows the same gap as a row on its first day.
