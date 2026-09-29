# ADR-021 — Manage projects re-navigates after every write so the sidebar follows

Date: 2026-09-29 · Status: accepted

## Context

SB-12's Manage projects page switches projects off and on, adds them and removes them. The sidebar must
drop or gain the project at once. But the sidebar is `board/sidebar`, a Blade class component rendered by
`layouts/board` once per page load (SB-7). It is not a Livewire component, so a Livewire action on the
page cannot re-render it. Each write also confirms with a toast.

## Decision

- Every write in `app/Livewire/Board/ManageProjects.php` (`setEnabled`, `add`, `remove`, including their
  refusals) ends with `reload()`: `redirectRoute('projects.manage', navigate: true)`. The page reloads
  through `wire:navigate`, and the layout renders the sidebar again from the database.
- The toast is raised before the redirect, so `layouts/board` wraps `<flux:toast.group>` in
  `@persist('toast')`, which keeps it alive across the navigation.

Alternatives rejected:
- **A Livewire event to a sidebar component.** It would update without a page load, but the sidebar
  would have to become a Livewire component, adding a component round trip and state to every board
  page for a change that happens only here.
- **A full (non-navigate) redirect.** Simpler, but it loses the toast and flashes the whole page.
- **Leave the sidebar stale until the next page.** The story requires the project to leave the sidebar
  when it is switched off.

## Consequences

- Each switch costs a page navigation rather than a partial render. On a localhost board with a handful
  of projects that is not noticeable.
- The form is reset by the reload on success; on a refusal `add()` returns before `reload()`, so the
  typed values and the inline error stay.
- Any later page that changes what the sidebar lists (SB-13 and on) can reuse the same pattern, and any
  toast raised before a `wire:navigate` now survives it.
