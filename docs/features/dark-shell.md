# Dark shell and sidebar
Status: active   ·   Last updated: 2026-10-11   ·   Stories: RD-2, RD-4

## Overview
The redesign's command-center layout (Home A, [`docs/mockups/RD-2/option-a.html`](../mockups/RD-2/option-a.html)):
a graphite ground, Geist type, and a sidebar holding the logo, Home, Mockups with its awaiting-pick count, and
every enabled project with a state dot and story-ID prefix. Every later RD page is built inside it. The
Preflight page was its first user; RD-4 moved the pages the owner kept (Stories, Handbook, Mockup gallery and
viewer, Manage projects, App map) in, restyled with its tokens and with their layouts unchanged. RD-2 also moved refresh-on-load out of the home and project pages and into a
middleware on all board page routes, so pages added later get it without doing anything.

## How it works
**Layout.** `resources/views/layouts/shell.blade.php` puts `class="shell"` on `<html>` and includes
`partials/head` with `shell => true`. That flag skips `@fonts` (Instrument Sans) and `@fluxAppearance`, so
the shell never sets `.dark` itself and there is no theme switch. In its place `partials/head` defines a
stub `window.Flux.applyAppearance()` that only strips `.dark` from a `.shell` page (see Toasts below). The body is `bg-ground font-sans text-ink`.
`x-data="{ open: false }"` holds the mobile drawer's state, which the sidebar's menu button, backdrop and aside
all share. The page slot sits in `md:pl-58` beside the fixed sidebar. A page opts in with
`#[Layout('layouts.shell')]`. Since RD-4 that is every page except `Home`, `ProjectPage` (the old `/p/{project}`
dashboard) and `ProductionDashboard`, which stay on `layouts/board` until RD-5/RD-9: `ProjectPreflight` (RD-2),
`StoryPage` (RD-3), and `ProjectStories`, `ProjectHandbook`, `MockupGallery`, `MockupViewer`, `ManageProjects`,
`ProjectAppMap` (RD-4), all in `app/Livewire/Board/`.
([ADR-044](../decisions/ADR-044-kept-pages-move-into-the-shell-and-share-primitives-by-variant.md))

**Toasts (RD-4).** Manage projects, its lazy `ProductionSettings` panels and the mockup viewer confirm with
`Flux::toast`, so `layouts/shell.blade.php` now carries `<flux:toast.group>` inside `@persist('toast')` (a toast
raised just before a `wire:navigate` reload survives it) plus `@fluxScripts`. Flux styles its toast for light and
for a `.dark` ancestor, and the shell never puts `.dark` on `<html>`, so the group is wrapped in `<div class="dark">`
to get the dark toast. Loading `flux.js` brings one hazard: it captures `window.Flux.applyAppearance` once, on the
first full page load, and calls it on load and after every `wire:navigate` swap; with none defined it deletes the
light/dark choice the old pages saved. The stub in `resources/views/partials/head.blade.php` is defined first and
only removes `.dark` from a `.shell` page, leaving the saved choice for the old pages' own appearance script. It
only runs when a session starts with a full load of a shell page; a session that starts on an old page keeps the
real function, which can add `.dark` inside the shell (hence `.shell.dark` in `app.css`).
([ADR-045](../decisions/ADR-045-flux-toasts-in-the-shell-behind-an-appearance-stub.md))

**Tokens.** `resources/css/app.css` adds the Home A palette to `@theme`: `ground / surface / raised` surfaces,
`hairline / edge / rim` borders, `ink / ink-soft / ink-muted / ink-faint` text, and the signals `needs`
(amber), `live` (blue), `go` (green) and `fail` (red), plus `--font-geist` and `--font-geist-mono`. An
`@layer theme` `.shell, .shell.dark { … }` block re-points the shared semantic tokens inside the shell (`ok→go`,
`warning→needs`, `danger→fail`, `pending→ink-muted`, `series→live`, `accent→needs`, `gain→go`, `loss→fail` (RD-4, for the production panels), and `--font-sans` /
`--font-mono` to Geist). Components that both old and new pages use, such as state dots and charts, therefore
recolour with no per-view override. The `.shell.dark` selector (RD-4) makes the
block win even when an old page's appearance script has left `.dark` on `<html>` across a `wire:navigate` swap.
`.shell .map-page` redraws the app map's placeholder wireframe in `surface` / `raised` / `rim`. A base rule makes unclassed borders inside `.shell` `edge`, not the light
theme's gray. ([ADR-040](../decisions/ADR-040-dark-shell-is-scoped-by-a-shell-class-and-variant.md))

**The `shell:` variant.** `@custom-variant shell (&:where(.shell, .shell *))`. It and `dark` are both zero-specificity
`:where()`, so `shell:` beats `dark:` only by coming later in the built CSS: keep `dark` declared above `shell`
in `resources/css/app.css` (a comment there pins it). Shared Blade components that the
old light pages still render carry their dark look as `shell:` classes beside the existing `dark:` ones:
- `components/board/project-tabs.blade.php` uses the hairline border and `ink` tones. RD-4 set the tabs to
  Stories · Preflight · Handbook · Map and dropped Dashboard; the old dashboard still renders them, none current.
