# ADR-042 — One pick action shared by the story page and the mockup viewer

Date: 2026-10-11 · Status: accepted · Story: RD-3

## Context

RD-3 made the story page a second place to pick a mockup. Until then the only pick was
`MockupViewer::pick()` (SB-21), which did the work inline. It re-read the project, set and row on the server,
logged `board.mockup_pick_refused` when there was no set, called `StoryPickWriter::pick()`, turned a
`StoryPickRefusedException` into a sentence, and always dropped the local-pick cache.

## Decision

Move that work into `app/Actions/Board/PickMockup.php:handle()`. It returns `{branch, refusal}`, and both
`MockupViewer::pick()` and `StoryPage::pick()` call it. Each page decides only how to show the result: the
viewer shows a toast, and the story page shows an inline line. `StoryPickWriter` keeps every guard and refusal
rule, untouched.

Alternatives rejected:
- **Copy `MockupViewer::pick()` into `StoryPage`.** Two copies of the server-side re-validation and cache drop
  would drift. One that forgot `forget()` would hide a landed pick, and one that trusted the browser's story
  would write to the wrong file.
- **Move the re-read into `StoryPickWriter`.** The writer is the narrow gateway into a project (ADR-032), and the
  story listed its guards as Do NOT touch.

## Consequences

- A third pick surface calls `PickMockup` and gets the same refusals and logging.
- `board.mockup_pick_refused` for "no mockup set" is now logged from `PickMockup`, not from the viewer.
