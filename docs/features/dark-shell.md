# Dark shell and sidebar
Status: active   ·   Last updated: 2026-10-11   ·   Stories: RD-2

## Overview
The redesign's command-center layout (Home A, [`docs/mockups/RD-2/option-a.html`](../mockups/RD-2/option-a.html)):
a graphite ground, Geist type, and a sidebar holding the logo, Home, Mockups with its awaiting-pick count, and
every enabled project with a state dot and story-ID prefix. Every later RD page is built inside it. The
Preflight page is its first user. RD-2 also moved refresh-on-load out of the home and project pages and into a
middleware on all board page routes, so pages added later get it without doing anything.

## How it works
**Layout.** `resources/views/layouts/shell.blade.php` puts `class="shell"` on `<html>` and includes
`partials/head` with `shell => true`. That flag skips `@fonts` (Instrument Sans) and `@fluxAppearance`, so
`.dark` is never set inside the shell and there is no theme switch. The body is `bg-ground font-sans text-ink`.
`x-data="{ open: false }"` holds the mobile drawer's state, which the sidebar's menu button, backdrop and aside
all share. The page slot sits in `md:pl-58` beside the fixed sidebar. A page opts in with
`#[Layout('layouts.shell')]` (currently `app/Livewire/Board/ProjectPreflight.php` and, since RD-3,
`StoryPage.php`). Every other page still uses `layouts/board` until RD-4 moves it.

**Tokens.** `resources/css/app.css` adds the Home A palette to `@theme`: `ground / surface / raised` surfaces,
`hairline / edge / rim` borders, `ink / ink-soft / ink-muted / ink-faint` text, and the signals `needs`
(amber), `live` (blue), `go` (green) and `fail` (red), plus `--font-geist` and `--font-geist-mono`. An
`@layer theme` `.shell { … }` block re-points the shared semantic tokens inside the shell (`ok→go`,
`warning→needs`, `danger→fail`, `pending→ink-muted`, `series→live`, `accent→needs`, and `--font-sans` /
`--font-mono` to Geist). Components that both old and new pages use, such as state dots and charts, therefore
recolour with no per-view override. A base rule makes unclassed borders inside `.shell` `edge`, not the light
theme's gray. ([ADR-040](../decisions/ADR-040-dark-shell-is-scoped-by-a-shell-class-and-variant.md))

**The `shell:` variant.** `@custom-variant shell (&:where(.shell, .shell *))`. Shared Blade components that the
old light pages still render carry their dark look as `shell:` classes beside the existing `dark:` ones:
- `components/board/project-header.blade.php` shows muted text, and its light/dark toggle is `shell:hidden`.
- `components/board/project-tabs.blade.php` uses the hairline border, and its current/other tab tones come from `ink`.
- `components/board/state.blade.php` gives the `pending` label `shell:text-ink-muted`. The dot colours need no
  variant, because the token re-pointing above handles them.

`wire:navigate` replaces `<html>` attributes on each page swap, so moving between a shell page and an old page
adds or removes `.shell` correctly in both directions.

**Fonts.** Geist and Geist Mono come from the `@fontsource-variable/geist` and `…/geist-mono` npm packages
(devDependencies). `app.css` declares its own `@font-face` for each one, pointing at the package's
`*-latin-wght-normal.woff2`, so the family names stay `Geist` / `Geist Mono`. Vite copies the files into the
build, and no page asks a font CDN at run time.
([ADR-039](../decisions/ADR-039-geist-fonts-self-hosted-from-fontsource.md))

**Sidebar.** `app/View/Components/Shell/Sidebar.php:render()` builds the data, and
`resources/views/components/shell/sidebar.blade.php` draws it:
- **Projects.** `Project::enabled()` ordered by name. Each one links to `projects.stories` and shows
  `<x-board.state>` as its dot. With none, the list reads "No projects yet." with an "Add a project" link to
  `projects.manage`.
- **ID prefix.** `prefixes()` runs one grouped query over on-ref stories:
  `SUBSTRING_INDEX(story_id, '-', 1)` with a count per project, most-used first and ties A–Z. The first row
  for each project is its prefix, so story-board shows `SB` even though it also holds RD stories. A project
  with no story IDs shows no prefix. The mockup's "CZ" for cozmic-site is derived from story IDs, not from
  the project name's initials.
- **Mockups count.** `ReadMockupSets::awaitingCount()`, which is cached per project snapshot. A count of zero
  renders no badge.
- **Current entry.** This is read from the route, not passed in by the page. The `project` route parameter
  (a model or a name) marks that project's entry. `routeIs('home')` marks Home, and
  `routeIs('mockups', 'mockups.show')` marks Mockups. The current entry gets `aria-current="page"` and
  `bg-raised`.
- **Logo.** `components/shell/logo.blade.php` holds the mark and "Story Board", linking home. It appears in
  both the aside and the mobile top bar.

**Drawer (< 768 px).** The design follows design-standards: Alpine only, no server round-trip. A sticky top bar
(`md:hidden`) holds the menu button (`data-shell-menu`). The aside (`#shell-sidebar`, `data-shell-sidebar`) is
`hidden` until `data-open`. It reuses the existing `.board-drawer` slide-in CSS, uses `x-trap.noscroll="open"`,
and closes on Escape, on its close button (`data-shell-close`) or on a backdrop click. Every target is at least
44 px (`min-h-11` / `size-11`), and focus-visible draws an amber outline.

