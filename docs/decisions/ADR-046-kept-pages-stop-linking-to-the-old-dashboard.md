# ADR-046 — Kept pages stop linking to the old dashboard and the story modal

Date: 2026-10-11 · Status: accepted · Story: RD-4

## Context

The kept pages pointed into pages the redesign scraps: Stories rows opened the SB-8 story modal (embedded on
the page, `?story=` URLs), the project tabs led with Dashboard (`/p/{project}`), and the project header's
breadcrumb linked the project name to that dashboard. RD-3 built the short story page at `/p/{project}/s/{id}`.
The modal and the old dashboard are still used by `/` and `/p/{project}` until RD-9.

## Decision

- A Stories row with a well-formed ID is an `<a href="route('stories.show')">` with `wire:navigate`, keeping its
  `data-story-link` hook. The page no longer embeds `<livewire:board.story-modal />`, and its delegated
  `openStory()` click handler is gone.
- `board/project-tabs` is Stories · Preflight · Handbook · Map. Dashboard is removed; "App map" is "Map".
- `board/project-header`'s project link goes to `projects.stories`, as the shell sidebar's project entry does.
  Its light/dark toggle is removed.
- The `StoryModal` component itself is kept, because old Home, the old dashboard and Live sessions still open it.
  RD-9 deletes it.

Alternatives rejected:
- **Keep the modal on Stories until RD-9.** The story page exists and is the redesign's single story view;
  keeping the modal means two story views on one page's rows.
- **Delete the modal now.** Out of scope (RD-9) and it would break the pages still on `layouts/board`.

## Consequences

- Rows open in a new tab and Back returns to the list, as a real link does.
- The old dashboard still renders the new tabs, with none marked current, until it is deleted.
- The RD-4 pages no longer link to `/p/{project}`. It is still reached from the old Home's tiles and sidebar, `/prod`,
  and the RD-3 story page's breadcrumb (`story-page.blade.php`), which RD-4 did not change.
