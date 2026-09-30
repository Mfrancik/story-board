# SB-26 — Mockup viewer opens side by side
Status: draft          Journey: none
Source: owner 2026-09-30: *"when i click mockups, open the side by side view by default"*. Owner answers: with no
screenshot of today's page, open option a vs option b; one-option sets and phone widths open single, as now.

## Story
As the owner, I want a mockup set to open with two versions side by side, so that I compare directions straight
away instead of clicking "Side by side" on every set.

## Why
Comparing is the point of opening a set. Today the viewer opens side by side only when SB-23 finds a journey shot;
every other set opens on one option and needs a click.

## In scope
- Opening a set from `/mockups` (or any link to `/mockups/{project}/{story}`) starts in compare whenever the set
  has two or more versions to show:
  - a journey shot exists → Current left, first option right (unchanged, SB-23);
  - no shot, two or more options → first option left, the next option right. "First" keeps today's rule
    (the picked option when there is one, else option a); "next" is the first other option in a/b/c order.
- Single view stays the start when there is only one version to show (one option and no shot).
- Below the `md` breakpoint (768px) the viewer starts single, as today; the "Side by side" toggle still works there.
- The server still decides the starting panes (`compare`, `left`, `right`), so the page and its log line agree.
  `board.mockup_viewed`'s `compare` means "opened in compare on a wide screen"; the phone fallback is client-side.
- Prev/next between sets (the viewer's arrows) applies the same rule to each set it lands on.

## Out of scope (do NOT build)
- Remembering the toggle across sets or visits. Changing the compare layout, pickers, swap or width switch.
- The gallery grid itself, the story modal and the story page (they link to the viewer; they don't embed it).
- The Current pane's matching or empty states (SB-23).

## Acceptance criteria (executable — these become the Pest test names)
- Given a set with options a and b and no journey shot, when it opens, then it is in side by side with option a
  left and option b right.
- Given a set with options a, b and c where b is picked and no shot, when it opens, then b is left and a is right.
- Given a set with a matching journey shot, when it opens, then Current is left and the first option right (unchanged).
- Given a set with a single option and no shot, when it opens, then it shows that option alone with compare off.
- Given a 375 px viewport, when a two-option set opens, then it shows one option with compare off and no horizontal
  page scroll, and "Side by side" still turns compare on.
- Given the viewer opened in compare, when "Side by side" is clicked, then it switches to the single view.
- Given prev/next moves to another set, then that set opens by the same rule.
- Given a set opens in compare, then `board.mockup_viewed` logs `compare: true` with the correct `current` flag.

## Applicable standards
- Design: no new layout; the existing SB-21 option-a compare and toolbar. Tokens unchanged.
- Codebase/DB: no schema change. Starting-pane choice in `MockupViewer` (server); the phone fallback in
  `mockupViewer()` (resources/js/mockup-gallery.js) via `matchMedia('(min-width: 768px)')`. WHY comments on both.
- Logging: `board.mockup_viewed` unchanged in shape; `compare` now true for any multi-version set.

## Design mockup gate (visual stories only — else "n/a — non-visual")
n/a — behaviour default only; reuses the approved SB-21 option-a compare layout.

## Do NOT touch
- `app/Actions/Board/ReadCurrentVersion.php`, `app/Actions/Board/ReadJourneyShots.php`, `app/Services/GitReader.php`,
  `app/Services/StoryPickWriter.php`, `config/`.

## Data & interfaces
- Schema/migrations: none.
- Changed: `app/Livewire/Board/MockupViewer.php` (starting panes), `resources/views/livewire/board/mockup-viewer.blade.php`
  (`data-compare-open` from the new rule), `resources/js/mockup-gallery.js` (phone fallback). No new routes or events.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build. Pane choice and log in
  `tests/Feature/Board/MockupGalleryTest.php` / `MockupCurrentTest.php`; compare on open, 375 px and toggle in
  `tests/Browser/MockupGalleryTest.php`.
- Journey test: none.
- Browser check: `/mockups` → open a set with a and b → side by side, a left, b right → Side by side off → single
  → next set → opens side by side again. At 375 px: opens single.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: n/a — behaviour default on an approved layout
- [ ] Journey test(s) green end-to-end (n/a)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc updated via /document (docs/features/mockup-gallery.md)

## Links
Journey: none · Depends on: SB-21, SB-23
