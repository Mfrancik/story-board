# ADR-054 — Project tiles reuse existing rules and stored data only

Date: 2026-10-11 · Status: accepted · Story: RD-7

## Context

Each home tile (RD-7) summarises one project: story progress, off-main work, last preflight, production, and
whether a session is live. Every one of those already has an owner elsewhere (the Stories page, `IndexOffMain`,
`ReadPreflightHistory`, the Production page, Live now). The tiles re-render on the overview's 30 s poll, and the
story forbids opening a production connection from them.

## Decision

- **Story counts use the Stories page's rule.** `ListProjectTiles::kinds()` is one grouped `status` query over
  on-ref stories, mapped through `ReadProjectStories::kind()`, so a tile and its Stories page always agree.
- **Off main counts stored off-main story rows** (`Story::offMain()`), the same unit the old dashboard tile used,
  not distinct branches or worktrees.
- **The production headline is stored data only.** It is the first enabled metric's (by `position`, then id)
  latest `ProdSnapshot` in the last 7 board-local days, with a 7-day series. No connection, no enabled metric, or
  no snapshot in the window means no production row. `ProductionReader` is untouched.
- **The live dot comes from the Live now panel's sessions**, so the dot and the cards cannot disagree. If Live now
  fails, no tile shows a live dot.
- **The sparkline is the existing `board/sparkline` with a `decorative` prop** (a bare `aria-hidden` SVG, no
  hover, no end dot), because the figure is already in text beside it. Not a new component.
- **The tile re-queries on every poll**, as RD-5's figures do; only Shipped this week is cached (ADR-053).
- **The last-preflight slot has a nullable `ran_on`** that renders "on X" when set; RD-8 fills it.

Alternatives rejected:
- **Counting progress in the tile's own query.** A second copy of the status→kind rule would drift from the
  Stories page.
- **Reading production live.** Would open a production connection from the home page every 30 s.
- **A per-field tile error state (the mockup's).** Off-main is stored data with no separate failure, so a tile
  fails as one panel.

## Consequences

- A tile's production row lags by up to a day (the nightly snapshot) and disappears after 7 days without one.
- Without a working Live now, live dots vanish silently; the Live now panel's own error state is the signal.
