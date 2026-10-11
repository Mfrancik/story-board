# ADR-049 — Overview panels fail alone; the view is logged once per page load

Date: 2026-10-11 · Status: accepted · Story: RD-5

## Context

The overview draws on three independent sources: the stored snapshot (Needs me, In flight), the session transcripts
(Live now), and the preflight CSVs (Preflight figure). The story asked for an error state on Live now when the
session reader throws. The page also polls every 30 s, because Live now's `wire:poll` re-renders the whole
component.

## Decision

- `Overview::attempt()` wraps **each** source (`needs`, `live`, `figures`) in its own `try/catch`. A throw becomes
  `null`, logs `board.overview_panel_failed` (error: panel, exception class, message), and only that panel and the
  figures built from it show an error state. This covers every panel, not just Live now.
- `board.overview_viewed` (info, with every count) is logged only when `Livewire::isLivewireRequest()` is false,
  that is on a full page load, not on each poll.

Alternatives rejected:
- **Guard only Live now.** A broken query or CSV would still take the whole page down, against design-standards'
  four states per view.
- **Separate Livewire components per panel.** Isolates failure too, but the header sentence and figures are built
  from the panels' data, so they would need to share state across components or query twice.
- **Log every render.** Two info lines a minute per open tab, with the same counts.

## Consequences

- A failing panel is visible on the page and in the log by name; the rest of the overview stays usable.
- A count that changes only between polls is not logged until the next full load.
