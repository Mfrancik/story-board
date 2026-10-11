# Retired pages (RD-9)
Status: active   ·   Last updated: 2026-10-11   ·   Stories: RD-9

## Overview
RD-2 to RD-8 built the redesign's pages beside the old ones, leaving the old code unrouted or unused so each story
stayed small. RD-9 deleted that old code, its views and its tests in one reviewed step, so the next build does not
stumble over dead code. This doc is the map of what went, what replaced it, where the tests of kept code moved,
and the guard that keeps the deleted names from coming back. The owner approved the exact file list before anything
was deleted. Where the story and the code disagreed, the owner's rulings at build (story file, "Owner decisions at
build") override the story body.

## How it works
**What was deleted, and what replaced it.**

| Gone | Replaced by |
|---|---|
| `/` old dashboard: `App\Livewire\Board\Home`, `needs-me-cards`, `project-card`, `section`, `story-row`, `ListWhatNeedsMe` | [Home overview](home-overview.md) (`Overview`, `ListNeedsMe`, `ListProjectTiles`) |
| `/p/{project}` (`projects.show`): `ProjectPage`, `ReadProjectProgress` (whole class: it only had `handle()`) | The project's tabs: [Stories](stories-by-initiative.md), Preflight, Handbook, Map. The shell sidebar links each project to its Stories |
| Story modal: `StoryModal`, `ResolveStoryLink`, `.mockup-thumb*` CSS | [Story page](story-page-and-mockups.md) at `/p/{project}/s/{id}` |
| Live sessions panel and sidebar badge: `LiveSessions`, `ListLiveSessions::counts()`, the cards' `thumb` field, `.live-pulse` CSS | The overview's Live now (`ListLiveSessions::overview()`) |
| `/prod`: `ProductionDashboard`, `ListProductionDashboard`, `prod-stat`, `prod-change`, `prod-row-status`, the `ReadProductionMetrics` job, `--color-loss` | The overview's project tiles (RD-7), fed by the nightly `board:prod-snapshot` |
| Old frame: `layouts/board.blade.php`, `App\View\Components\Board\Sidebar` and `board/sidebar` | [Dark shell](dark-shell.md) (`layouts/shell`, `x-shell.sidebar`) |
| `/?project=x` → `/p/x` redirect: `RedirectProjectFilter` | Nothing: the parameter is ignored (below) |
| `tests/Pest.php:oldHome()` and the `/test/old-home` route ([ADR-050](../decisions/ADR-050-old-home-is-tested-at-a-test-only-route-until-rd-9.md)) | Nothing |

**Kept by evidence, though the story listed them.** `StoryPage` and `StoryPageTest` (RD-3 rewrote the page in place),
`board/sparkline` (RD-7 tiles), `metric-result` (`/projects`), `FindStoryVersion::others()` (the story page), and the
mockup file route `/p/{project}/m/{id}/{file}` with `MockupFileController`, `ReadMockupFile`, `ParseVersion` and
`MockupFileTest`. The story page's frame and its `?v=` versions load through that route
([ADR-056](../decisions/ADR-056-mockup-file-route-kept-for-the-story-page.md)).

**One helper moved before its class went.** `ListProductionDashboard::format()` is now
`app/Services/Production/MetricNumber.php:format()`, a pure helper beside the other production classes. The tiles
(`ListProjectTiles::prod()`) and the sparkline's hover readout (`resources/views/components/board/sparkline.blade.php`)
both call it, so a figure and its trend line print the same number.

**Production reads now happen once a day.** `/prod` was the only page that queued `ReadProductionMetrics`, on load and
on Refresh. With both gone, `board:prod-snapshot` (`app/Console/Commands/BoardProdSnapshot.php`, scheduled 23:55 in
`board.timezone` in `routes/console.php`) is the only production read. It calls
`app/Actions/Board/ReadProductionProject.php:handle()` per enabled, connected project. Without `schedule:work`
running, tiles never get a new figure (RUNBOOK "Project tiles show no new production figure").

**`/?project=` is ignored.** `routes/web.php` maps `/` to `Overview` with no extra middleware. A stray `project`
query is not read by anything, so the page returns 200 and shows the whole board. The overview's own filter is
`?projects=` (plural) ([ADR-058](../decisions/ADR-058-legacy-project-query-is-ignored.md)).

**Small trims in kept code.** `ListLiveSessions::matched()` lost its `$log` flag: only `counts()` passed `false`, so
`board.session_ignored` now always logs. Docblocks in `CheckProjectShown`, `FindStoryVersion`, `ListNeedsMe`,
`ReadProductionProject`, `EnsureProjectIsShown`, `ProdSnapshot` and `project-tabs` no longer name deleted callers.
`--color-gain` stays because the app map's Built tag uses `text-gain`; only `--color-loss` went.

## Data model
None. No migration, and no table or column dropped. `prod_snapshots`, `prod_connections`, `prod_metrics` and
`project_locations` stay: the tiles, `/projects` and off-main indexing use them.

## Interfaces
Routes removed: `/prod` (`prod`), bare `/p/{project}` (`projects.show`), `/test/old-home` (tests only). All three 404.
Every remaining page route answers 200: `/`, `/projects`, `/mockups`, `/mockups/{project}/{id}`, and each project's
`stories`, `preflight`, `handbook`, `map`, `s/{id}` and `m/{id}/{file}`.

## Configuration
None.

