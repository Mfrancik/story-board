# Project overview
Status: active   ·   Last updated: 2026-10-12   ·   Stories: RD-11

## Overview
`/p/{project}` is a project's landing page in the dark shell: a dense tile grid (mockup option B, "command center")
of its story progress, what needs the owner, its live sessions, its latest preflight, its production numbers, the
"What it is" of its brief and its biggest initiatives, with a **Refresh this project** button in the header. RD-9
retired the old project page, so a project had no landing page and links dropped the owner on Stories; this puts
everything checked first on one screen, using only data the board already reads. Git activity tiles follow in RD-12.

## How it works
**Route.** `routes/web.php` registers `GET /p/{project:name}` as `projects.overview` (a new name; the retired
`projects.show` stays banned by `RetireOldPagesTest`) behind `EnsureProjectIsShown`, inside the
`RefreshStaleProjects` group. An unknown or disabled project is a 404 before the component mounts (ADR-013).

**Landing page.** Three things now point here instead of `projects.stories`:
- the shell sidebar's project links (`resources/views/components/shell/sidebar.blade.php`, `data-nav-project`);
- the project-name breadcrumb in `board/project-header` (`data-crumb-project`);
- the **Overview** tab, first in `board/project-tabs` (Overview / Stories / Preflight / Handbook / Map).

The home overview's project tiles still link to Stories (see Known limitations).

**Component.** `app/Livewire/Board/ProjectOverview.php` uses `GuardsProjectPage` (locked `$project`, `hydrate()`
re-check). `render()` reads every tile fresh; tile data is never held in public properties, so none of it enters
the Livewire snapshot. Each source runs through `attempt()`: a throw becomes `null` for that tile only (its error
state) and logs `board.overview_panel_failed`, the home overview's event, now with `project` (ADR-049's rule).

**Tiles, in option B's order** (`resources/views/livewire/board/project-overview.blade.php`, grid
`grid-cols-1 md:grid-cols-2 xl:grid-cols-4`; Needs you and Live now take one column, every other tile two):

| Tile | Source | Notes |
|---|---|---|
| Story progress | `ReadStoryProgress::handle()` | Counts by `ReadProjectStories::kind()`, so they match the Stories page. Built % is built ÷ (total − cancelled). `board/status-bar variant="tag"`. |
| Needs you | `ListNeedsMe::handle(ProjectFilter::only([name]))` | Pick / Draft / Ready rows, each linking to its story page; empty: "Nothing needs you here". |
| Live now | `ListLiveSessions::overview(ProjectFilter::only([name]))` | This project's session cards only; empty: "Nothing running." |
| Latest preflight | `ReadLatestPreflight::handle()` | Time, mode, tokens, wall, ran-on and verdict of the newest CSV row, plus a 13-day runs-vs-all-pass bar chart. No verdict → "—". |
| Production | `ReadProjectProduction::handle()` | Stored `ProdSnapshot`s only, 7-day window (`ListProjectTiles::PROD_DAYS`), `board/sparkline` + `MetricNumber`. No connection → one line "Production not connected · Set up" → `/projects`. |
| About | `ReadHandbook::about()` | The brief's `## What it is…` section at the snapshot SHA via `board/prose`; no brief or no section → `board/handbook-empty`; not read yet → a note. |
| Biggest initiatives | `ReadStoryProgress::handle()` | Top `TOP_INITIATIVES` by story count, built/total track; "All N" links to Stories. |

**The new readers** (all read-only, in `app/Actions/Board/`):
- `ReadStoryProgress` — one grouped `(initiative, status, count)` query on `Story::onRef()`, folded into tallies by
  kind and per-initiative built/total. Sort: most stories first, ties A–Z, "No initiative" last. Named so to stay
  clear of `ReadProjectProgress`, which is on `RetireOldPagesTest`'s banned retired-names list.
- `ReadLatestPreflight` — `ReadPreflightHistory` rows paired with `ReadPreflightRuns` records through
  `ReadPreflightRuns::pairs()`; a day's "all-pass" count is runs whose matched record says `GO`. Days are the
  board's time zone; a day with no run is a zero.
