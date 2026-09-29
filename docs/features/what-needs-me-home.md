# What needs me (home page)
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-3, SB-4, SB-5, SB-7

## Overview
`/` answers one question across every registered project: what is waiting on the owner? It lists
drafts to approve, mockups to pick and approved stories to build. Below those it has collapsible
Not on main, Built and Parked drafts sections and a health card per project. The owner chose this over
per-project counts or a kanban (mockup A, "Inbox"), because the board exists to make decisions, not to
report. The lists read only the stored snapshot (see
[Project registry and story reader](project-registry-and-reader.md)). Git is touched only when a row
is expanded to show the story text.

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

**The query.** `app/Actions/Board/ListWhatNeedsMe.php:handle()` builds every group from the same
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
- **projects**: one card per enabled project. It holds counts by *raw* status (so an
  out-of-vocabulary value stays visible), ordered draft/approved/built/cancelled and then A–Z, plus
  the number of stories with parse errors. Two grouped queries cover all projects.

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
- `project`, `initiative` and `q` are `#[Url]` properties, so filters survive a reload and can be
  shared as a link.
- `toggleSection()` opens Not on main, Built or Parked drafts (`Home::SECTIONS`). `showAll()` lifts a group's row cap: `Home::PAGE`
  for the three groups and `Home::SECTION_PAGE` for the sections.
- `expand($id)` renders a story's text on the first open of its row, only for a story whose project
  is enabled, through
  `app/Actions/Board/RenderStory.php`. That class reads the file with `GitReader::show()` at the
  row's `sha` and converts it with `Str::markdown` (`html_input=escape`,
  `allow_unsafe_links=false`). Opening and closing a row is Alpine state (`x-data="{ open }"` in
  `story-row`), so it costs no round trip after the first open. See
  [ADR-007](../decisions/ADR-007-story-text-read-on-expand.md).
- `bodies`, `openSections`, `expandedGroups` and `notice` are `#[Locked]`. Livewire public properties can
  otherwise be set from the browser, and `bodies` is printed raw (`{!! !!}`).

**The view.** `resources/views/livewire/board/home.blade.php` uses the `layouts/board` shell (the SB-7 project
sidebar, no auth, Flux appearance for light/dark). The same component also serves `/p/{project}`,
pinned to one project; see [App shell and project switcher](app-shell-and-project-switcher.md). It has a filter bar, the three groups, the three
collapsible sections (Not on main, Built, Parked drafts) and the project cards. The Blade components are in
`resources/views/components/board/`:
- `section`: a boxed list with a heading, count and hint. Optionally collapsible.
- `story-row`: one row that expands in place to chips, the story text and mockup thumbnails. A
  thumbnail is a sandboxed `<iframe>` of the SB-4 route `mockups.file`, created only while the row is
  open (`<template x-if="open">`). "Open full page →" links to the story page (with `?v=` for an
  off-main row, and only when `Story::hasPage()`). For an off-main row (SB-5), the row also shows its
  `location` and either a status chip or a "mockups only" tag, plus "picked X". The row's thumbnails
  come from `Story::mockupUrl()`. An untracked row shows no text or thumbnails and says where the file is. The `.mockup-thumb` class in `resources/css/app.css` renders it at
  1280×800 and scales it down.
- `project-card`: status bar and counts, a parse-error warning, stale/unreachable/pending state,
  `last_error`, the project's own ref @ SHA, and "indexed ago".
- `status-chip`: shows the raw status. Any value outside the vocabulary gets the danger tone.

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
- `GET /p/{project}` (route `projects.show`) → the same component, pinned (SB-7).
- Livewire actions: `refresh`, `toggleSection('offmain'|'built'|'parked')`, `showAll(<group>)`,
  `expand(<stories.id>)`, `clearFilters`.
- `ListWhatNeedsMe::handle(?project, ?initiative, ?search)` returns
  `{approval, pick, build, parked, built, offmain, projects}`. `section('offmain'|'built'|'parked', …)` and
  `withId($id, ?project)` are also public.
- `RenderStory::handle(Story): ?string` returns safe HTML, or `null` when git cannot read the file or
  the row is untracked.
  The view then says the story could not be read at that SHA.
