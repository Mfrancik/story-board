# ADR-019 — `/p/{project}` is its own `ProjectPage` component, sharing the What needs me cards with `/`

Date: 2026-09-29 · Status: accepted · Supersedes: [ADR-014](ADR-014-project-page-reuses-home-pinned.md)

## Context

SB-7 served `/p/{project}` from `Home` with a locked `$pinned` name, as an interim until SB-10 gave the
page its own dashboard (ADR-014). SB-10's page has different sections from `/` (Progress by
initiative, Not on main by kind, a one-project refresh) and none of `/`'s portfolio parts (tiles, In
flight, filters). Both pages still need the same three What needs me cards.

## Decision

- `projects.show` points at a new `App\Livewire\Board\ProjectPage`. `EnsureProjectIsShown` stays on
  the route and still runs before binding (ADR-013). `Home` loses `$pinned`, its `mount()` parameter
  and the `@if ($pinned)` branches, as ADR-014 said it should; `board.project_viewed` is now logged
  from `ProjectPage::mount()`.
- The cards move out of `home.blade.php` into `resources/views/components/board/needs-me-cards.blade.php`,
  used by both pages. Each host keeps its own `showAll()` and `expandedGroups`. The state dot and
  label move out of `project-card` into `components/board/state.blade.php` for the same reason: the
  tile and the project header both show them.
- `ProjectPage` holds the project **name** in a locked string, not the `Project` model, and loads the
  model in `render()`.

Alternatives rejected:
- **Keep extending `Home` with a pinned mode.** Every section would need a `$pinned` branch, and the
  two pages share only the cards.
- **Copy the card markup into the project page.** Two copies of the same design-A card would drift.
- **A `ProjectPage` that nests `Home`.** Already rejected in ADR-014: two `<main>`s and two `h1`s.
- **A public `Project $project` property.** Livewire would rehydrate it by key on every request; a
  project removed mid-visit then fails inside hydration. With a name, `refresh()` can run the same
  `CheckProjectShown` refusal as the route, log it and send the owner home.

## Consequences

- `Home` has one mode again and serves `/` only.
- A change to the cards lands on both pages at once; `needs-me-cards` must keep calling the host's
  `showAll('<card>')`, so any host must define it.
- Only `refresh()` re-checks the project. Other actions after a mid-visit removal fail at
  `render()`'s `firstOrFail()` (a 404 on the Livewire request), which is acceptable for a
  single-owner localhost board.

## Amendment — 2026-09-29 (SB-10 fix, `fix(SB-10): project page re-checks its project on every request`)

- The last consequence above no longer holds. `ProjectPage::hydrate()` runs `CheckProjectShown` on
  every Livewire request after the first: a project switched off or removed mid-visit logs
  `board.project_page_refused` (`request: update`), redirects home and renders nothing of the project.
- Why `hydrate()`, not `render()`: route middleware (ADR-013) guards only the initial GET, and the
  check has to run before any action touches the project. Holding the name rather than the model is
  what lets it refuse and log instead of failing inside hydration.
