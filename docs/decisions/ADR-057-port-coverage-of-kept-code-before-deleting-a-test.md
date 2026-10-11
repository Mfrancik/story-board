# ADR-057 — Coverage of kept code is ported before a deleted page's test file goes

Date: 2026-10-11 · Status: accepted · Story: RD-9

## Context

RD-9's story named whole test files to delete with their pages (`HomePageTest`, `ProductionDashboardTest`,
`AppShellTest`, `StoryModalTest`, `WhatNeedsMeTest` and others). Several were also the only tests of code that stays,
because the old pages were the way in. Examples: the `board:prod-snapshot` command and its schedule, `ListLiveSessions`
/ `SessionReader` linking rules, the refresh job's request id, and the story page's refusal of an unknown project.
RD-1 had ported only the action-level assertions it knew about.

## Decision

Port first, then delete (owner ruling at build: no loss of coverage). Each assertion that was the only coverage of
kept code moves to a test of the kept action, command or route, not of a page:
- `ProductionSnapshotTest`: the command, the 23:55 schedule, one snapshot per project/metric/day, the write-grant
  refusal, per-metric failure, `board.prod_read`.
- `LiveSessionLinksTest` (renamed from `LiveSessionsTest`): the rules, exercised through
  `ListLiveSessions::overview()`, the path the home uses, not through `handle()`, which no page calls now.
- `DarkShellTest`: the refresh job's request id, one job per project.
- `StoryPageTest`: unknown/disabled refusal and its log line, the shell render.
- `HomeOverviewTest`: awaiting-pick edge cases.
- `ProjectStoriesTest`: initiative order and counts, pinned directly instead of compared against `ReadProjectProgress`.

Alternative rejected:
- **Delete the files as listed.** Faster, but it would leave the nightly production read, the only remaining
  production read, with no test.

## Consequences

- The suite shrinks by the deleted files minus the ports, not by the whole files.
- Assertions on behaviour that died with a page (for example the old Not on main section) were not ported.
- A future deletion story should grep the test files it removes for kept class names before deleting.
