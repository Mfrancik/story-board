# All-projects dashboard (What needs me)
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-3, SB-4, SB-5, SB-7, SB-8, SB-9, SB-10

## Overview
`/` is the all-projects dashboard. It leads with what is waiting on the owner: three **What needs me**
cards (Awaiting a pick, Drafts to approve, Ready to build), then the **In flight** figures, one
**project tile** per enabled project, and the collapsible Not on main, Built and Parked drafts
sections. One page says what to decide and where work stands. The order is an owner ruling (SB-9,
design A): the page leads with decisions, not with reporting. SB-3's "Inbox" lists became the cards.
Everything reads the stored snapshot only (see
[Project registry and story reader](project-registry-and-reader.md)). A row opens the
[story modal](story-modal.md) (SB-8), which does its own git read; the page itself never touches git.

## How it works
**Snapshot facts, computed at refresh.** Two of the groups need facts that `bin/story-index` does not
give. `app/Actions/Board/RefreshProject.php` computes them once per refresh, at the resolved SHA, so
the page never works them out:
- `parkedInitiatives()` lists `stories/` with `GitReader::listFiles()` (`ls-tree -r -z`). For each
  `stories/<initiative>/README.md` whose `Status:` line starts `draft group`, the stories in that
  initiative get `is_parked = true`. This follows kit §Draft groups. Having a README is not enough:
  coins has a "release group" README and one with no status, and neither is parked.
- `dateOf()` stores the first valid `YYYY-MM-DD` in the story's `Source:` line as `dated_on`.
  "Ready to build, oldest first" sorts on it.