- `ReadProjectProduction` — null when the project has no `prodConnection` (a removed connection leaves metrics
  and snapshots behind, which are ignored); otherwise each enabled metric in `position` order with its latest
  stored value and one point per day (missing day = null). Never opens a production connection.
- `ReadHandbook::about()` — reads `docs/PROJECT-BRIEF.md` at `$project->sha`, takes the first `##` heading
  starting "What it is" (case-insensitive, so the kit's "What it is, and for whom" matches) up to the next `##`.
  Returns null (no brief), `''` (no such section) or HTML.

**`ReadPreflightRuns` change** (owner-approved exception to the story's Do-NOT-touch list): each record now
carries `verdict` (`GO` / `NO-GO`, anything else null — an odd verdict never skips the record), and the matching
logic moved into public `pairs()` (record index per run); `match()` delegates to it with identical results. See
[ADR-059](../decisions/ADR-059-preflight-runs-expose-pairs-and-verdict.md).

**Refresh this project** (F-16). `board/project-header` gained an optional `actions` slot, which holds the button.
`refresh()` stores two locked properties — `refreshQueuedAt` (now) and `refreshAttemptBefore` (the project's
`refresh_attempted_at`, read *before* dispatch because a sync queue finishes inside `dispatch()`) — then
dispatches `RefreshProjectJob` (unique per project) and logs `board.project_refresh_requested`. While
`refreshing()` is true the button reads "Refreshing…", is disabled, and a hidden `wire:poll.5s` element is rendered;
otherwise there is no polling. `refreshing()` is true until the attempt stamp moves past `refreshAttemptBefore`
**and** then either `indexed_at >= attempt` or the state is `stale` / `unreachable`; it gives up after
`REFRESH_WAIT_SECONDS` (300, the job's uniqueness window). The button is also disabled for a disabled project. See
[ADR-060](../decisions/ADR-060-refresh-completion-is-read-from-the-attempt-stamp.md).

## Data model
None. No migration. Reads the on-ref `stories` snapshot, `projects` (`sha`, `state`, `indexed_at`,
`refresh_attempted_at`), `prod_connections` / `prod_metrics` / `prod_snapshots`, the preflight cost CSVs and
`preflight-runs/*.json` records, cached live sessions, and the brief from git.

## Interfaces
- Route: `GET /p/{project:name}` → `projects.overview`. 404 for an unknown or disabled project.
- Livewire action: `ProjectOverview::refresh()`.
- `ReadStoryProgress::handle(Project): array{total, tallies: array<kind,int>, initiatives: list<{name, total, built}>, initiative_count}`.
- `ReadLatestPreflight::handle(Project): ?array{latest: {at, branch, mode, wall, tokens, ran_on, verdict}, days: list<{day, runs, pass}>}`.
- `ReadProjectProduction::handle(Project): ?list<{label, value, text, series: list<{day, value}>}>` (null = no connection).
- `ReadHandbook::about(Project): ?string`; constants `BRIEF`, `ABOUT_HEADING`.
- `ReadPreflightRuns::pairs(array $runs, array $records): array<int, int|null>`; records gain `verdict`.
- `board/project-header` prop `actions` (optional slot). `board/project-tabs` gains the `overview` key.
- Test hooks: `data-tile="<name>"`, `data-overview-grid`, `data-refresh-project`, `data-refreshing`,
  `data-refresh-poll`, `data-preflight-verdict`, `data-production-none`, `data-about-state`, `data-crumb-project`.

## Configuration
None new. `app.timezone` sets the preflight chart's days; the production window follows `ListProjectTiles::PROD_DAYS`.

## Observability
- `board.project_overview_viewed` (info): `project`, `stories`, `needs`, `sessions` (null for a failed tile).
  Page loads only — Livewire requests (refresh, polls) do not log it, so a refresh wait is not a line every 5 s.
- `board.project_refresh_requested` (info): `project`, `request_id`. Follow the same `request_id` into
  `board.refresh_started` / `board.refresh_finished` (or `board.refresh_skipped` when the job's lock is held).
- `board.overview_panel_failed` (error): `project`, `panel` (`progress | needs | live | preflight | production`),
  `error` class, `message`. One per failing tile per render.
- `board.handbook_read_failed` (warning) with `section: about`: git failed reading the brief.
- `board.project_page_refused` (info): unknown/disabled at load (from `EnsureProjectIsShown`) or `request: update`
  when the project leaves the board mid-visit (from `hydrate()`).
- The readers keep their own events: `board.preflight_history_unreadable`, `board.preflight_run_record_skipped`.
- Healthy: one `board.project_overview_viewed` per visit; after Refresh, one `board.project_refresh_requested`,
  then the refresh pair, then the button returns. "Refreshing…" stuck for 5 min means the job never ran — check
  the queue worker (RUNBOOK, "Page hangs… when a project is stale").

## Testing & verification
- `tests/Feature/Board/ProjectOverviewTest.php`: one `it()` per acceptance criterion, plus the refresh finishing
  (button back, polling stops), a project switched off before Refresh (nothing queued, refusal logged), and a tile
  whose source throws. The disabled-project button case mounts the component directly, because the route 404s a
  disabled project before the component exists.
- `tests/Browser/ProjectOverviewTest.php`: sidebar → Overview with every tile at 4 columns, each tab and back,
  Refresh → "Refreshing…" and disabled; 2 columns at 768 px, 1 at 375 px with no sideways scroll. Columns are
  read from the grid's computed `gridTemplateColumns` (RUNBOOK 2026-10-12).
- `tests/Browser/Journeys/BrowseTheBoardTest.php`: the project step lands on Overview, then walks to Stories by tab.
- `RetireOldPagesTest`: `/p/fx` now asserts 200 (superseded per L-12); `/p/unknown` and `/prod` still 404.
- Browser check: from `/`, click a project in the sidebar; check every tile; click each tab and back; press
  Refresh and watch "Refreshing…" clear; resize to 375 px.

## Key decisions & tradeoffs
- `ReadPreflightRuns` keeps the verdict and exposes `pairs()`, rather than a second reader re-matching records →
  [ADR-059](../decisions/ADR-059-preflight-runs-expose-pairs-and-verdict.md).
- Refresh completion is read from the attempt stamp, not the clock →
  [ADR-060](../decisions/ADR-060-refresh-completion-is-read-from-the-attempt-stamp.md).
- Tiles reuse the home overview's readers and rules and stored data only: Needs you and Live now pass a one-project
  `ProjectFilter`; progress uses the Stories page's kinds; Production reads snapshots, never a connection →
  [ADR-061](../decisions/ADR-061-project-overview-tiles-reuse-home-readers-and-stored-data.md).
- Story wins over mockup: no production connection shows a "not connected · Set up" line (the mockup dropped the
  tile); the mockup's `/prod` link became "Settings" → `/projects`; gate details ("1 gate failed") are omitted.

## Known limitations & gotchas
- **Verdict reads "—" everywhere** until RD-10 (parked) writes preflight run records; the chart's green "all-pass"
  bars stay empty for the same reason.
- The home overview's project tiles still link to `/p/{project}/stories`, not here — left for the owner.
- Production shows stored snapshots only, so values are as fresh as the last `board:prod-snapshot` (needs
  `schedule:work`; RUNBOOK "Project tiles show no new production figure").
- About picks the first `## What it is…` heading; a brief that names it differently shows the "no section" state.
- "Refreshing…" relies on `RefreshProject` stamping `refresh_attempted_at` first and `indexed_at` last; changing
  that order breaks completion detection.
- No git activity yet (commits, last edits, most-edited files) — RD-12.

## Change history
2026-10-12 — Project overview at `/p/{project}`: option B tile grid, Overview tab first, sidebar and breadcrumb
repointed, Refresh this project (F-16) (RD-11, `cbef7bc`)
