# ADR-026 — The Stories page filters rows with one CSS class, not Alpine per row

Date: 2026-09-29 · Status: accepted

## Context

SB-15 renders every on-ref story of a project once (946 rows for coins). The status filter chips,
initiative selection and Expand all must be pure UI, with no server call. The straightforward Alpine
shape, an `x-show` on each row, creates hundreds of reactive bindings that all re-evaluate on every
chip click. The other obvious shape, holding the list in a public Livewire property, serialises every
row into the component snapshot and makes every interaction a round trip.

## Decision

Only the initiatives (about 60) are bound to Alpine. The rows carry no directives:
- A chip toggles one `hide-<kind>` class on the list container (`#stories-pane`). Each `<li>` carries
  a literal Tailwind variant for its kind, for example `[.hide-built_&]:hidden`, so the browser's CSS
  engine hides the rows.
- One delegated `x-on:click` on the container dispatches `board-story` for the clicked
  `[data-story-link]`.
- Whether an initiative is still shown is computed from its counts by kind, which the server passes
  in `groups`. The rows are never inspected.
- Story data is passed to the view in `render()` and is never a public property of `ProjectStories`.

Alternatives rejected:
- **`x-show` per row.** Correct, but it adds about 950 reactive effects at coins' size, for no
  benefit over CSS.
- **Livewire-side filtering.** It needs a server round trip per click, which the story rules out, and
  it would re-send the list each time.

## Consequences

- The `hide-*` variants must stay as literal strings in
  `resources/views/livewire/board/project-stories.blade.php`. Tailwind's `@source '../views'` scans
  views, not `app/`. Moving the map into PHP or building the class names dynamically silently breaks
  filtering.
- Non-first groups are `display: none` in the server markup, so the first paint is already correct
  and no loading skeleton is needed.
- The component never re-renders in a normal visit, so `board.stories_viewed` is one line per load.
