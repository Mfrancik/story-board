# RD-3 — Short story page
Status: approved         Journey: none
Source: owner 2026-10-10: *"id like to shorten the story pages a lot more, some are super supper long i wont read
them. a title, short description, mockups, details can go below that"*.

## Story
As the owner, I want a story page that leads with the title, one sentence and the mockups, with every other detail
folded away below, so that I can understand and decide on a story in seconds without reading the whole file.

## Why
Story files run to hundreds of lines. The decision usually needs only what the story is and which mockup to pick.

## In scope
- `/p/{project}/s/{id}` renders a new page in the RD-2 shell. Top to bottom:
  1. ID, status and initiative as small tags; the title, large.
  2. **Summary:** the story's `Summary:` line if it has one, otherwise the first sentence of its `## Story`
     section. At most 200 characters, cut at a word with "…".
  3. **One primary action, depending on state:** awaiting a pick → "Pick option X" (the option selected below);
     everything else → none.
  4. **Mockups:** buttons for Today (the current-version shot, when there is one) and each option; one large
     sandboxed frame showing the selected option; links to full screen and side by side (the existing viewer).
     An optional "Why this one?" field is sent with the pick. Picking uses the existing `StoryPickWriter` path,
     its refusals included.
  5. **Details**, each collapsed, with a one-line summary on its header: Done when (criteria count, then the
     list); Depends on; Do not touch; Other versions (off-main copies); Full story text (rendered with `RenderStory`).
- A story with no mockups skips section 4; a story with no `## Story` section and no `Summary:` shows no summary line.

## Out of scope (do NOT build)
- Approving a story from the page (no writer for that exists).
- Cost and history per story (needs data the board does not have).
- Changing the `/story` skill or kit so it writes a `Summary:` line (kit change; capture as a backlog idea).
- Deleting the old story modal (RD-9).

## Acceptance criteria (executable — these become the Pest test names)
- Given a story with a `Summary:` line, then the page shows that line under the title.
- Given a story without one, then it shows the first sentence of `## Story`, cut to 200 characters at a word.
- Given a story awaiting a pick with options A–C, then the mockup buttons show Today, A, B and C, and the primary
  action reads "Pick option A" until another option is selected.
- Given option B selected and a reason typed, when Pick is pressed, then the story file gets B and the reason, and
  the page shows it as picked.
- Given the pick is refused (for example, a dirty checkout), then the refusal message shows and nothing is written.
- Given a built story with no mockups, then no Mockups section and no primary action show.
- Given the page loads, then every Details section is collapsed and its header shows its count or summary.
- Given an unknown story ID, then the page is 404.

## Applicable standards
- Design: RD-2 tokens; design-standards §Every interactive view must handle four states; Alpine for the option
  buttons and the details; mockup frames sandboxed as today.
- Codebase/DB: reuse `FindStoryVersion::handle()`, `ReadMockupSets`, `ReadCurrentVersion`, `RenderStory`,
  `StoryPickWriter`. A small action for the summary line.
- Logging: keep the existing story-view and pick events; add `board.story_summary_fallback` (debug) when the
  first-sentence fallback is used.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: redesign canvas https://claude.ai/artifact/Ervkp5duofs7VbUbbz7tDg, board "Story page — short first".
  Save it as a self-contained `docs/mockups/RD-3/option-a.html` before building.
- Chosen option: A (owner, 2026-10-10)
- Why I chose it: "these look good", meaning title, short description and mockups first, details below.

## Do NOT touch
- `StoryPickWriter`'s guards and refusal rules.
- `stories/` files of any project, except through the pick.

## Data & interfaces
- Schema/migrations: none.
- The route stays `/p/{project}/s/{id}`, served by a new component that replaces the old `StoryPage`.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: open a story awaiting a pick, switch options, pick one in a scratch project, and confirm the
  story file changed. Open a long built story and confirm the page fits on one screen with details closed.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-2
