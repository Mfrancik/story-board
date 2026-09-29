# Single-project dashboard
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-10

## Overview
`/p/{project}` is one project's dashboard, in depth where the all-projects page shows breadth. It has a
header with a one-project refresh, the What needs me cards scoped to the project, **Progress by
initiative** (one status bar per initiative, most open work first) and **Not on main** counted by where
the work lives. It exists because a portfolio tile cannot show coins' 57 initiatives or its hundreds of
off-main versions. It is design A, view 2 (`docs/mockups/SB-7/option-a.html`). Like `/`, it reads the
stored snapshot only; a story row opens the [story modal](story-modal.md), which does its own git read.

## How it works
**Route and refusal.** `routes/web.php` mounts `App\Livewire\Board\ProjectPage` at `/p/{project:name}`
(`projects.show`, SB-7's route). `EnsureProjectIsShown` still refuses an unknown or disabled project
before binding ([ADR-013](../decisions/ADR-013-project-refusal-runs-before-route-binding.md)), so
`ProjectPage::mount(Project $project)` only ever sees a shown project. Before SB-10 this route rendered
`Home` pinned to the project; that mode is gone
([ADR-019](../decisions/ADR-019-project-page-is-its-own-component.md), superseding ADR-014).

**The component.** `app/Livewire/Board/ProjectPage.php`:
- `mount()` stores the project **name** in the locked `$project` (not the model; see ADR-019), logs
  `board.project_viewed`, and queues a `RefreshProjectJob` for this project only if `needsRefresh()`.
  Other projects refresh when `/` or their own page loads.
- `refresh()` is "Refresh this project". It re-checks the name with
  `CheckProjectShown::refusal()`. A project disabled or removed since the page loaded logs
  `board.refresh_refused`, queues nothing and redirects to `/`. Otherwise it logs
  `board.refresh_requested` with `project`, queues one `RefreshProjectJob` and sets the locked
  `notice` ("Refresh queued for coins. Reload in a minute to see it.").
- `showAll($group)` expands a What needs me card (a Livewire call, as on `/`). `toggleKind($kind)`
  opens or closes one location kind's off-main rows. Either one with a value outside `Home::GROUPS` /
  `ProjectPage::KINDS` logs `board.project_action_refused` and changes nothing; only a hand-made
  request can send one.
- `hydrate(CheckProjectShown)` runs on every Livewire request after the first and re-checks the
  name. The route's `EnsureProjectIsShown` only guards the initial GET; Livewire update requests
  skip route middleware. A project switched off or removed mid-visit logs
  `board.project_page_refused` with `request: update`, sets the private `$gone` flag and redirects
  home (`wire:navigate`). `render()` then returns an empty `<div>`, so the project's rollup is never
  read.
- `render()` loads the project by name, then takes `ListWhatNeedsMe::handle($name)` for the cards
  and the header's status counts and parse-error count, and `ReadProjectProgress::handle($id)` for
  the initiative rows and off-main counts. Only while a kind is open does it add one
  `ListWhatNeedsMe::section('offmain', $name)` query, grouped by `location_kind` in PHP.

**The rollup.** `app/Actions/Board/ReadProjectProgress.php` is two grouped queries on `stories`,
whatever the number of initiatives:
- `initiatives()`: on-ref stories grouped by `(initiative, status)`, with `coalesce(status, '(none)')`
  so a missing status shows in the danger tone instead of being dropped, and `max(is_parked)`
  (`RefreshProject` sets `is_parked` on every story of a parked initiative, so max is that value). It
  uses `toBase()`, because the rows are aggregates rather than `Story` models. PHP folds them into one
  row per initiative: `counts` (summed per status with `groupBy('status')`, not keyed with
  `mapWithKeys`: a null status and a literal `(none)` both coalesce to `(none)` and would overwrite
  each other; ordered by `ListWhatNeedsMe::ordered()`, now public so bar segments
  match the tiles), `total`, `built`, `open` (draft plus approved, `ReadProjectProgress::OPEN`) and
  `parked`. Stories with no initiative (sitting directly in `stories/`) become a row named `null`,
  shown as "No initiative". Order: most open work first, ties A–Z with the unnamed row after the
  named ones. That fold is `ReadProjectProgress::rollup()`, public since SB-15 so the
  [Stories page](stories-by-initiative.md) reuses the same counts and order (ADR-025).
- `offMain()`: off-main rows counted by `location_kind`.

**The view.** `resources/views/livewire/board/project-page.blade.php`, top to bottom:
1. Header: "All projects / name" breadcrumb, `h1` with the name and `board/state` dot and label,
   `ref @ short SHA` (or "no snapshot yet"), story and initiative totals, "Refreshed N ago" from this
   project's `indexed_at`, "Refresh this project" (`data-refresh-project`) and the dark-mode toggle.
   Then the notice, the first line of `last_error` when the state is not `ok`, and a row of
   `board/status-chip` tallies with a parse-error warning.
2. **What needs me**: `<x-board.needs-me-cards>`, the same component `/` uses, fed the project-scoped
   groups.
3. **Live now**: a comment-only slot for SB-11.
4. **Progress by initiative** (two thirds of a `lg:grid-cols-3` grid): a legend (plus "other" when an
   out-of-vocabulary status exists), then one `<li data-initiative>` per row with the name, a
   "parked" tag, `<x-board.status-bar>` and `built/total`. Rows past `INITIATIVE_PAGE` (8) carry
   `data-beyond x-show="all"`; "Show all N" is Alpine, since every row is already on the page.
5. **Not on main** (the last third): total, a neutral-toned bar by kind, and one button per kind
   (`data-offmain-kind`) with its count, disabled at 0. No off-main rows at all reads "Nothing off
   main. Every story version is on <ref>."
6. Below the grid, full width, one collapsed-style `board/section` per open kind with its
   `board/story-row`s (branch name and location shown by the row), because a row with a branch name
   and a title does not fit a third column.

## Data model
None owned; no migration. Reads `projects` and `stories` (`initiative`, `status`, `is_parked`,
`location_kind`). Writes nothing.

## Interfaces
- `GET /p/{project:name}` (`projects.show`) → `ProjectPage`. 404 for an unknown or disabled project
  (via `EnsureProjectIsShown`). A `?project=` query string is ignored; `?story=<project>/<ID>` belongs
  to the embedded modal.
- Livewire actions: `refresh`, `showAll('pick'|'approval'|'build')`,
  `toggleKind('branch'|'worktree'|'untracked')`. Locked state: `project`, `expandedGroups`,
  `openKinds`, `notice`.
- Constants: `ProjectPage::INITIATIVE_PAGE`, `ProjectPage::KINDS`, `ReadProjectProgress::OPEN`.
- `ReadProjectProgress::handle(int $projectId): array{initiatives: list<{name, counts, total, built,
  open, parked}>, offmain: array<kind, int>}`.
- Blade: `<x-board.needs-me-cards :pick :approval :build :parked :expanded :filtered :page />` (calls
  the host's `showAll()`), `<x-board.state :state part="dot|label" />`.
- Test hooks: `data-initiatives`, `data-initiative`, `data-open`, `data-beyond`, `data-parked`,
  `data-show-all="initiatives"`, `data-offmain-panel`, `data-offmain-kind`, `data-offmain-rows`,
  `data-refresh-project`, `data-state-dot`, plus the card hooks from the
  [all-projects doc](what-needs-me-home.md#interfaces).

## Configuration
None. Refresh needs a queue worker, as everywhere on the board (`composer run dev` starts one).

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.project_viewed` | info | `ProjectPage::mount()` | `project` |
| `board.refresh_requested` | info | `ProjectPage::refresh()` | `project` (one name; `/` logs `projects`) |
| `board.refresh_refused` | warning | `ProjectPage::refresh()` | `project`, `reason` (`unknown` / `disabled`) |
| `board.project_page_refused` | info | `ProjectPage::hydrate()` | `project`, `reason` (`unknown` / `disabled`), `request: update` |
| `board.project_action_refused` | warning | `showAll()` / `toggleKind()` | `project`, `action`, `value` |
| `board.refresh_*` | see [registry doc](project-registry-and-reader.md#observability) | `RefreshProjectJob` | `request_id` |

Every line carries `request_id`. A healthy "Refresh this project" is `board.refresh_requested` for the
project, then on the worker `board.refresh_started` and `board.refresh_finished` for that project only,
same `request_id`. A `project_page_refused` with `request: update` is the page catching a project
switched off or removed mid-visit (the route's own refusal has no `request` key). A `refresh_finished` for another project from the same `request_id` means the
refresh was not scoped. A `project_action_refused` line means something hand-crafted a Livewire call.

## Testing & verification
- `tests/Feature/Board/ProjectPageTest.php` has one `it()` per acceptance criterion on fixtures shaped
  like the 2026-09-29 dev data: 8 initiative rows ordered by open work with "Show all 57", the parked
  label, coins' off-main counts and branch rows with branch names, asset-track's "Nothing off main",
  a one-project refresh (one job, notice, log), rent-track's danger segments, and a query-count test
  that adds initiatives and asserts the count does not move. Extra `it()`s cover the header, a stale
  state, scoped cards opening the modal, card Show all, the "No initiative" row, empty states, the
  absent Live now slot, the query string not moving the project, the stale-load refresh, the refused
  refresh and the refused actions, the mid-visit disable and removal (redirect home, logged, rollup
  not read), and a null status plus a literal `(none)` summing instead of one dropping the other.
- `tests/Browser/ProjectPageTest.php`: 8 rows then Show all, the branch rows expanding, a card row
  opening the modal, the refresh notice, and asset-track at 375px with no sideways scroll.
- `AllProjectsDashboardTest` and `AppShellTest` were updated: `/p/{project}` now has no project tiles
  and no project dropdown.
- Real-data check (2026-09-29): initiatives coins 57, asset-track 4, rent-track 1; client-dashboard 13
  named plus a "No initiative" row (33 stories), which is the story's "14". coins' `import` is the only
  parked initiative. Off main coins `{branch 154, untracked 93, worktree 15}`, client-dashboard
  `{branch 17, untracked 1, worktree 10}`, none for asset-track and rent-track.

## Key decisions & tradeoffs
- `/p/{project}` is its own `ProjectPage`; the cards were extracted into `board/needs-me-cards` and
  shared, not copied; the component holds the project name, not the model →
  [ADR-019](../decisions/ADR-019-project-page-is-its-own-component.md) (supersedes ADR-014).
- The initiative rollup is one grouped aggregate query and off-main rows load only on demand →
  [ADR-020](../decisions/ADR-020-initiative-rollup-is-one-grouped-query.md).
- The mid-visit re-check lives in `hydrate()`, not `render()`: it must run before any action or
  render touches the project, and route middleware never sees Livewire update requests (ADR-019
  amendment).
- Initiative "Show all" is Alpine (rows already rendered, pure UI per CLAUDE.md); the cards' Show all
  stays a Livewire call, for parity with `/`.
- Deliberate deviations: "every row opens the modal" applies to story rows only; initiative rows are
  aggregates and do not open anything (the owner may want them to expand later). No initiative or
  search filter here (the mockup's view 2 has none). The header drops the mockup's checkout path and
  adds the status chips and parse-error warning.

## Known limitations & gotchas
- Initiative rows are not clickable. There is no drill-down from an initiative to its stories yet.
- A mid-visit refusal is caught on the owner's *next request*, not pushed: a page left open after the
  project is switched off still shows the old render until the owner clicks something.
- Livewire keeps the previous HTML after a redirect, so a test that asserts `assertDontSee` after
  `assertRedirect` checks stale markup. The mid-visit tests prove "not read" by mocking
  `ReadProjectProgress` with `shouldNotReceive('handle')` (see RUNBOOK).
- An open kind's rows are re-read on every render. If a refresh emptied that kind since it was opened,
  its section reads "None left: the last refresh moved them" rather than disappearing.
- Browser tests click "Refresh this project" by `data-refresh-project`, not by text (see RUNBOOK).

## Change history
2026-09-29 — Single-project dashboard at `/p/{project}`: header refresh, scoped cards, Progress by initiative, Not on main by kind; replaces `Home` pinned (SB-10, `a062331`)
2026-09-29 — `hydrate()` sends the owner home (logged) when the project leaves the board mid-visit, replacing a stale render or unlogged 404; status counts summed so null and `(none)` no longer collide (SB-10, `e9a2f6e`)
