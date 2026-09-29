# App shell and project switcher
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-7, SB-10

## Overview
Every board page sits in one frame: a left sidebar that lists the enabled projects and switches
between the all-projects view (`/`) and a single project's view (`/p/{project}`). Before this, the
only way to change project was the `project` dropdown in the home page's filter bar. That stops
working as the number of projects grows. The frame is design A from `docs/mockups/SB-7/option-a.html`
(owner pick). SB-8 to SB-10 filled it in; SB-12 switches on the Manage slot.

## How it works
**The layout.** `resources/views/layouts/board.blade.php` wraps every board page in
`x-data="{ open: false }"`. It renders `<x-board.sidebar />` and then the page in a `md:pl-64` column.
`open` is the mobile drawer's state. The layout owns it so that three parts can share it: the
sidebar's menu button, its backdrop and the `<aside>` itself.

**The sidebar.** `app/View/Components/Board/Sidebar.php:render()` makes one query:
`Project::enabled()->withCount(stories on the ref)->orderBy('name')`. It works out the current entry
from the route, so no page has to pass it in:
- `current` is the route's `project` parameter. It can be a bound `Project` or a plain string.
- `onHome` is `routeIs('home')`.
- `manageUrl` is set only when `Route::has('projects.manage')` (SB-12's route name). Until then the
  Manage projects slot is not rendered.

`resources/views/components/board/sidebar.blade.php` renders three things:
- A mobile top bar (`md:hidden`) with the menu button (`aria-label="Open projects"`).
- A backdrop.
- The `<aside id="board-sidebar">`, which holds the brand link, the filter box, "All projects"
  with the total count, one link per project, the empty state and the Manage slot.

Each project link shows a state dot, its name, a screen-reader-only state word and the story count.
The dot classes map `ok|pending|stale|unreachable` to `bg-ok|bg-pending|bg-warning|bg-danger`, and
any unknown state falls back to danger. The current link gets `aria-current="page"`. Every link uses
`wire:navigate`.

**All toggling is Alpine.** The filter text `q` is local to the `<aside>`. Each `<li>` is
`x-show`n when its lower-cased name contains `q`, and a "No project matches" line appears when
nothing does. ⌘K / Ctrl+K (window listeners) opens the drawer and focuses the box. Escape, the close
button and the backdrop close it. `x-trap.noscroll="open"` holds focus in the open drawer.

**The drawer below 768px.** The `<aside>` is `hidden md:flex data-open:flex`. A closed drawer is
`display:none`, not just off-screen, so screen readers and Playwright's `isVisible` treat it as
gone. The slide-in is a CSS animation on `.board-drawer[data-open]` in `resources/css/app.css`,
switched off under `prefers-reduced-motion`.

**`/p/{project}`, the single-project page.** `routes/web.php` names the route `projects.show`. Since
SB-10 it mounts `App\Livewire\Board\ProjectPage`, whose `mount()` logs `board.project_viewed`; see
[Single-project dashboard](single-project-dashboard.md). SB-7 served it from `Home` pinned to the
project; that mode and `Home`'s `$pinned` are gone
([ADR-019](../decisions/ADR-019-project-page-is-its-own-component.md), superseding ADR-014). The
sidebar is the only project switcher; `/` has no project filter since SB-9.

**One refusal for every project URL.** `app/Actions/Board/CheckProjectShown.php:refusal($name)` returns
`unknown`, `disabled` or null. Two middlewares call it:
- `app/Http/Middleware/EnsureProjectIsShown.php` is attached to `projects.show` and `stories.show`.
  For a refused name it logs `board.project_page_refused` and aborts 404. `bootstrap/app.php` puts it
  **ahead of `SubstituteBindings`** in the priority list. Without that, Livewire's `{project:name}`
  binding would 404 an unknown name before the guard ever ran
  ([ADR-013](../decisions/ADR-013-project-refusal-runs-before-route-binding.md)). As a result,
  `StoryPage::mount()` no longer has its own disabled-project branch.
- `app/Http/Middleware/RedirectProjectFilter.php` is on `home`. It sends the old filter URL
  `/?project=coins` to `/p/coins` with a **301** and keeps the other query parameters. A name it
  refuses gets a **302 to `/`** instead, because the link is old rather than wrong and the name could
  become valid later. Either way it logs `board.project_filter_redirected`.

`mockups.file` is not behind the middleware. `MockupFileController` still refuses a disabled project
itself (see [Story page and mockups](story-page-and-mockups.md)).

## Data model
None owned. Reads `projects` (`name`, `state`, `is_enabled`) and counts `stories` on the ref
(`location_kind IS NULL`). No migrations.

## Interfaces
- `GET /p/{project:name}` (`projects.show`) → `ProjectPage` (SB-10). 404 for an unknown or
  disabled project.
- `GET /?project=<name>[&…]` → 301 to `/p/<name>[?…]`, or 302 to `/` if the name is refused.
- `CheckProjectShown::refusal(string): 'unknown'|'disabled'|null`. Constants `UNKNOWN` and `DISABLED`.
- `<x-board.sidebar />` takes no props. It reads the current route.
- Theme tokens `--color-ok` and `--color-pending` (in `resources/css/app.css`). `stale` and
  `unreachable` reuse `--color-warning` and `--color-danger`.
- Test hooks: `data-sidebar-all`, `data-sidebar-project="<name>"`, `data-story-count`,
  `data-state-dot="<state>"`.

## Configuration
None. The Manage projects slot is switched on by the existence of the `projects.manage` route, not by
a flag.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.project_viewed` | info | `ProjectPage::mount()` (SB-10; `Home::mount()` before) | `project` |
| `board.project_page_refused` | info | `EnsureProjectIsShown` | `project`, `reason` (`unknown` / `disabled`) |
| `board.project_filter_redirected` | info | `RedirectProjectFilter`, refused names only | `project`, `reason`, `to` (`home`) |

Every line carries `request_id` (see the [home doc](what-needs-me-home.md#observability)). If a
`/p/...` 404 has no `board.project_page_refused` line, the guard ran after route-model binding again:
check the `prependToPriorityList` call in `bootstrap/app.php` (see the RUNBOOK). A story page for a
disabled project now logs `project_page_refused`, not `board.story_not_found`.

## Testing & verification
- `tests/Feature/Board/AppShellTest.php` has one `it()` per acceptance criterion. It also covers the
  story route refused in the same place, redirects that keep other filters, a disabled project's old
  URL going to `/`, pinning that survives the query string and Clear filters, the empty sidebar, and
  the Manage slot staying hidden. The pinning test now checks `/p/coins` ignores `?project=` and has
  no project dropdown (SB-10).
- `tests/Browser/AppShellTest.php`: at 375px the drawer is hidden, the menu button opens it and
  Escape closes it. At desktop width, clicking a project switches to its page and typing filters the
  list.
- `tests/Feature/Board/GuardLoggingTest.php` now expects `board.project_page_refused` for a disabled
  project's story page.
- Browser check (story): at 1280px click coins and see `/p/coins` highlighted, then type "cli" and see
  only client-dashboard. At 375px, open and close the drawer with the button and with Escape.

## Key decisions & tradeoffs
- The refusal is a middleware that runs before route binding, not a `Route::bind`, `->missing()` or
  `resolveRouteBinding` override → [ADR-013](../decisions/ADR-013-project-refusal-runs-before-route-binding.md).
- `/p/{project}` reused `Home` with a locked `pinned` until SB-10 (ADR-014); it is now its own
  `ProjectPage` → [ADR-019](../decisions/ADR-019-project-page-is-its-own-component.md).
- The sidebar works out "current" from the route rather than having each page pass it in, so new
  pages under `/p/{project}` get it for free.
- Deviations from the mockup, both deliberate: the drawer breakpoint is `md` (768px), as the story
  says, not the mockup's `lg`. The box reads "Filter projects", not "Find a story", because the story
  rules out story search.
- `board.project_filter_redirected` is an extra event the story did not name. It was added under L-5
  so that every refusing branch logs.

## Known limitations & gotchas
- The sidebar re-queries projects and counts on every full page load, `wire:navigate` included.
  Counts are live as of the last refresh, not the moment.
- ⌘K / Ctrl+K is captured window-wide on every board page, so the browser's own shortcut for that
  key is not available there.

## Change history
2026-09-29 — Sidebar shell, `/p/{project}`, `/?project=` redirect, one project refusal before binding (SB-7, `d5867f6`)
2026-09-29 — `projects.show` points at the new `ProjectPage`; `Home`'s pinned mode removed (SB-10, `a062331`)
