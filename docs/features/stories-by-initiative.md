# Stories by initiative
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-15

## Overview
`/p/{project}/stories` is the project page's third tab (Dashboard | Handbook | Stories). It lists every
on-ref story of the project, grouped by initiative, and tags each story with its status. Initiatives
with their counts sit on the left, and the chosen initiative's stories are on the right. It answers the
question Progress by initiative leaves open (SB-10's bars show *how much* is done, not *which* stories).
It came from backlog idea F-4.

## How it works
**Route.** `routes/web.php` registers `GET /p/{project:name}/stories` (`projects.stories`) behind
`EnsureProjectIsShown`, so an unknown or disabled project is a 404 before the component mounts
(ADR-013).

**Component.** `app/Livewire/Board/ProjectStories.php` holds only the locked project name. Its
`render()` calls `ReadProjectStories::handle()`, logs `board.stories_viewed`, and renders the whole
project's list in one response. The story data is passed to the view and never held in a public
property, so hundreds of rows never enter the Livewire snapshot. The component has no actions.
`hydrate()` re-checks `CheckProjectShown` on any later request, because route middleware never sees
Livewire update requests (ADR-019 amendment). A project switched off mid-visit logs
`board.project_page_refused` (`request: update`), redirects home and renders an empty `<div>`.

**The read.** `app/Actions/Board/ReadProjectStories.php:handle()`:
1. One query: `Story::onRef()` for the project, selecting only `id, story_id, title, status,
   initiative, is_parked, path`.
2. Group by initiative in PHP, then build the same `(initiative, status, n, parked)` rows that
   SB-10's grouped SQL returns (a null status becomes `(none)`, as SB-10's `coalesce` does).
3. Feed those rows to `ReadProjectProgress::rollup()`. That is the one copy of Progress by
   initiative's counts, open work and order: most open work first, ties A–Z, "No initiative" last
   among equals. See [ADR-025](../decisions/ADR-025-stories-page-reuses-the-progress-rollup-over-one-query.md).
4. For each initiative, sort its stories naturally by ID with `strnatcmp` (BR-2 before BR-10). A row
   with no ID goes last, by path. Each story is tagged with a `kind` from `ReadProjectStories::kind()`:
   `built | approved | draft | cancelled` are their own kind, and anything else, including no status,
   is `other`. Nothing is guessed, so rent-track's `done` is not Built.
5. Only a well-formed ID gets a `link` (`<project>/<ID>`, from `Story::hasPage()`), as on every other
   list. Other rows are plain and do not open the modal.
6. Each initiative gets a key `g0`, `g1`… by position. Names are not used as keys because they are
   free text, and "No initiative" has no name.

**The view.** `resources/views/livewire/board/project-stories.blade.php` (mockup option C):
- `board/project-header` (breadcrumb, name, state, ref; the slot adds "· N stories in M initiatives")
  and `board/project-tabs` with `current="stories"`. The Handbook uses the same header. The Dashboard
  keeps its own header, because it carries Refresh and the status tallies.
- Filter chips (Built, To do, Draft, Cancelled, Other) with the project-wide tally for each kind,
  "N of M initiatives", and Expand all / Collapse all.
- Left pane: a `<nav>` of initiatives with name, a "parked" tag, "open / all" counts and a
  `board/status-bar variant="tag"`. Below `lg`, it becomes a `<select>`.
- Right pane: one `#stories-pane` list containing every group. When collapsed, only the chosen group
  shows. When expanded, every group with visible rows shows, under sticky headers.
- Each row shows the ID, the title and `board/status-chip variant="tag"`. A cancelled row is
  struck through and muted.
- `<livewire:board.story-modal />` is embedded, so a row opens SB-8's modal, and `?story=` works on
  this page too.

**Pure UI, sized for coins (946 rows).** Only the initiatives (about 60) are bound to Alpine: the root
`x-data` holds `groups` (key, label, total, count by kind), `on` (the filter state), `sel` and `all`.
The rows carry no Alpine of their own:
- A filter chip toggles one `hide-<kind>` class on `#stories-pane`. Each `<li>` carries a literal
  Tailwind variant such as `[.hide-built_&]:hidden`, so the CSS does the hiding.
- One delegated `x-on:click` on the list finds the closest `[data-story-link]` and dispatches
  `board-story`.
- An initiative drops out of the left list when the count of its enabled kinds is 0. Its counts are
  rendered once on the server and never change with the filters.
- Groups other than the first are `display: none` in the server markup, so there is no flash before
  Alpine starts. That is why there is no loading skeleton.
See [ADR-026](../decisions/ADR-026-stories-page-filters-rows-with-css-not-alpine.md).

**Tags.** `resources/views/components/board/status-chip.blade.php` and `status-bar.blade.php` gain
`variant="tag"`. The chip then reads Built / To do / Draft / Cancelled. Cancelled gets a muted outline,
and any other value is grey with its raw text ("no status" for none). Only this page passes the
variant, so the danger tone for off-list values is unchanged everywhere else.

