# ADR-008 — Mockups are served from git at the snapshot SHA, through one sandboxed route

Date: 2026-09-29 · Status: accepted

## Context

SB-4 shows each story's `option-*.html` mockups on the board. Those files live in other repos, are
written by agents and humans, and often contain scripts. The board must render them live, including
relative assets like `shots/01.png`, without letting them touch the board's origin. It also must not
read anything outside the story's mockup folder, and must not read the working tree: the owner's rule
is that the board reads from each project's ref.

## Decision

One route, `GET /p/{project}/m/{storyId}/{file}` (`app/Http/Controllers/MockupFileController.php`),
is the only way from a URL to a project's bytes. `app/Actions/Board/ReadMockupFile.php` does the
work:
- The **directory comes from the stored snapshot** (`stories.mockups.dir` must equal
  `docs/mockups/<ID>`), never from the URL.
- `file` must be plain path segments. `.`, `..`, hidden files, `//`, encoded dots, backslashes and
  NULs are **refused, not normalised**. A refused path returns 404 and logs
  `board.mockup_path_rejected`.
- The bytes come from `GitReader::show()` at the story row's `sha`.
- The response sends a CSP `sandbox allow-scripts allow-popups` directive (no `allow-same-origin`,
  no top navigation) plus `nosniff`. Every `<iframe>` that embeds it also carries `sandbox="allow-scripts"`.
  So the scripts run, but in an opaque origin even when the file is opened directly in a tab.
  `tests/Browser/MockupSandboxTest.php` proves `origin=null` and that cookies are blocked.

Alternatives rejected:
- **Serve from the working tree.** It may be stale or half-edited, and it breaks the owner's rule
  that the board reads from the ref.
- **Copy mockups into story-board.** That duplicates other repos' files, needs a sync step, and gives
  a second copy that can drift.
- **Iframe `sandbox` attribute only.** It does not protect a mockup opened in its own tab ("open ↗").
  The CSP header does.

## Consequences

- A mockup that isn't on the ref cannot be shown (SB-5's concern).
- Each frame is one request plus one `git show`. Frames are lazy, and the compare overlay's frames
  exist only while it is open.
- Mockups cannot use cookies or `localStorage`, because they run in an opaque origin.
- The home page's expand thumbnails reuse the same route, so there is one sandbox to get right.