- `GitReader::listFiles($path, $ref, $prefix): list<string>`.
- Every response carries an `X-Request-Id` header.
- Test hooks: `data-row`, `data-pick-row`, `data-group` / `data-section`, `data-project-card` / `data-story-count`,
  `data-status-chip`, `data-goto`.

## Configuration
- A **queue worker must run** for any refresh to happen. `composer run dev` (`php artisan dev`) starts
  `queue:listen` alongside the server. Under a bare `php artisan serve`, jobs pile up in `jobs` and
  every snapshot stays as it was.
- `PHP_CLI_SERVER_WORKERS=4` in `.env` / `.env.example`. With a single worker, a slow mockup iframe
  would block the page's own Livewire requests.
- `@tailwindcss/typography` (dev dependency, owner-approved) styles the rendered story text.
  `prose-h1:hidden` hides the story's `#` title because the row already shows it.
- Status colours are theme tokens in `resources/css/app.css` `@theme`: `--color-draft`, `-approved`,
  `-built`, `-cancelled`, `-pick`, `-danger`, `-warning`.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.home_viewed` | info | `Home::mount()` | `project`, `initiative`, `q` (the filters) |
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
- `tests/Feature/Board/HomePageTest.php` has one `it()` per acceptance criterion: parked drafts
  hidden, a pick row leaving once `Chosen option` is on the ref, oldest-first build order, the URL
  project filter, exact ID search, empty states, an unreachable card, parse-error warning plus raw
  status. It also covers the gate rulings (closed sections, row cap, expand with text and mockups,
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

## Key decisions & tradeoffs
- Refresh runs on the queue, unique per project, instead of after the response →
  [ADR-006](../decisions/ADR-006-refresh-runs-on-the-queue.md) (supersedes ADR-005 §1).
- Story text is read from git on first expand, not stored →
  [ADR-007](../decisions/ADR-007-story-text-read-on-expand.md).
- **Parked** means the initiative README's `Status:` starts `draft group`, not merely that a README
  exists (owner ruling at the gate). It is computed at refresh and stored, so the lists stay a plain
  indexed query.
- **Awaiting a mockup pick** covers draft and approved only. Its accuracy depends on the kit parser
  (F-1). The board is deliberately not patched to work around it (owner ruling).
- **Not on main** reuses the collapsible section, is closed by default, and never counts in the three
  groups, because the ref stays the truth for status (SB-5).
- Rows expand in place with several open at once, per the owner's pick of mockup A. Built and Parked
  drafts are closed by default and load their rows only when opened. Cancelled stories show up only
  through an ID search.
- Status colours are semantic theme tokens, so re-theming happens in `app.css` and not in the views.

## Known limitations & gotchas
- **"Awaiting a mockup pick" over-reports** until the kit fix F-1 lands. `Chosen option: **B**` parses
  as no pick. On 2026-09-29 this showed 6 stories that were already picked.
- **No worker, no refresh.** See Configuration.
- **A migrated but unrefreshed row** reads `is_parked=false` and `dated_on=null`, so a parked draft
  shows under Awaiting approval until the next refresh.
- Layout classes still use the raw `zinc-*` palette. Only the status colours are tokens (preflight
  WARN, deferred).

## Change history
2026-09-29 — Snapshot facts `is_parked` and `dated_on`, `ListWhatNeedsMe`, `GitReader::listFiles` (SB-3, data layer, `a1874aa`)
2026-09-29 — Livewire home replaces SB-2's bare list. Expand in place, Built/Parked sections, project cards, URL filters, queued refresh, request IDs (SB-3)
2026-09-29 — Refresh confirmation, `SECTION_PAGE`, `expand()` and the initiative list limited to enabled projects, cards show the project's ref, story links resolve (SB-4)
2026-09-29 — Not on main section (off-main rows, location labels, mockups-only tag, `?v=` links); Built hint now says "on each project's ref" (SB-5)
2026-09-29 — Rendered in the sidebar shell; `/p/{project}` reuses the component pinned to one project; `?project=` redirects there (SB-7)
