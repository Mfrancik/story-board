# ADR-016 — One `?story` parser, one version picker, and a link that names a story, not a version

Date: 2026-09-29 · Status: accepted

## Context

SB-8 adds a second way to open a story: the modal at `?story=<project>/<ID>`, beside the story page
at `/p/{project}/s/{id}?v=`. The `?story` value comes from the address bar and must be validated
before it reaches the database or git. The modal also needs the same "which version is the story"
rule that `StoryPage` had privately, and the same list of other versions.

## Decision

- `app/Actions/Board/ResolveStoryLink.php:handle()` is the only parser of `?story`. It checks the
  shape `<project>/<ID>` (a project name must start with a letter or digit, so `.` and `..` fail),
  then `Story::ID_PATTERN`, then `CheckProjectShown::refusal()`, and only then queries. Every refusal
  logs `board.story_modal_refused` with `malformed`, `unknown` or `disabled`. `StoryModal` routes every
  way in (mount, `open()`, a browser edit of `story`) through it.
- `app/Actions/Board/FindStoryVersion.php` holds the version rule moved out of `StoryPage`:
  `handle()` (a requested off-main version, else the ref's row, else the first off-main version by
  branch → worktree → untracked), `all()` and `others()` (the "Versions off main" lines). The page
  and the modal call the same code, so they cannot disagree about which copy is "the" story.
- The link carries no version. Clicking an off-main row of a story that is also on main opens the
  **main** version. The branch version is listed under "Versions off main", linking to the full
  page with `?v=`. A story that exists only off main opens its off-main version.

Alternatives rejected:
- **`?story=<project>/<ID>@<rowId>`** or a second `?v=` on the list page. Row IDs change at every
  refresh (the snapshot is replaced wholesale, ADR-005), so a shared or reloaded link would break. A
  project/ID pair survives a refresh.
- **Parsing `?story` inside the component**, as `StoryPage` does with `?v=`. A second parser is a
  second place for the ID or path check to drift.

## Consequences

- An off-main row whose ID is not well formed (odd mockup folder names) cannot form a link, so
  `board/story-row` renders it as a plain, non-interactive row.
- A dependency chip opens a story with any version in the same project, while the story page's
  chips still link only to dependencies on main.
