# SB-4 — Read a story and see its mockups side by side
Status: built            Journey: none
Source: owner 2026-09-29 (coins /story): *"see all of the stories … mockups, etc"*.

## Story
As the owner, I want to open any story and see its full text next to its mockup options, so that I
can review a story or make a mockup decision without hunting through folders in each repo.

## Why
Right now a mockup review means someone serving files locally, and a mockup pick means remembering
which folder holds which story. This puts both on one page. The decision itself is still recorded
through /story or /build, by the read-only ruling.

## In scope
- `/p/{project}/s/{storyId}`: the story's markdown rendered from the ref's content (SB-2 stores it,
  or fetches it through `GitReader`). Header chips: status, initiative, journey, source, depends-on
  (as links to those stories).
- Mockups panel: each `option-*.html` at the ref shown in a sandboxed iframe, served by the board
  from `git show` content, with a toggle between side by side and one at a time. Widths 375 / 768 /
  1280. The chosen option is marked, with the story's "Why I chose it" text.
- Relative assets inside a mockup directory (css, png) are served from the same ref.
- A "Copy /build command" button (clipboard only) for approved stories, e.g. `/build SB-3`. This is
  not a write action.

## Out of scope (do NOT build)
- Editing, approving, or picking from the board.
- Mockups that aren't on the ref (SB-5).
- Diffing story versions.

## Acceptance criteria
- Given MOB-65 is `approved` on coins' ref, when its page opens, then the rendered title, status chip
  `approved`, and the `## Story` text appear.
- Given a story with option-a/b/c mockups and `Chosen option: b`, then three frames render and b is
  marked chosen alongside the reason text.
- Given a mockup that references `shots/01.png` in its own directory, then the image loads from the
  ref.
- Given a mockup contains a script, then it runs only inside a sandboxed iframe with no same-origin
  access to the board.
- Given a story with no mockup directory, then the panel reads "No mockups". The panel does not
  appear for stories marked non-visual.
- Given an unknown story ID, then the page returns 404.
- Given a path-traversal attempt in the mockup file route (`../../.env`), then it returns 404 and
  never reads outside `docs/mockups/<ID>/` at the ref.

## Applicable standards
- Design: kit design-standards; the markdown styled with the Tailwind typography plugin if it's already
  a dependency, otherwise ask first.
- Codebase/DB: mockup bytes come only from `GitReader::show(ref, path)`, with the path validated
  against the story's mockup directory. CSP headers on the mockup route.
- Logging: `board.story_viewed`, `board.mockup_served` (debug), `board.mockup_path_rejected` (warning).

## Design mockup gate
- Mockups: `docs/mockups/SB-4/option-{a,b}.html` (layout: story beside mockups, or story above them).
- Chosen option: b
- Why I chose it: story above the mockups gives the frames the full width; owner also asked for "an
  option to open a bigger browser screen and pick two to see side by side" (owner, 2026-09-29).

## Owner rulings at the gate (2026-09-29)
- **Full-screen compare**: a Compare button opens a full-window overlay; choose any two options
  (default: the chosen one and the first other), each fills half the screen at 375/768/1280, with a
  swap button; Esc closes. Pure UI state (Alpine) — nothing is recorded; the board stays read-only.

## Do NOT touch
- Registered projects' files. SB-3's home layout, apart from pointing its rows at this page.

## Data & interfaces
- Routes: `/p/{project}/s/{storyId}` (Livewire), `/p/{project}/m/{storyId}/{file}` (controller, raw
  mockup bytes).

## Test plan
- Pest tests from the acceptance criteria, including the traversal and sandbox tests, written first.
- Browser check: open coins MOB-56 (four options, pick D), confirm all four frames render and D is
  marked chosen, then open the screenshots.

## Definition of done
- [ ] Acceptance criteria pass
- [ ] Mockup gate cleared
- [ ] Logging events in place
- [ ] Status flipped to `built` in the build commit
- [ ] /preflight GO
- [ ] /document

## Links
Journey: none · Depends on: SB-2, SB-3
