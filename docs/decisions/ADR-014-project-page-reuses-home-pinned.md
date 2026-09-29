# ADR-014 — `/p/{project}` reuses the Home component, pinned, until SB-10

Date: 2026-09-29 · Status: superseded by [ADR-019](ADR-019-project-page-is-its-own-component.md)

## Context

SB-7 needs a single-project page now, but its real dashboard is SB-10. Until then the page should show
today's home content scoped to one project, and the scope must not be changeable from the page. The
`project` filter is a `#[Url]` property, so the query string and Clear filters can both move it.

## Decision

`routes/web.php` mounts `App\Livewire\Board\Home` at `/p/{project:name}` (`projects.show`).
`Home::mount(?string $project)` stores the name in a separate `#[Locked] public ?string $pinned`.
`render()` prefers `pinned` over the `project` filter, and `clearFilters()` does not reset `project`
while pinned. The view swaps the heading and hides the project `<select>`.

Alternatives rejected:
- **A new `ProjectPage` component that nests `Home`.** That produced two `<main>` elements and two `h1`s
  on one page.
- **Pinning by setting the existing `project` filter.** It is URL-bound and cleared by Clear filters,
  so the page could drift off its project.

## Consequences

- `Home` has two modes for now: `pinned === null` on `/` and a project name on `/p/{project}`.
- SB-10 points `projects.show` at its own component. At that point `pinned`, the `mount()` parameter
  and the `@if ($pinned)` branches in `home.blade.php` should be removed.
