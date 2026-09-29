# SB-8 — Story detail modal
Status: approved         Journey: none
Source: owner 2026-09-29 (/story): *"id like clicking one of the line items to open a big modal to show me
everything there."* Owner answer: the modal is URL-addressable.

## Story
As the owner, I want clicking any story row to open a large modal with everything about that story, so
that I can read it, see its mockups and versions, and get back to the list without losing my place.

## Why
Rows expand in place today (SB-3), which pushes the list around and shows only part of the story. A modal
keeps the list still and has room for the whole story, and a URL means a reload or a shared link lands on
the same story.

## In scope
- Every story row, on `/`, on `/p/{project}` and in every section, is a `<button>` that opens the modal.
  Expand-in-place is removed.
- The modal is design A's: centered, `max-w-5xl`, about 90vh, scrolling inside. It has two columns at
  768px and up and one below.
  - Header: ID, title, status chip, project, and "Open full page →" to `/p/{project}/s/{id}` (the page
    that compares mockups side by side). A close button with `aria-label="Close"`.
  - Left column: the story text, rendered by the existing `RenderStory` at the snapshot SHA.
  - Right rail:
    - Details: status, initiative, journey, `Source:` date (`dated_on`), and parse errors when present.
    - Depends-on chips. A dependency that exists in the same project opens in this modal; one that does
      not is shown as plain text.
    - Mockup thumbnails from `Story::mockupUrl()`, with the chosen option ringed and badged "chosen". A
      choice the parser could not read is quoted, per ADR-009.
    - Versions not on main (SB-5 rows) with their location labels.
- The URL carries `?story=<project>/<ID>` (for example `/?story=coins/ACQ-20`), on both the home page and
  the project page. The browser Back button closes the modal. Opening the URL directly opens the modal.
- Escape, the close button and a backdrop click close the modal. Focus moves into it on open, is trapped
  inside it, and returns to the row on close.
- A story that exists only off main (for example client-dashboard CT-1, in a worktree) opens with its
  off-main version and a "Not on main — worktree <path>" banner. An untracked version shows its location
  and no text, as today.

## Out of scope (do NOT build)
- Picking a mockup (SB-13). This modal is read-only.
- The full-page mockup compare. The story page keeps it.
- Changing the story page's own layout, beyond sitting in the SB-7 shell.

## Acceptance criteria (executable — these become the Pest test names)
- Given coins ACQ-20 on the ref (built, depends on ACQ-19 and ACQ-17, mockups a/b/c, chosen a), when
  `?story=coins/ACQ-20` loads, then the modal shows its title, its rendered story text, the dependency
  chips ACQ-19 and ACQ-17, and three mockup thumbnails with A marked chosen.
- Given the modal is open, when a dependency chip for a story in the same project is clicked, then the
  modal shows that story and the URL changes to it.
- Given client-dashboard CT-1, which exists only in a worktree, when `?story=client-dashboard/CT-1` loads,
  then the modal shows the worktree version with a not-on-main banner.
- Given `?story=coins/NOPE-1` (well-formed, no such story), then no modal opens, the page shows "No story
  coins/NOPE-1 on the board", and `board.story_modal_refused` is logged with reason `unknown`.
- Given `?story=coins/../x` or another malformed value, then no modal opens, git is never called, and
  `board.story_modal_refused` is logged with reason `malformed`.
- Given a story in a disabled project, then no modal opens and `board.story_modal_refused` is logged with
  reason `disabled`.
- Given a story row on `/`, when it is clicked, then the modal opens with that story and the list keeps its
  scroll position.
- Given the modal is open, when Escape is pressed, then it closes, `?story` leaves the URL, and focus
  returns to the row.
- Given a story with an unparsed choice (a bold `Chosen option: **B**`, F-1), then the rail quotes the raw
  line and marks no thumbnail chosen.

## Applicable standards
- Design: design-A modal (SB-7 gate). Detail view as a modal from a list, with its own URL (design
  standards §Canonical interaction patterns). The same pattern is used on every list the board has, and
  the full page stays for comparing mockups. Loading state: a skeleton inside the modal while the story
  text loads (`wire:loading`), with its space reserved.
- Codebase/DB: no schema change. The `?story` parameter is parsed once (`Story::ID_PATTERN` plus a
  project-name check) and never reaches git unvalidated. `RenderStory` and `Story::mockupUrl()` are
  reused. `board/story-row` loses its Alpine expand and becomes a button. L-5: each refusal gets a test
  and a log line.
- Logging: `board.story_modal_opened` (info; `project`, `story`, `version` = `ref|<location_kind>`),
  `board.story_modal_refused` (info; `project`, `story`, `reason` = `unknown|malformed|disabled`).

## Design mockup gate
- Mockups: covered by docs/mockups/SB-7/option-a.html, view 3 (story modal open).
- Chosen option: a (SB-7's gate)
- Why I chose it: "A is the best design" (owner, 2026-09-29).

## Do NOT touch
- `app/Services/GitReader.php`, `app/Http/Controllers/MockupFileController.php` (reuse it as it is), any
  registered project's files.

## Data & interfaces
- Schema/migrations: none.
- Livewire: a `StoryModal` component embedded by the home and project pages, with a `#[Url] story`
  property. `board/story-row` is changed. `docs/UI-INVENTORY.md` is updated (story-row no longer expands;
  new story-modal).

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: on `/` click a "Ready to build" row, see the modal, press Back, and see the list at the
  same scroll. Open `/?story=coins/ACQ-20` directly and see A marked chosen. Check at 375px (one column).
- Checked against real data (2026-09-29): coins ACQ-20 is `built`, `depends_on` = [ACQ-19, ACQ-17],
  mockups `{dir: docs/mockups/ACQ-20, chosen: a, options: [a,b,c]}`. client-dashboard CT-1 has no ref row,
  only a `worktree` row (location `/Users/mikefrancik/Code/client-dashboard`). coins MOB-65's raw line is
  `**B** — the product row opens in place (2026-09-29)`, stored as chosen null (F-1).

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7