**The query.** `app/Actions/Board/ListWhatNeedsMe.php:handle()` builds every card and section from the same
`scoped()` query. That query covers stories of enabled projects on the ref only
(`location_kind IS NULL`; SB-5's off-main rows are excluded, except from `offmain`), with the project, initiative and search
filters applied:
- **approval**: `status = draft` and not parked.
- **pick**: `status` in `draft|approved`, `mockups.options` non-empty, `mockups.chosen` JSON null.
  Built and cancelled stories are history, not decisions, so they are left out.
- **build**: `status = approved`, ordered by `dated_on` with undated stories last
  (`orderByRaw('dated_on is null')`, because MySQL has no `NULLS LAST`), then project, then path.
- **offmain** (SB-5): the same query with `offMain: true` (`location_kind IS NOT NULL`). It is a count
  only, and `section('offmain')` orders rows by project, location kind, branch and path. See
  [Not on main](not-on-main.md).
- **parked** and **built**: counts only. `section()` loads their rows only while the section is open,
  because coins alone has hundreds of built stories.
- **projects** (`projects()`): one summary per enabled project, feeding its tile. It holds on-ref
  counts by *raw* status (so an out-of-vocabulary value stays visible), ordered
  draft/approved/built/cancelled and then A–Z, the number of on-ref stories with parse errors, and
  (SB-9) `offmain`, the number of versions not on main. Two grouped queries cover every project. The
  first sums `location_kind is null` and `location_kind is not null` side by side per status, so the
  not-on-main count costs no query of its own. A status seen only off main (a mockup-only row's null
  status, say) has `n = 0` and is filtered out, so it draws no segment.
- **in_flight** (`inFlight()`, SB-9): approved-and-unbuilt, versions not on main, and the names of
  projects whose `state` is not `ok`. It is summed in PHP from the project summaries, so it costs no
  query and follows the same enabled-only rule as the tiles.

**Two scopes on one page.** The initiative filter and the search narrow only the cards, the ID
hint and the sections. The tiles and In flight describe the whole portfolio. A project's state cannot be filtered by initiative anyway; see
[ADR-018](../decisions/ADR-018-portfolio-figures-ignore-filters-and-add-no-query.md).

A search shaped like a story ID (`ID_PATTERN`, CLAUDE.md step 6) matches `story_id` exactly, so
`MOB-65` does not also match `MOB-650`. Any other search is a `LIKE` on ID or title, with `%`, `_` and
`\` escaped. `withId()` catches an ID search that no visible group answers, such as a cancelled
story. The page then says which status the story has and links to its
[story page](story-page-and-mockups.md) (`stories.show`).

**The component.** `app/Livewire/Board/Home.php` is the page, mounted at `/` with
`Route::livewire('/', Home::class)` in `routes/web.php`.
- `mount()` logs `board.home_viewed` and queues a `RefreshProjectJob` for each enabled project whose
  `needsRefresh()` is true. Queued rather than run after the response; see
  [ADR-006](../decisions/ADR-006-refresh-runs-on-the-queue.md).
- `refresh()` is the Refresh button. It logs `board.refresh_requested`, queues a job for every
  enabled project, and sets the locked `notice` ("Refresh queued for N projects…"), shown as a
  `role="status"` line.
- `initiative` and `q` are `#[Url]` properties, so filters survive a reload and can be shared as a
  link. SB-9 removed the `project` filter outright. The sidebar switches project, and
  `RedirectProjectFilter` (SB-7) already sends any `/?project=` to `/p/{project}` before `Home` runs
  ([ADR-017](../decisions/ADR-017-dashboard-leads-with-what-needs-me-and-drops-the-project-filter.md)).
- `Home::GROUPS` is the card order (`pick`, `approval`, `build`). `toggleSection()` opens Not on main,
  Built or Parked drafts (`Home::SECTIONS`). `showAll()` lifts a row cap: `Home::PAGE` (the design-A
  top 5) for a card, and `Home::SECTION_PAGE` for an open section.
- `render()` also passes `refreshedAt`, the **oldest** `indexed_at` among the shown projects. The
  header's "Refreshed N ago" uses it, because the page is only as fresh as its stalest snapshot.
- `openSections`, `expandedGroups` and `notice` are `#[Locked]`, because Livewire public properties can
  otherwise be set from the browser.
- SB-3's `expand()` and `bodies` were removed in SB-8. Story text now lives in the modal
  (`StoryModal`, embedded once in the view).

**The view.** `resources/views/livewire/board/home.blade.php` uses the `layouts/board` shell (the SB-7 project
sidebar, no auth, Flux appearance for light/dark). It serves `/` only: since SB-10 one project's page
is its own component, [Single-project dashboard](single-project-dashboard.md). Top to bottom:
1. The header: `h1` "What needs me" (the first heading in `<main>`), the subtitle "All projects · N
   projects · N stories on each project's ref", then "Refreshed N ago", Refresh and the dark-mode toggle.
2. The filter bar (initiative, search) and the ID-search hint (`data-goto`).
3. **What needs me**: `<x-board.needs-me-cards>` (extracted in SB-10 and shared with the project
   page): three `<section data-card data-group>` cards in a `md:grid-cols-3` grid, so they stack
   below 768px. Each has a dot, title, big count, hint, its top `Home::PAGE` rows and "Show all N →",
   which calls the host's `showAll()`. An empty card shows a dashed empty state ("Nothing to
   approve.") and skips its eager-load query. The cards are not `board/section`, because the design-A
   card has a big count and a top-5 list.
4. **Live now**: a comment-only slot. SB-11 renders it; nothing is output until then.
5. **In flight**: a `<dl>` of three figures (`data-figure="approved|offmain|not-ok"`). Projects not ok
   turns danger-toned when non-zero and lists the names.
6. **Projects**: one `board/project-card` tile per project in a two-column grid.
7. The collapsible Not on main, Built and Parked drafts sections (`board/section`), unchanged.

The Blade components are in `resources/views/components/board/`:
- `section`: a boxed list with a heading, count and hint. Optionally collapsible. Since SB-9 only
  the three collapsible sections use it.
- `story-row`: one row. When `Story::hasPage()` it is a `<button>` that dispatches `board-story`
  with `<project>/<ID>` to open the [story modal](story-modal.md); otherwise it is a plain row.
  `variant="card"` (SB-9) is the compact two-line card row: `project · ID`, a right-hand meta, then
  the title. The meta is the status chip on a pick row, otherwise the `Source:` date (`dated_on`) as
  "N ago". Pick rows also list the option letters. The default `list` variant is the section row. For
  an off-main row (SB-5), it shows its `location` and either a status chip or a "mockups only" tag,
  plus "picked X". The `wire:key` includes variant and group, because one story can sit in a card
  and in an open section at once.
- `project-card`: the dashboard tile (SB-9, extended from SB-3's health card). The whole tile is an
  `<a wire:navigate>` to `/p/{project}`. It shows a state dot (the sidebar's token map) and, when not `ok`,
  a state label, both from `board/state` since SB-10; then the on-ref story total, a `status-bar`, a count chip per status, "N not on main",
  "Refreshed N ago", a parse-error warning, **only the first line** of `last_error` when not `ok`, and
  ref @ SHA. A project with no stories gets a dashed empty state with a next step.
- `status-bar` (SB-9): a stacked bar by raw status, one segment per status sized by share. The kit's
  four statuses take their tokens; anything else (`in`, `done`, `(none)`) gets `bg-danger` and
  `data-tone="danger"`. It is `role="img"` with an `aria-label` summary. The project page's initiative
  rows reuse it (SB-10).
- `status-chip`: shows the raw status. Any value outside the vocabulary gets the danger tone. The
  optional `count` prop (SB-9) turns it into a tally chip ("draft 11"). The `errors` prop is coerced
  to an int, because an unpassed `errors` resolves to Laravel's shared `ViewErrorBag` (see
  [RUNBOOK](../RUNBOOK.md)).

**Request IDs.** `app/Http/Middleware/AssignRequestId.php` is prepended to every request in
`bootstrap/app.php`. It reuses an inbound `X-Request-Id` if it matches `[A-Za-z0-9._-]{1,64}`, and
otherwise makes a UUID. It puts the ID in Laravel `Context` and echoes it back as `X-Request-Id`.
`RefreshProjectJob` captures the ID when it is constructed and restores it in `handle()`, so a
refresh's log lines on the worker trace back to the page load or button press that queued it.

## Data model
- `stories.is_parked` (bool, default false) and `stories.dated_on` (date, nullable), added by
  `database/migrations/2026_09_29_000004_add_is_parked_and_dated_on_to_stories_table.php`. Both are
  written only by `RefreshProject::replaceSnapshot()`. A migrated row keeps the defaults until its
  project's next refresh.
- Story text is **not** stored. It is read from git at `stories.sha` on demand.
- The page reads `projects` and `stories` and writes nothing. The board is read-only by owner ruling.

## Interfaces
- `GET /` (route `home`) → `App\Livewire\Board\Home`. Query string: `?initiative=<name>&q=<text>`. A
  `?project=<name>` is redirected to `/p/<name>` by `RedirectProjectFilter` (SB-7).
- `GET /p/{project}` (route `projects.show`) → `ProjectPage` since SB-10, not this component; see
  [Single-project dashboard](single-project-dashboard.md).
- Livewire actions: `refresh`, `toggleSection('offmain'|'built'|'parked')`, `showAll(<group>)`,
  `clearFilters` (resets `initiative` and `q`). `?story=<project>/<ID>` belongs to the embedded modal.
- `ListWhatNeedsMe::handle(?project, ?initiative, ?search)` returns
  `{approval, pick, build, parked, built, offmain, projects, in_flight}`. Each `projects` entry carries
  `offmain`; `in_flight` is `{approved, offmain, not_ok: list<name>}`. `section('offmain'|'built'|'parked', …)` and
  `withId($id, ?project)` are also public, and (SB-10) `ordered($counts)`, the status order the
  project page's initiative bars share.
- `RenderStory::handle(Story): ?string` returns safe HTML, or `null` when git cannot read the file or
  the row is untracked.
  The view then says the story could not be read at that SHA.
- `GitReader::listFiles($path, $ref, $prefix): list<string>`.
- Every response carries an `X-Request-Id` header.
- Blade: `<x-board.status-bar :counts="[status => n]" :label="?string" />`,
  `<x-board.status-chip :status :errors="int" :count="?int" />`,
  `<x-board.story-row :story :group variant="list|card" />`, `<x-board.project-card :card />`.
- Test hooks: `data-row`, `data-pick-row`, `data-card` / `data-group` / `data-count` / `data-show-all`,
  `data-section`, `data-figure`, `data-refreshed`, `data-project-card` / `data-story-count` /
  `data-state` / `data-offmain` / `data-parse-errors`, `data-status-bar` / `data-segment` / `data-tone`,
  `data-status-chip`, `data-goto`.

## Configuration
- A **queue worker must run** for any refresh to happen. `composer run dev` (`php artisan dev`) starts
  `queue:listen` alongside the server. Under a bare `php artisan serve`, jobs pile up in `jobs` and
  every snapshot stays as it was.
- `PHP_CLI_SERVER_WORKERS=4` in `.env` / `.env.example`. With a single worker, a slow mockup iframe
  would block the page's own Livewire requests.
- `@tailwindcss/typography` (dev dependency, owner-approved) styles the rendered story text.
  `prose-h1:hidden` hides the story's `#` title because the modal header already shows it.
- Status colours are theme tokens in `resources/css/app.css` `@theme`: `--color-draft`, `-approved`,
  `-built`, `-cancelled`, `-pick`, `-danger`, `-warning`.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.home_viewed` | info | `Home::mount()` | `initiative`, `q` (the filters; `project` dropped in SB-9) |
| `board.refresh_requested` | info | `Home::refresh()` | `projects` (names queued) |
| `board.story_read_failed` | warning | `RenderStory` | `project`, `story`, `error` |
| `board.refresh_*` | see [registry doc](project-registry-and-reader.md#observability) | `RefreshProject` via `RefreshProjectJob` | now also `request_id` |

Every line carries `request_id` from `Context`. To trace one page load, take the `X-Request-Id`
response header and grep the log for it. A healthy stale page load logs `board.home_viewed`, and
then, on the worker, `board.refresh_started` followed by `board.refresh_finished` with the same
`request_id`. If you see `home_viewed` and no `refresh_started`, either the worker is not running or
a unique job for that project is already queued (`uniqueFor` 300 s). Repeated
`board.refresh_skipped` means an earlier refresh still holds the per-project lock. That lock can
outlive a killed process until its TTL runs out, and that is expected.

## Testing & verification
- `tests/Feature/Board/AllProjectsDashboardTest.php` (SB-9) has one `it()` per acceptance criterion,
  on a fixture shaped like the 2026-09-29 dev data: "What needs me" as the first heading, Ready to
  build's top 5 and "Show all", the "Nothing to approve" empty state, rent-track's danger segments and
  parse-error warning, an unreachable tile and the not-ok figure, a disabled project counted nowhere,
  rows opening the modal, and a **query-count test** that adds projects and asserts the count does not
  move. Extra `it()`s cover the tile's not-on-main count and link, the In flight figures, the empty
  tile, the header's refresh age, the removed project dropdown, the absent Live now slot, and
  `/p/{project}` scoping its cards and showing no tiles (SB-10).
- `tests/Browser/AllProjectsDashboardTest.php` (SB-9) clicks a row in each card to open the modal, and
  checks the cards sit side by side at 1280px, stack at 375px, and that a tile leads to `/p/rent-track`.
- `tests/Feature/Board/HomePageTest.php` has one `it()` per SB-3 acceptance criterion: parked drafts
  hidden, a pick row leaving once `Chosen option` is on the ref, oldest-first build order, the
  `/?project=` redirect, exact ID search, empty states, an unreachable card, parse-error warning plus raw
  status. It also covers the gate rulings (closed sections, row cap,
  ID search with no visible group), queued refresh on load and on the button, one job per project,
  request-ID propagation, `home_viewed` logging and the locked properties.
- `tests/Feature/Board/WhatNeedsMeTest.php` tests `ListWhatNeedsMe` directly: each group, the
  filters, exact vs text search, disabled projects, project summaries.
- `tests/Feature/Board/RefreshProjectsTest.php` covers `is_parked` and `dated_on` at refresh.
- `tests/Browser/BoardListTest.php` smoke-tests `/` in a real browser.
- Browser check, 2026-09-29, on the four real projects: coins' Ready to build matched
  `git grep -h '^Status: approved' origin/main -- 'stories/**/*.md' | wc -l`. Screenshots at 1280
  and 375 px, light and dark, were clean. MOB-65 expanded with its text and both coins mockup
  thumbnails. The filter survived a reload, and a `MOB-65` search returned exactly that story.
- SB-9 real-data check, 2026-09-29: Ready to build 30 (coins 29, client-dashboard 1); rent-track
  `{draft: 11, in: 1, done: 3}` with red `in`/`done` segments and 15 parse errors; not on main coins
  262 and client-dashboard 28. Awaiting a pick showed 9 (6 of them bold picks misread by the kit
  parser, F-1) and Drafts to approve 56.

## Key decisions & tradeoffs
- Refresh runs on the queue, unique per project, instead of after the response →
  [ADR-006](../decisions/ADR-006-refresh-runs-on-the-queue.md) (supersedes ADR-005 §1).
- Story text is read from git on demand, not stored →
  [ADR-007](../decisions/ADR-007-story-text-read-on-expand.md). Since SB-8 the demand is the story
  modal, not an expanded row.
- **Parked** means the initiative README's `Status:` starts `draft group`, not merely that a README
  exists (owner ruling at the gate). It is computed at refresh and stored, so the lists stay a plain
  indexed query.
- **Awaiting a pick** covers draft and approved only. Its accuracy depends on the kit parser
  (F-1). The board is deliberately not patched to work around it (owner ruling).
- **Not on main** reuses the collapsible section, is closed by default, and never counts in the three
  groups, because the ref stays the truth for status (SB-5).
- Rows open the story modal (SB-8), which replaced SB-3's expand in place. Built and Parked
  drafts are closed by default and load their rows only when opened. Cancelled stories show up only
  through an ID search.
- Status colours are semantic theme tokens, so re-theming happens in `app.css` and not in the views.
- The dashboard leads with What needs me (owner ruling; design A's h1 "All projects" became the
  subtitle), and the project filter was removed, not hidden →
  [ADR-017](../decisions/ADR-017-dashboard-leads-with-what-needs-me-and-drops-the-project-filter.md).
- Tiles and In flight ignore the initiative filter and search, add no query, and the header shows the
  stalest snapshot's age →
  [ADR-018](../decisions/ADR-018-portfolio-figures-ignore-filters-and-add-no-query.md).
- Card rows show the `Source:` date as "N ago", not the mockup's "approved N days ago": approval dates
  are not stored. Card order follows SB-3's rules (Ready to build oldest first), not the mockup's
  newest-first hint.

## Known limitations & gotchas
- **"Awaiting a pick" over-reports** until the kit fix F-1 lands. `Chosen option: **B**` parses
  as no pick. On 2026-09-29 the card showed 9, of which 6 were already picked.
- **Filters do not move the tiles or In flight.** With an initiative selected, the cards shrink and
  the figures below do not. That is intended (ADR-018), not a stale render.
- **"Refreshed N ago" in the header is the oldest snapshot**, so one stuck project makes the whole
  page read old. Check the tiles to find which one.
- Blade: `@endif@if` written back to back does not compile. A directive needs a non-word character
  before its `@`, so put them on separate lines (as `status-chip` now does).
- **No worker, no refresh.** See Configuration.
- **A migrated but unrefreshed row** reads `is_parked=false` and `dated_on=null`, so a parked draft
  shows under Drafts to approve until the next refresh.
- Layout classes still use the raw `zinc-*` palette. Only the status colours are tokens (preflight
  WARN, deferred).

## Change history
2026-09-29 — Snapshot facts `is_parked` and `dated_on`, `ListWhatNeedsMe`, `GitReader::listFiles` (SB-3, data layer, `a1874aa`)
2026-09-29 — Livewire home replaces SB-2's bare list. Expand in place, Built/Parked sections, project cards, URL filters, queued refresh, request IDs (SB-3)
2026-09-29 — Refresh confirmation, `SECTION_PAGE`, `expand()` and the initiative list limited to enabled projects, cards show the project's ref, story links resolve (SB-4)
2026-09-29 — Not on main section (off-main rows, location labels, mockups-only tag, `?v=` links); Built hint now says "on each project's ref" (SB-5)
2026-09-29 — Rendered in the sidebar shell; `/p/{project}` reuses the component pinned to one project; `?project=` redirects there (SB-7)
2026-09-29 — Rows open the story modal; `expand()` and `bodies` removed (SB-8, `f601c01`)
2026-09-29 — All-projects dashboard: What needs me cards (top 5), In flight, project tiles with `status-bar`, header refresh age; project filter removed; query-count test (SB-9, `a26153b`)
2026-09-29 — `/p/{project}` moved to `ProjectPage`; `$pinned` removed; cards extracted to `board/needs-me-cards` (SB-10, `a062331`)
