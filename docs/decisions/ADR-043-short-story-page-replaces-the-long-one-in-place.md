# ADR-043 — The short story page replaces the long one in place

Date: 2026-10-11 · Status: accepted · Story: RD-3

## Context

RD-3 calls for a short story page (title, summary, mockups, folded details) at the existing
`/p/{project}/s/{id}`. The old `StoryPage` (SB-4) also had a full-screen compare overlay, a width toggle, a
"Copy `/build`" button for approved stories and a dark-mode toggle. None of these is in the approved mockup
(`docs/mockups/RD-3/option-a.html`). The mockup did show three more actions: "Approve without a pick", "Edit
story" and "Cost & history".

## Decision

- Replace `App\Livewire\Board\StoryPage` and its view in place, keeping the class name and the `stories.show`
  route name. The old component was used only by that route, so a parallel component would have left dead code
  for RD-9 to delete.
- Build only what the mockup shows and the board can back. The compare overlay, width toggle, `/build` button and
  dark toggle go. Comparing stays in the gallery viewer, which the page links as "Side by side". Approve, Edit and
  Cost & history are scoped out, because no writer or data exists for them. The mockup record shows them dashed.
- Details are native `<details>`, not Alpine: opening and closing needs no state. All five sections always
  render, and say "None…" when empty, so the page has the same shape for every story.
- No confirm dialog on Pick, because the mockup has none. Success is an inline message, because the shell layout
  has no Flux toast host.

Alternatives rejected:
- **A new component beside the old one.** It would leave two pages for one route and its tests.
- **Keep the compare overlay.** It is not in the approved design, and the viewer already does it.

## Consequences

- Links and tests that used `stories.show` keep working. Tests that asserted the old page's hooks
  (`data-mockups`, `data-compare`, the `/build` button) were rewritten.
- The story modal keeps the long form until RD-9 deletes it.
