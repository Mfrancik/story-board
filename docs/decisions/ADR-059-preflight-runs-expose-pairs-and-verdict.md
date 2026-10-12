# ADR-059 — `ReadPreflightRuns` keeps each record's verdict and exposes `pairs()`

Date: 2026-10-12 · Status: accepted · Story: RD-11

## Context

The project overview's Latest preflight tile shows the newest run's verdict and a chart of all-pass runs per day.
The verdict lives in the per-run JSON record (ADR-055), which `ReadPreflightRuns` already reads and matches to CSV
rows — but it dropped the verdict, and `match()` returned only where each run ran. RD-11's story listed
`ReadPreflightRuns` under Do NOT touch ("call them, don't change them").

## Decision

An owner-approved exception to that list: `ReadPreflightRuns::read()` keeps `verdict` (`GO` or `NO-GO`; any other
value becomes null and never skips the record), and the matching moves into a public `pairs()` returning the
matched record's index per run. `match()` delegates to `pairs()` and returns exactly what it did before.
`ReadLatestPreflight` calls `handle()` and `pairs()` and reads the verdict off the matched record.

Alternatives rejected:
- **A new Action that re-reads and re-matches the records.** About 60 duplicated lines, and two copies of the
  matching rule (branch, 10-minute window, nearest first, each side used once) that would drift.
- **Leave the verdict out.** The tile's whole point is the verdict; the story's criterion requires it when recorded.

## Consequences

- One matching rule, one record reader. The Preflight page's "Ran on" is unchanged (its tests still pass).
- An odd verdict reads as unknown ("—"), never a guess, and where that run ran still shows.
- Until RD-10 writes records, every verdict is null and the overview shows "—".