## Data model
None. No migration, and nothing stored. It reads the on-ref `stories` snapshot (see
[project registry and reader](project-registry-and-reader.md)). Off-main rows (`location_kind` not
null) are excluded by `Story::onRef()`.

## Interfaces
- Route: `GET /p/{project:name}/stories` → `projects.stories`. Returns 404 for an unknown or disabled
  project.
- `ReadProjectStories::handle(Project): array{stories: int, tallies: array<kind,int>, initiatives:
  list<{key, name, counts, total, built, open, parked, kinds, stories: list<{id, story_id, title,
  status, kind, link}>}>}`.
- `ReadProjectStories::kind(?string): string` and `ReadProjectStories::KINDS` (filter-chip order).
  `status-chip` calls `kind()` too, so there is one mapping.
- `ReadProjectProgress::rollup(iterable<stdClass>): list<{name, counts, total, built, open, parked}>`.
  It is public for this page. SB-10's `handle()` output shape is unchanged.
- Props: `board/status-chip` and `board/status-bar` take `variant` (`null` or `'tag'`).
  `board/project-header` takes `model`, `page` and `current`, and its slot extends the ref line.
- Event: a row dispatches `board-story` with `<project>/<ID>` (SB-8).

## Configuration
None.

## Observability
- `board.stories_viewed` (info): `project`, `stories` (the on-ref count). It is logged in `render()`.
  The page does not re-render in normal use, so expect one line per page load. Several lines for one
  visit mean something is triggering a re-render and resending the whole list.
- `board.project_page_refused` (info), with `request: update`: a project left the board mid-visit
  (from `hydrate()`). A 404 at page load logs the same event, without `request`, from `EnsureProjectIsShown`.
- Healthy: one `board.stories_viewed` per visit, and no Livewire update requests while clicking
  initiatives, chips or Expand all. The only request comes from opening a story, which goes to
  StoryModal.

## Testing & verification
- `tests/Feature/Board/ProjectStoriesTest.php`: one `it()` per acceptance criterion (order and counts,
  natural ID order, cancelled, To do, off-list grey tags, full counts under filters, No initiative,
  parked, off-main excluded, empty state, 404 and `hydrate()`, logging). Extra tests cover:
  - The chip's danger tone off this page.
  - The query-count test at coins' size. It asserts that the action is exactly one query, and that the
    page's total query count does not grow with story count. It does not assert "one query on
    `stories`", because the sidebar runs its own stories count.
  - A test that `ReadProjectProgress` and `ReadProjectStories` give the same order and counts.
  - The Stories tab.
- `tests/Browser/ProjectStoriesTest.php`: the Alpine behaviour. Covers selection with no server
  request, the Built chip hiding rows and an all-built initiative, Expand all jumping to a group and
  Collapse all, a row opening the modal, and the select at 375px with no sideways scroll.
- Browser check: `/p/coins/stories`. Select acquisition and check that ACQ-22 is struck through as
  Cancelled. Turn off Built. Try Expand all and Collapse all. Open a story. Check that
  `/p/rent-track/stories` shows grey `in` and `done` tags.

## Key decisions & tradeoffs
- One query, one ordering: the page counts in PHP and reuses `ReadProjectProgress::rollup()` rather
  than calling `handle()` (three queries) or re-deriving the sort →
  [ADR-025](../decisions/ADR-025-stories-page-reuses-the-progress-rollup-over-one-query.md).
- Rows are filtered by one container class and CSS variants, not by Alpine on each row →
  [ADR-026](../decisions/ADR-026-stories-page-filters-rows-with-css-not-alpine.md).
- No skeleton, although the story asked for one: nothing loads after the first response.
- Off-list statuses are grey on this page by owner answer. The danger tone stays elsewhere because
  there an off-list value is a problem to see.

## Known limitations & gotchas
- **The `hide-*` class names must stay literal in the Blade file.** Tailwind finds classes by scanning
  `resources/views` (`@source '../views'` in `resources/css/app.css`). If the `$hide` map moves into
  PHP under `app/` or is built by string concatenation, the variants are not generated and filtering
  silently stops working, with no error.
- Initiative names are grouped **case-sensitively** here but **case-insensitively** by SB-10's MySQL
  `GROUP BY` (collation). Two names that differ only by case would be two groups here and one on the
  dashboard. No real project has this today.
- The whole list is rendered on every page load, so the payload grows with the project. At coins' size
  this is fine. Paging would conflict with filtering and Expand all, which work on the whole list.
- Selection, filters and Expand all are not remembered across visits (out of scope). There is no
  search by ID or title.
- A row whose ID is not well formed is shown but does not open the modal.
- Off-main stories are not listed. The Dashboard's Not on main panel owns them.

## Change history
2026-09-29 — Stories tab at `/p/{project}/stories`: initiatives with counts, stories tagged by status,
filter chips, Expand all; `rollup()` extracted; `board/project-header` shared with the Handbook (SB-15, `ae144cb`)
