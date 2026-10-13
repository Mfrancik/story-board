# ADR-087 — Quick-peek and grid/list are Alpine; the narrow agenda is chosen by CSS

Date: 2026-10-12 · Status: accepted · Story: MK-10

## Context

The calendar has a quick-peek on each chip and becomes a two-week agenda below 768 px. The library has a grid/list
toggle. CLAUDE.md: never a server round-trip for UI state that doesn't need data.

## Decision

- **Quick-peek is Alpine.** Each chip (`components/marketing/calendar-chip.blade.php`) carries its peek data
  (`Js::from`), and a click sets the page's `peek`. "Open in studio" dispatches `open-studio` from the peek.
- **Grid/list is Alpine.** `layout` on the library's `<main>` drives `group-data-[layout=list]` classes.
- **Grid and agenda are both rendered**, and Tailwind's `md:` breakpoint shows one. The agenda's 14 days come from the
  same build as the grid (the range is widened to cover them).
- Paging months/weeks and Week/Month stay Livewire calls, because they change which posts are shown.

Alternatives rejected:
- **A Livewire peek** (`$peekId` + re-render). A round trip for data already on the page.
- **A JS viewport check that switches modes.** Flashes the wrong layout on load, needs a resize listener, and gives
  the server a viewport it can't know on first render.

## Consequences

- The calendar's HTML carries both layouts; on a month view that is a few dozen extra chips, accepted.
- Browser tests at 375 px assert by visibility (agenda visible, grid hidden), since both are always in the markup.
