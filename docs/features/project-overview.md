# Project overview
Status: active   ·   Last updated: 2026-10-12   ·   Stories: RD-11, RD-12

## Overview
`/p/{project}` is a project's landing page in the dark shell: a dense tile grid (mockup option B, "command center")
of its story progress, what needs the owner, its live sessions, its latest preflight, its production numbers, the
"What it is" of its brief, its biggest initiatives and its git activity (commits per day, last edits, most-edited
code files today), with a **Refresh this project** button in the header. RD-9
retired the old project page, so a project had no landing page and links dropped the owner on Stories; this puts
everything checked first on one screen. RD-12's activity tiles are the only part that reads git live, and only
through fixed read-only `GitReader` forms.

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
| Commits per day (RD-12) | `ReadGitActivity` `commits` | 14-day SVG bar chart (`DAYS`), window total and today's count; zero days have no bar. Two columns. |
| Last edits (RD-12) | `ReadGitActivity` `edits` + `uncommitted` | Latest `EDITS` commits: short sha, type pill, subject, story ID link, +/−, relative time. Header shows the uncommitted-path count or "Working copy clean". Two columns. |
| Most-edited files today (RD-12) | `ReadGitActivity` `files` | Top `TOP_FILES` code files by commits touching them today; `NOT_CODE` (`docs/`, `stories/`) excluded. Full width. |

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

**Git activity** (RD-12, `app/Actions/Board/ReadGitActivity.php:handle()`). Four reads, each on its own, so one
failing read empties only its own tile:
- `commits` — `GitReader::commitsPerDay()`: `log()`'s RD-7 form over the window, bucketed by committer day in
  `app.timezone`, zero-filled, oldest first.
- `edits` — `GitReader::commitStats()`: `git log --shortstat --no-ext-diff --no-textconv --max-count=<n>`. Subjects
  are split into type / story ID / text by the CLAUDE.md step-6 pattern (`SUBJECT`); anything else shows whole. A
  story ID links to its story page only when it is in this project's on-ref snapshot, as Shipped this week does. A
  merge has no stat line, so it shows "—".
- `files` — `GitReader::filesChangedSince()` from today's local midnight: `--name-only --no-renames`, ranked by
  commits touching each path (ties A–Z), then filtered to code by `isCode()`.
- `uncommitted` — `GitReader::uncommittedCount()`: `status --porcelain -z --untracked-files=normal` on the checkout,
  counted (a rename counts once; an untracked directory counts once).

The first three read the project's **ref** (`origin/main` after the last fetch), so unpushed local commits do not
appear; only the uncommitted count reads the working copy. Each read is cached by `read()` (see
[ADR-082](../decisions/ADR-082-git-activity-cache-holds-plain-values-and-never-failures.md)); the new git forms and
the tightened `status` guard are [ADR-081](../decisions/ADR-081-git-activity-reads-are-fixed-log-and-status-forms.md).
At 375 px Last edits hides the short sha and type pill; the +/− stays.

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
- `ReadGitActivity::handle(Project): array{commits: ?{days: list<{day, count}>, total, today}, edits: ?list<{sha, short, at, type, subject, story_id, linked, added, removed}>, files: ?list<{path, commits}>, uncommitted: ?int}` — null per key is that tile's error state.
- `GitReader` (RD-12): `commitsPerDay(path, ref, days, timezone): array<Y-m-d,int>`, `commitStats(path, ref, count 1–99)`,
  `filesChangedSince(path, ref, since): array<path,int>`, `uncommittedCount(path): int`; each throws `GitReaderException`.
- `board/project-header` prop `actions` (optional slot). `board/project-tabs` gains the `overview` key.
- Test hooks: `data-tile="<name>"`, `data-overview-grid`, `data-refresh-project`, `data-refreshing`,
  `data-refresh-poll`, `data-preflight-verdict`, `data-production-none`, `data-about-state`, `data-crumb-project`;
  RD-12: `data-activity-state` (`ok | empty | error`), `data-commits-total`, `data-commits-today`, `data-commits-chart`,
  `data-edit`, `data-edit-story`, `data-edit-added`, `data-edit-removed`, `data-uncommitted`, `data-hot-file`.

## Configuration
None new. `app.timezone` sets the preflight chart's days and the activity tiles' days (and "today" for
most-edited); activity reads are cached `ReadGitActivity::CACHE_SECONDS` (300 s) in the default cache store; the production window follows `ListProjectTiles::PROD_DAYS`.

## Observability
- `board.project_overview_viewed` (info): `project`, `stories`, `needs`, `sessions` (null for a failed tile).
  Page loads only — Livewire requests (refresh, polls) do not log it, so a refresh wait is not a line every 5 s.
- `board.project_refresh_requested` (info): `project`, `request_id`. Follow the same `request_id` into
  `board.refresh_started` / `board.refresh_finished` (or `board.refresh_skipped` when the job's lock is held).
- `board.git_activity_failed` (warning, RD-12): `project`, `read` (`commits | edits | files | uncommitted`), `error`.
  One per failed read per render — failures are not cached, so a broken repo logs on every render until fixed.
- `board.git_refused` (from `GitReader`): a caller asked for a `log`/`status` form outside the fixed ones — a code
  bug, not a repo problem.
- `board.overview_panel_failed` (error): `project`, `panel` (`progress | needs | live | preflight | production | activity`),
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
- `tests/Feature/Board/ProjectGitActivityTest.php` (RD-12): one `it()` per acceptance criterion on `GitFixture`
  repos, a dataset of refused `log`/`status` forms (L-11: deleting the guards turns them red), and a serialising-cache
  case. Fixture commits must be made in date order (`--since` cut-off, RUNBOOK 2026-10-11).
- `tests/Browser/ProjectGitActivityTest.php` (RD-12): the three tiles with today's numbers and a Last edits story
  link; 375 px with no sideways scroll. Run under the browser lock, after `npm run build` in a worktree (new
  Tailwind classes are unstyled until then).
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
- Git activity reads are fixed read-only `GitReader` forms; `--no-textconv`/`--no-ext-diff` so a project's own diff
  helpers never run; `status` options narrowed to listings →
  [ADR-081](../decisions/ADR-081-git-activity-reads-are-fixed-log-and-status-forms.md).
- Activity cache holds scalars only and never a failure →
  [ADR-082](../decisions/ADR-082-git-activity-cache-holds-plain-values-and-never-failures.md).
- RD-12 deviations: the mockup's "Full log" link is omitted (no log page exists); most-edited reads the ref, not
  unpushed local commits.
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
- Activity windows use `git log --since`, which stops at the first commit older than the window: a history with
  skewed committer dates (rebases, clock drift) can undercount commits per day and most-edited.
- Commits per day and most-edited count committer dates; a commit dated in the future is dropped from the chart.
- The uncommitted count can be up to 5 min stale (cached); the ref-based tiles change only after a refresh.
- Most-edited "code" is everything outside `docs/` and `stories/` — config, lock files and tests count as code.

## Change history
2026-10-12 — Project overview at `/p/{project}`: option B tile grid, Overview tab first, sidebar and breadcrumb
repointed, Refresh this project (F-16) (RD-11, `cbef7bc`)
2026-10-12 — Git activity tiles: commits per day, Last edits with uncommitted count, most-edited code files today;
new read-only `GitReader` forms and a tighter `status` guard (RD-12, `9b0addd`)
