# Story page and mockups
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-4

## Overview
`/p/{project}/s/{storyId}` shows one story's full text, read from its project's ref, above its mockup
options. The options render in sandboxed frames at 375, 768 or 1280 px, and a full-screen overlay
compares any two. Before this page, reviewing a mockup meant serving files locally from each repo.
The page only displays. Approving and picking stay in `/story` and `/build`, because the board is
read-only by owner ruling. The layout is mockup B (story above, so the frames get the full width).
The compare overlay was added by owner ruling at the gate.

## How it works
**Routes** (`routes/web.php`):
- `stories.show`: `Route::livewire('/p/{project:name}/s/{storyId}', StoryPage::class)`, constrained to
  `[A-Z]{2,}-[0-9]+[a-z]?`, so a malformed ID is a routing 404 and never reaches the component.
- `mockups.file`: `GET /p/{project:name}/m/{storyId}/{file}` → `MockupFileController`. `file` is
  `.*` so that relative assets like `shots/01.png` match. `ReadMockupFile` decides what is allowed,
  not the route pattern.

**The page.** `app/Livewire/Board/StoryPage.php:mount()` 404s a disabled project, then loads the
story's on-ref row (`Story::onRef()`, by `project_id` and `story_id`) or 404s. It reads the raw
markdown once with `RenderStory::read()` (`GitReader::show()` at the row's `sha`) and uses that one
read for two things:
- `RenderStory::toHtml()` makes the body: `Str::markdown` with raw HTML escaped and unsafe links
  dropped. This is the same renderer the home page's expand uses.
- `app/Actions/Board/ReadMockupGate.php:handle()` quotes the `## Design mockup gate` section. It
  returns `visual` (false when the section starts `n/a`), `chosen` (the `Chosen option:` text as
  written) and `why` (`Why I chose it:`), with emphasis stripped and `_pending_` read as null.
  **It never decides which option is chosen.** The marked frame comes only from the kit parser's
  `stories.mockups.chosen` ([ADR-009](../decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md)).

`render()` re-reads the row and looks up which `depends_on` IDs exist in the same project. Those
become `stories.show` links and the others stay plain text. Every public property is `#[Locked]`.
The component has no actions: all interaction is Alpine.

**The view.** `resources/views/livewire/board/story-page.blade.php`:
- Header: breadcrumbs back to `/` and to `/?project=<name>`, a dark-mode toggle, and for
  `approved` stories only a "Copy `/build <ID>`" button that writes to the clipboard.
- Article: status chip, initiative/journey/depends-on chips, `Source:`, the path and ref@sha, any
  `parse_errors`, and the rendered text in a `prose` block. If git could not read the file, it says
  so.
- Mockups panel: rendered only when `gate.visual`. It is **servable** only when the snapshot's
  `mockups.dir` is exactly `docs/mockups/<ID>` and there are options. Otherwise it reads "No mockups
  at <ref> for <ID>".
  - Chosen banner, one of three: the parser's letter with the "why" text (green); the story's written
    choice quoted with an F-1 note and **no frame marked** (amber, `data-chosen-text`); or "No option
    chosen yet".
  - One `<figure data-mockup-frame>` per option. Each holds an
    `<iframe sandbox="allow-scripts" loading="lazy">` pointing at `mockups.file`, and the chosen one
    gets `data-chosen` and a ring.
  - Alpine state on the section: `mode` (`side` / `one`), `width` (375/768/1280), `current`,
    `compare`, `left`, `right`. Height is `.mockup-frame` in `resources/css/app.css` (70vh). Width is
    bound inline because it is data.
- **Compare overlay**: `x-teleport="body"` → `x-if="compare"` → a `role="dialog"` with
  `x-trap.noscroll`. Two selects (default: the chosen option, or the first, against the first
  other), a swap button, width toggles, and two iframes each half the window. Esc or "× Close"
  closes it. Because of `x-if`, the two compare frames (two git reads) exist only while the overlay
  is open (see RUNBOOK). Nothing about a comparison is recorded.

**Serving a mockup file.** `app/Http/Controllers/MockupFileController.php:__invoke()` 404s a disabled
project and calls `app/Actions/Board/ReadMockupFile.php:handle()`:
1. Rejects a `storyId` not shaped like an ID, and any `file` that is not plain segments
   (`isPlainRelativePath()`: each segment `[A-Za-z0-9_-][A-Za-z0-9._-]*`, so no `.`, `..`, hidden
   files, empty segments from `//`, backslashes, NULs, or anything over 255 bytes). Encoded dots arrive
   decoded and fail the same check. A rejected path logs `board.mockup_path_rejected`.
2. Takes the **directory from the snapshot**, not the URL. The row's `mockups.dir` must equal
   `docs/mockups/<storyId>`, so a URL can only ever reach its own story's folder.
3. Reads `<dir>/<file>` with `GitReader::show()` at the row's `sha`. It never reads the working tree.
Any failure throws `MockupNotFoundException`, which the controller turns into a 404. The response
carries the content type from an extension allow-list (otherwise `application/octet-stream`) and a
CSP whose `sandbox` directive gives it an opaque origin even when opened directly in a tab. It also
sends `nosniff`, `no-referrer` and `private, max-age=60`. See
[ADR-008](../decisions/ADR-008-mockups-served-from-git-in-a-sandbox.md).

## Data model
Reads only. Uses `projects` and the on-ref `stories` rows (`mockups` JSON: `dir`, `options`,
`chosen`; `depends_on`; `parse_errors`; `sha`). No migrations. Story text and mockup bytes are read
from git on request and never stored.

## Interfaces
- `GET /p/{project}/s/{storyId}` (`stories.show`) → `App\Livewire\Board\StoryPage`. Returns 404 for
  an unknown or disabled project, an unknown story, or a malformed ID.
- `GET /p/{project}/m/{storyId}/{file}` (`mockups.file`) → raw bytes or 404. The home page's
  `story-row` thumbnails use it too.
- `RenderStory::read(Story): ?string` (raw markdown, or null and logged) and
  `RenderStory::toHtml(string): string`. `handle()` still composes the two for the home page.
- `ReadMockupGate::handle(string $markdown): array{visual: bool, chosen: ?string, why: ?string}`.
- `ReadMockupFile::handle(Project, string $storyId, string $file): string`, which throws
  `App\Exceptions\MockupNotFoundException`.
- Test hooks: `data-mockups`, `data-mockup-frame="<letter>"`, `data-chosen`, `data-chosen-text`,
  `data-compare`.

## Configuration
None of its own. Relies on `PHP_CLI_SERVER_WORKERS` (see the
[home doc](what-needs-me-home.md#configuration)) so that several frames can load in parallel with the
page. `@tailwindcss/typography` styles the story text.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.story_viewed` | info | `StoryPage::mount()` | `project`, `story` |
| `board.story_read_failed` | warning | `RenderStory::read()` | `project`, `story`, `error` |
| `board.mockup_served` | debug | `ReadMockupFile` | `project`, `story`, `file`, `bytes` |
| `board.mockup_not_found` | info | `ReadMockupFile` | `project`, `story`, `file`?, `reason` (`unknown story` / `no mockup directory` / `not at the ref`) |
| `board.mockup_path_rejected` | warning | `ReadMockupFile` | `project`, `story`, `file` |

Every line carries `request_id` (see the home doc). A healthy page view logs one `story_viewed`,
then one `mockup_served` per option frame. Frames are separate requests, so each has its own
`request_id`. A page view that logs `mockup_served` for more frames than the story has options means
the compare frames are loading while the overlay is closed, which is the regression in the RUNBOOK.
Any `mockup_path_rejected` means a URL the board never builds, so someone is probing it.

## Testing & verification
- `tests/Feature/Board/StoryPageTest.php` has one `it()` per acceptance criterion: title, status chip
  and Story text; frames and chosen-with-reason; no `allow-same-origin`; "No mockups"; no panel when
  non-visual; 404s. It also covers depends-on links, the approved-only `/build` button, compare
  defaults, `story_viewed` logging, a quoted unparsed choice, disabled project and malformed ID, an
  unreadable story, and compare frames behind `x-if`.
- `tests/Feature/Board/MockupFileTest.php`: serving HTML and a relative `shots/01.png` from the ref;
  CSP and `nosniff`; ref rather than working tree; a traversal dataset (`..`, encoded, `./`, `//`,
  hidden); missing file; unknown story, project or mockup dir; no cross-story reach.
- `tests/Browser/MockupSandboxTest.php` opens a mockup directly in a real browser. Its script sees
  `origin=null` and `document.cookie` throws.
- Browser check on real data, 2026-09-29: coins MOB-56 rendered four frames (a–d) from `origin/main`.
  Its written choice "D" was quoted, not marked (F-1). The story's test plan expected D marked, and
  the owner ruled the fix belongs in the kit parser. Compare opened a vs b at 50/50, and Esc closed
  it. At 375 px in dark mode there was no horizontal scroll. `/p/coins/s/NOPE-1` and a traversal URL
  returned 404. The home page's "Open full page →" and "Go to <ID> →" links now resolve.

## Key decisions & tradeoffs
- Mockups come from `git show` at the snapshot SHA through one sandboxed route →
  [ADR-008](../decisions/ADR-008-mockups-served-from-git-in-a-sandbox.md).
- A choice the kit parser cannot read is quoted as written, never guessed →
  [ADR-009](../decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md).
- The compare overlay is pure Alpine and records nothing (read-only ruling). `x-if` rather than
  `x-show` so its frames cost nothing until opened. `x-trap` keeps focus in the dialog and returns it
  to "Compare two…" on close.
- The page is a Livewire component with no server actions. Livewire provides the layout, title and
  `wire:navigate`. All props are `#[Locked]` because `body` is printed raw.
- One git read of the story feeds both the body and the gate quote (`RenderStory::read()` split out
  of `handle()`).

## Known limitations & gotchas
- **Unparsed picks show unmarked.** Until the kit fix F-1 lands, `Chosen option: **D** (…)` gives no
  marked frame. It shows as a quoted amber note instead. On 2026-09-29 coins MOB-56 (D) and MOB-65
  (B) looked like this.
- The mockup CSP is `sandbox allow-scripts allow-popups`. Popups are allowed, so a mockup's
  `target=_blank` link can open a tab, but that tab inherits the opaque origin. There is no
  `allow-same-origin` or top navigation.
- Only `docs/mockups/<ID>/` at the ref is served. Mockups in any other directory, or not yet on the
  ref, read "No mockups" (off-ref mockups are SB-5).
- The sandbox gives the frame an opaque origin, so a mockup cannot use `localStorage` or cookies. A
  mockup that depends on them will throw inside its frame.
- Every frame is a separate PHP request and a `git show`. Side-by-side with many options leans on
  `PHP_CLI_SERVER_WORKERS` and `loading="lazy"`.
- In one-at-a-time mode the hidden frames are `x-show`, not `x-if`, so they still exist (lazily
  loaded) and switching is instant.

## Change history
2026-09-29 — Sandboxed raw-mockup route `mockups.file`, landed ahead of the mockup pick (SB-4, `817e411`; storyId shape check and `mockup_not_found` logging in `7f4d864`)
2026-09-29 — Story page (layout B), mockup panel, full-screen compare. Also fixed SB-3's carried WARNs (SB-4, `c7d383c`)
2026-09-29 — Compare frames load only while open; focus trapped in the dialog (SB-4, `e4c2d9e`)