- `components/board/status-chip.blade.php` and `status-bar.blade.php` (RD-4): neutral text, ring and track tones.
- `components/board/state.blade.php` gives the `pending` label `shell:text-ink-muted`. The dot colours need no
  variant, because the token re-pointing above handles them.

Primitives that only shell pages render since RD-4 carry the shell tokens directly, with no `dark:` pair:
`project-header` (whose breadcrumb's project link now opens the project's Stories page, and whose light/dark
toggle is gone), `prose`, `kit-badge`, `handbook-empty`, `mockup-state`, `mockup-current`, `confirm-modal`,
`switch`, `map-screen` and `metric-result`. Mockup iframes keep `bg-white` on purpose: a mockup is a light page.
Primary buttons (Add project, Pick, Save) are amber `bg-needs`, as on the RD-3 story page.

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
- Toasts: any shell page can call `Flux::toast(...)`; the host is in the layout.
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
- `tests/Feature/Board/ReskinKeptPagesTest.php` (RD-4): one `it()` per acceptance criterion, run over a dataset
  of the kept pages (shell + current sidebar entry, Stories rows as links with no modal, the four tabs), plus
  extras for the breadcrumb, toasts without `flux.appearance`, and `/` and `/p/{project}` still on `layouts/board`.
  The existing feature tests of each kept page still pass, with selectors changed only where markup did.
- `tests/Browser/ReskinKeptPagesTest.php`: each kept page and the viewer at 375 px with no sideways scroll and no
  JS errors, and a Stories row click landing on `/p/{project}/s/{id}`.
  It also pins the appearance stub: a dark-mode browser with `flux.appearance = light` saved keeps it through an
  old page → shell → back round trip and through a full load of a shell page → old page. Only the second leg runs
  the stub, so only it goes red without one.
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
- Kept pages moved in while three old pages stay out; primitives shared with those get `shell:` variants, not forks
  ([ADR-044](../decisions/ADR-044-kept-pages-move-into-the-shell-and-share-primitives-by-variant.md)).
- Flux toasts were brought into the shell rather than replaced by inline messages, which would have changed
  behaviour ([ADR-045](../decisions/ADR-045-flux-toasts-in-the-shell-behind-an-appearance-stub.md)).
- Kept pages stop pointing at the old dashboard: Stories rows link to the story page, the Dashboard tab is gone,
  the breadcrumb opens Stories ([ADR-046](../decisions/ADR-046-kept-pages-stop-linking-to-the-old-dashboard.md)).
- `ink-faint` is `#8a8d93`, not the mockup's `#7E8187`. The mockup value is 4.19:1 on `raised` (the current
  nav item's background). The new value is 5.76 / 5.35 / 4.91:1 on ground / surface / raised. Every other text
  token is at least 6.02:1, and ink on amber is 8.9:1.
- The new names (`layouts/shell`, `x-shell.*`, `data-nav*`, `data-shell-*`) sit beside the old `layouts/board`
  and `board/sidebar` rather than replacing them, because the old pages still need those until RD-9.

## Known limitations & gotchas
- Two shells exist until RD-9. `layouts/board` (light/dark, filterable sidebar, live badge) still serves `/`, the
  old `/p/{project}` dashboard and `/prod`. A fix to sidebar behaviour may need to land in both.
- The shell sidebar has no filter box, Production item, per-project counts or live badge (owner, 2026-10-11:
  none of the kept pages needs them; Production is backlog F-7). Brain and Money items are later phases.
  `LiveSessionsTest` therefore checks the live badge on `/`, not `/projects`.
- The Handbook's "unread" and "failed" states still say "Refresh the project from its dashboard". Revisit at RD-9.
- The story modal component stays (old Home, old dashboard and Live sessions open it) until RD-9 deletes it.
- `preflight-history.js` now uses shell-only token classes (`stroke-hairline`, `fill-ink-muted`, …). The charts
  would look wrong if that page ever went back to a light layout.
- Only the latin subset of Geist is loaded. Text outside latin falls back to the system font.
- A project page now refreshes every stale enabled project, not just its own. This is a behaviour change from
  SB-10.
- Installing npm packages in a worktree rewrites the `package-lock.json` name (RUNBOOK, 2026-10-11).

## Change history
2026-10-11 — Dark shell layout, Home A tokens, Geist fonts, shell sidebar and drawer, `RefreshStaleProjects`
middleware. The Preflight page is the first page moved in (RD-2, `43b01ed`)
2026-10-11 — Stories, Handbook, Mockup gallery and viewer, Manage projects and App map moved in, restyled; Flux
toasts and an appearance stub in the shell; `.shell.dark`; tabs Stories · Preflight · Handbook · Map (RD-4, `f722dbc`)
2026-10-11 — Browser test pins the appearance stub across a `wire:navigate` round trip (RD-4, `951e540`)
2026-10-11 — `ProductionSettings` lazy placeholder on `text-ink-muted` (was zinc/`dark:`, low contrast on `/projects`); `app.css` comment pins `dark` above `shell` (RD-4, `205e0b4`)