**Refresh on load.** `app/Http/Middleware/RefreshStaleProjects.php:handle()` dispatches one `RefreshProjectJob`
for each enabled project whose `needsRefresh()` is true, logs `board.stale_refresh_dispatched` for each, and then
serves the page. It is applied as a route group in `routes/web.php` around every Livewire page route (`/`,
`/p/{project}` and its sub-pages, the story page, `/projects`, `/prod`, `/mockups`, the mockup viewer). The file
routes (`shots.file`, `mockups.file`, `mockups.frame`) are outside the group on purpose. A page's frames and
images hit them many times per load. Livewire update requests do not run route middleware, so only full page
loads trigger a refresh. The old dispatches in `Home::mount()` and `ProjectPage::mount()` were removed.
([ADR-041](../decisions/ADR-041-refresh-on-load-is-one-middleware-on-the-page-routes.md))

## Data model
No tables or migrations. The sidebar reads `projects` (`id, name, state`) and `stories` (`project_id`,
`story_id`, on-ref rows only).

## Interfaces
- `layouts/shell` is a Livewire page layout with no props. Use it via `#[Layout('layouts.shell')]`.
- `<x-shell.sidebar />` is a class component with no props. It reads the current route itself.
- `<x-shell.logo />` takes no props.
- `RefreshStaleProjects` is route middleware with no parameters.
- Test hooks: `data-shell-sidebar`, `data-shell-menu`, `data-shell-close`, `data-shell-no-projects`,
  `data-nav="home|mockups"`, `data-nav-project="<name>"`, `data-mockups-count="<n>"`, `data-id-prefix`. These
  names were chosen so they don't collide with the old `board/sidebar`'s hooks, which RD-9 deletes along with
  `layouts/board`.

## Configuration
None. The fonts are npm devDependencies. After pulling, run `npm install` and rebuild (`npm run build`, or let
`composer run dev` start Vite).

## Observability
- `board.stale_refresh_dispatched` (info, `{project}`) is logged once for each job the middleware queues. The
  job's own `board.refresh_started` / `board.refresh_finished` / `board.refresh_skipped` events are unchanged,
  and the request ID set by `AssignRequestId` links each job back to the page load that queued it.
- Healthy: a page load with stale projects logs one `board.stale_refresh_dispatched` per stale project, and a
  `board.refresh_started` follows soon after from the worker. A fresh board logs none.
- Failing: the dispatch is logged but `refresh_started` never follows, which means no queue worker is running
  (see RUNBOOK "Page hangs 8–50 s"). If no dispatch is logged on a page you expected to trigger one, check that
  its route is inside the `RefreshStaleProjects` group in `routes/web.php`.

## Testing & verification
- `tests/Feature/Board/DarkShellTest.php` has one `it()` per acceptance criterion. The refresh criterion runs
  against a dataset of page URLs (shell and old pages alike), asserting exactly one job and one log line. It is
  joined by extras for "fresh and disabled projects queue nothing", the ID prefix (most-used and none), and
  dark-only rendering (no Google/Bunny fonts, no `flux.appearance`). This closes RD-1's gap: refresh-on-load is
  now tested at the middleware, not only through pages RD-9 deletes.
- `tests/Browser/DarkShellTest.php` checks the 375 px drawer (open, close button, Escape, a menu button of at
  least 44 px, no sideways scroll) and the 1280 px column (current project marked, body computed in Geist,
  `document.fonts.check('16px Geist')`).
- `ProjectPageTest` "queues one refresh per stale project when the page loads" now expects every stale project
  to be refreshed, not just the page's own.
- Browser check: `/p/story-board/preflight` at 1440 and 375 px against the Home A board.

## Key decisions & tradeoffs
- Fonts come from fontsource variable packages, not Vercel's `geist` package, which drags in `next` as a peer
  dependency ([ADR-039](../decisions/ADR-039-geist-fonts-self-hosted-from-fontsource.md)).
- The dark styling is scoped by `.shell` plus a `shell:` variant, rather than by forking the shared components
  ([ADR-040](../decisions/ADR-040-dark-shell-is-scoped-by-a-shell-class-and-variant.md)).
- Refresh-on-load is one middleware on the page-route group
  ([ADR-041](../decisions/ADR-041-refresh-on-load-is-one-middleware-on-the-page-routes.md)).
- `ink-faint` is `#8a8d93`, not the mockup's `#7E8187`. The mockup value is 4.19:1 on `raised` (the current
  nav item's background). The new value is 5.76 / 5.35 / 4.91:1 on ground / surface / raised. Every other text
  token is at least 6.02:1, and ink on amber is 8.9:1.
- The new names (`layouts/shell`, `x-shell.*`, `data-nav*`, `data-shell-*`) sit beside the old `layouts/board`
  and `board/sidebar` rather than replacing them, because the old pages still need those until RD-9.

## Known limitations & gotchas
- Two shells exist until RD-4/RD-9. `layouts/board` (light/dark, Flux, filterable sidebar, live badge) still
  serves every page except Preflight. A fix to sidebar behaviour may need to land in both.
- The shell sidebar does not yet include the old one's filter box, Production item, per-project counts or live
  badge. Brain and Money items are later phases.
- `preflight-history.js` now uses shell-only token classes (`stroke-hairline`, `fill-ink-muted`, …). The charts
  would look wrong if that page ever went back to a light layout.
- Only the latin subset of Geist is loaded. Text outside latin falls back to the system font.
- A project page now refreshes every stale enabled project, not just its own. This is a behaviour change from
  SB-10.
- Installing npm packages in a worktree rewrites the `package-lock.json` name (RUNBOOK, 2026-10-11).

## Change history
2026-10-11 — Dark shell layout, Home A tokens, Geist fonts, shell sidebar and drawer, `RefreshStaleProjects`
middleware. The Preflight page is the first page moved in (RD-2, `43b01ed`)