## Observability
No events were added. The deleted code's events went with it: `board.home_viewed`, `board.project_viewed`,
`board.story_modal_opened` / `_refused`, `board.project_filter_redirected`, `board.prod_viewed`,
`board.prod_refresh_requested` / `_refused`, `board.prod_read_stalled` and `board.prod_read_crashed`. A grep for any of
them in a current log means an old process is still running. `board.prod_read` still logs from `ReadProductionProject`, now only
when the nightly command runs, so a day with no `board.prod_read` line means the scheduler was not running.
`board.session_ignored` (debug) now logs on every live-session read, not only on the overview's.

## Testing & verification
`tests/Feature/Board/RetireOldPagesTest.php` holds the acceptance criteria, one test per criterion:
- `/prod`, `/p/fx` and `/p/unknown` 404.
- `/?project=story-board` (and `fx`) returns 200 with the filter reading "All projects".
- Every remaining route returns 200.
- Two guards against a deleted name coming back. An `arch()` test: `expect('App')->not->toUse([...])` over the deleted
  classes. And a text scan of `app`, `resources`, `routes`, `bootstrap` and `tests` (`.php`, `.css`, `.js`) for
  `retiredNames()`, the deleted class, view, component and helper names as word-bounded regexes. The scan exists
  because arch reads PHP `use` statements only and cannot see `<x-board.sidebar>` or `layouts.board` in Blade.

Coverage of kept code was ported before its test file was deleted
([ADR-057](../decisions/ADR-057-port-coverage-of-kept-code-before-deleting-a-test.md)):

| Kept code | Was tested in | Now tested in |
|---|---|---|
| `board:prod-snapshot`, its 23:55 schedule, one snapshot per project/metric/day, write-grant refusal, per-metric failure, `board.prod_read` | `ProductionDashboardTest` | `tests/Feature/Board/ProductionSnapshotTest.php` |
| `ListLiveSessions` / `SessionReader` linking and reading rules | `LiveSessionsTest` | `tests/Feature/Board/LiveSessionLinksTest.php` (renamed), through `overview()`, the path the home uses |
| Refresh job carries the request id; one job per project | `HomePageTest` and the old page tests | `tests/Feature/Board/DarkShellTest.php` |
| Story page refusal of an unknown or disabled project, and its log line; shell render | `AppShellTest`, `StoryModalTest` | `tests/Feature/Board/StoryPageTest.php` |
| Awaiting-pick edge cases (built, cancelled, chosen, empty set) | `WhatNeedsMeTest` | `tests/Feature/Board/HomeOverviewTest.php` |
| Initiative order and counts on the Stories page | compared against `ReadProjectProgress` | pinned directly in `ProjectStoriesTest` |

The old dashboard's Not on main section tests went with it, unported: that section has no successor (Known limitations).
`tests/Browser/Journeys/BrowseTheBoardTest.php` now steps `/` → `/p/coins/stories` → `/p/rent-track/stories` on
the shell sidebar's `data-nav-project` hooks.

Browser check (story): click every sidebar item and every project tab. No dead links, no 500s.

## Key decisions & tradeoffs
- The mockup file route stays, against the story's list: the kept story page needs it, and re-pointing it was scope
  growth → [ADR-056](../decisions/ADR-056-mockup-file-route-kept-for-the-story-page.md).
- Port, then delete: no coverage of kept code is lost with a deleted page's tests →
  [ADR-057](../decisions/ADR-057-port-coverage-of-kept-code-before-deleting-a-test.md).
- `/?project=` is ignored, not redirected and not 404 → [ADR-058](../decisions/ADR-058-legacy-project-query-is-ignored.md).
- `MetricNumber` is its own pure class, not a method on `ListProjectTiles`, so the sparkline does not depend on the
  tiles action.
- The "nothing references a deleted class" check is two tests, because arch cannot see Blade.
- Orphans beyond the story's list went too (owner ruling): `ReadProductionMetrics`, `ListLiveSessions::counts()` and
  `thumb`, `BoardListTest`, `LiveSessionsTest` (after porting), and the dead CSS.

## Known limitations & gotchas
- **No page lists off-main work any more.** The old dashboard's Not on main section is gone. Off-main work shows only
  as a count on the overview's project tiles and as the versions banner on a story page.
- **Production figures update once a day, and only with the scheduler running.** No page load triggers a read.
- The SB-11 privacy check (message, version, `requestId`, slug sentinels never reach the page) now runs against `/`
  in `LiveSessionLinksTest`.
- Dead code left for later (backlog F-15): `ListLiveSessions::handle()`, its private helpers and the `chosen` field
  have no caller; `ReadMockupSets::countFor()` lost its only caller (the old project page's Mockups button);
  `ReadProductionProject::outcome()` / `outcomeKey()` are written but read only by the next write; the
  `@else @fluxAppearance` light branch in `resources/views/partials/head.blade.php` and the stale comment at
  `resources/css/app.css` lines 10–14; legacy `zinc` / `dark:` classes in shared board components; `.board-modal` /
  `.board-drawer` usage was not checked.
- The project app map and handbook error states still say "Refresh the project from its dashboard", and there is no
  dashboard now (also in F-15).
- `retiredNames()` matches by regex. A future class whose name contains a retired word (e.g. a new `ProjectPage`)
  fails the scan; widen the word boundary or rename, do not delete the guard.

## Change history
2026-10-11 — Old dashboard, `/p/{project}`, story modal, live panel, `/prod`, `layouts/board` and the `/?project=` redirect deleted; kept-code coverage ported; `MetricNumber` extracted (RD-9, `2669db7`)
