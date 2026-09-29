# Story modal
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-8

## Overview
Clicking any story row on `/` or `/p/{project}` opens a large modal with everything about that story:
its text, details, dependencies, mockup thumbnails and off-main versions. It replaced SB-3's
expand-in-place rows, which pushed the list around and showed only part of the story. The modal is
URL-addressable (`?story=<project>/<ID>`), so a reload or a shared link lands on the same story and
Back closes it. Layout is design A, view 3 (`docs/mockups/SB-7/option-a.html`). The full story page
(`/p/{project}/s/{id}`) stays for comparing mockups side by side.

## How it works
**Opening from a row.** `resources/views/components/board/story-row.blade.php` renders each row as a
`<button>` when `Story::hasPage()` is true (the ID matches `Story::ID_PATTERN`). Clicking it
dispatches the window event `board-story` with `<project>/<ID>`. The list itself makes no round
trip, so it keeps its scroll position. A row whose ID is not well formed (an oddly named mockup
folder off main) cannot form a link, so it renders as a plain `<div>`.

**The modal shell.** `Home` embeds `<livewire:board.story-modal />` once
(`resources/views/livewire/board/home.blade.php`), so it is on both `/` and `/p/{project}`. Open and
closed is Alpine state in the view's root `x-data`, over the server's `rowId`:
- `show(link)` pushes a history entry with `?story=`, increments `depth`, shows the modal at once
  with a skeleton, and calls `$wire.open(link)`.
- `close()` (Escape, the Close button, a backdrop click) steps back through the `depth` entries it
  pushed. A modal opened directly from a URL (`depth === 0`) closes with `$wire.close()` instead.
- `popped()` (Back/Forward) re-reads `?story` from `location` and loads or closes.

This is why `StoryModal::$story` is `#[Url(except: '')]` without `history: true`: Livewire pushed its
entry only after the round trip, and that race sent the browser to `about:blank`
([ADR-015](../decisions/ADR-015-story-modal-history-is-driven-by-alpine.md)). `x-trap.noscroll` moves
focus to Close (`autofocus`), keeps it in the dialog, and returns it to the row on close.

**Resolving the link.** `app/Livewire/Board/StoryModal.php` sends every way in (`mount()` for a
direct URL, `open()` from a row or chip, `updatedStory()` for a browser edit of `story`) through
`show()`, which calls `app/Actions/Board/ResolveStoryLink.php:handle()`. That is the one parser of
`?story`. It checks, in order:
1. The shape `<project>/<ID>`, with a project name that starts with a letter or digit (so `..` fails),
   and the ID against `Story::ID_PATTERN`. Failure → `malformed`, before any query or git.
2. `CheckProjectShown::refusal()` (SB-7) → `unknown` or `disabled`.
3. `FindStoryVersion::handle()` → `unknown` if there is no row.

A refusal sets `refusal` to a sentence shown as a dismissible status line above the list ("No story
coins/NOPE-1 on the board."). No modal opens.

**Which version.** `app/Actions/Board/FindStoryVersion.php` (moved out of `StoryPage`, which now
calls it too) picks the ref's row, else the first off-main version (branch, then worktree, then
untracked). `others()` builds the "Versions off main" lines. The link carries no version, so an
off-main row of a story that is also on main opens the main version, and the branch copy is listed
in the rail with a link to the full page's `?v=`
([ADR-016](../decisions/ADR-016-one-story-link-parser-and-one-version-picker.md)).

**Content.** On a resolved story, `show()` reads the markdown once with `RenderStory::read()` (git
at the row's `sha`), renders it with `RenderStory::toHtml()` into `body`, and quotes the gate with
`ReadMockupGate::handle()`. `read()` returns null for an untracked row, so the modal says where the file lives instead of showing text.
`render()` reloads the row by `rowId` and passes:
- `known`: which `depends_on` IDs have any version in the same project. Those chips are buttons that
  call `show()` for that story. The others are plain text.
- `versions`: `FindStoryVersion::others()`.

The view (`resources/views/livewire/board/story-modal.blade.php`) has a header (ID, status chip,
title, "Open full page →" with `?v=` for an off-main row, Close) and a `data-story-grid` of the text
beside a 20rem rail from 768px (`md:grid-cols-[1fr_20rem]`), one column below. The rail holds
Details (status, initiative, journey, source date, depends on, parse errors, path, version as ref
or branch @ SHA), then Mockups and Versions off main. An off-main story shows a "Not on main — <location>" banner.
Thumbnails are sandboxed iframes of `Story::mockupUrl()`, sized by `.mockup-thumb-box` /
`.mockup-thumb` in `resources/css/app.css`. The chosen one (from the kit parser's `mockups.chosen`
only) is ringed and badged. A choice the parser could not read is quoted from the gate and no
thumbnail is marked ([ADR-009](../decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md)).
While `open` or `story` is in flight, `wire:loading` swaps the header and body for skeletons of the
same size.

## Data model
None owned; no migrations. Reads `projects` and `stories`. Story text and mockups are read from git
on demand, as before ([ADR-007](../decisions/ADR-007-story-text-read-on-expand.md), whose read-on-
demand rule now applies to the modal instead of an expanded row).

## Interfaces
- Query string `?story=<project>/<ID>` on `GET /` and `GET /p/{project}`.
- Window event `board-story` with detail `<project>/<ID>`: anything that opens the modal must
  dispatch this rather than call `$wire.open()`, so the history count stays right.
- `StoryModal`: `#[Url] story`, `open(string $story)`, `close()`. `#[Locked]` `rowId`, `body`, `gate`,
  `refusal`. `body` is printed raw, so the lock is what keeps the browser from setting it.
- `ResolveStoryLink::handle(string): array{story, project, id, reason}`, with `reason` one of
  `MALFORMED`, `UNKNOWN`, `DISABLED` or null.
- `FindStoryVersion::handle(Project, string $storyId, ?int $version)`, `all()`, `others()`.
- Removed: `Home::expand()` and `Home::$bodies`.
- Test hooks: `data-story-link`, `data-story-open`, `data-story-refused`, `data-story-backdrop`,
  `data-story-grid`, `data-dep` / `data-dep-missing`, `data-modal-mockups`, `data-thumb` /
  `data-chosen`, `data-chosen-text`, `data-offmain-shown`, `data-versions`.

## Configuration
None.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.story_modal_opened` | info | `StoryModal::show()` | `project`, `story`, `version` (`ref` or the `location_kind`) |
| `board.story_modal_refused` | info | `ResolveStoryLink::refuse()` | `project` (null if malformed), `story` (the raw value, cut to 80 chars, if malformed), `reason` |
| `board.story_read_failed` | warning | `RenderStory` | `project`, `story`, `error` |

Every line carries `request_id`. A healthy open is one `story_modal_opened`. A link that "does
nothing" should have a `story_modal_refused` with its reason. An edit to a `#[Locked]` property
logs nothing: Livewire throws before app code runs. History bugs (the modal will not close, Back
leaves the board) are client-side and leave no log line; see the RUNBOOK entry for 2026-09-29.

## Testing & verification
- `tests/Feature/Board/StoryModalTest.php`: one `it()` per acceptance criterion (ACQ-20 with chips
  and A chosen, following a dependency, CT-1 worktree-only with the banner, `unknown` / `malformed`
  / `disabled` refusals, the unparsed-choice quote), plus a dependency outside the project, a
  malformed value from `open()`, versions off main, the opened log line, close, Back setting `story`
  empty, the locked properties, the project page, and rows as buttons.
- `tests/Browser/StoryModalTest.php`: click a row, keep scroll, Back closes (also after arriving
  through the sidebar); Escape clears `?story` and returns focus; direct URL at 375px is one column
  and follows a chip; two columns from 768px and a backdrop close.
- The expand-in-place tests in `HomePageTest` and the expand guard in `GuardLoggingTest` were
  removed with the feature.
- Browser check (story): on `/` click a Ready to build row, see the modal, press Back, and see the
  list at the same scroll. Open `/?story=coins/ACQ-20` directly and see A marked chosen. Check 375px.

## Key decisions & tradeoffs
- History is pushed by Alpine before the server call, not by `#[Url(history: true)]` →
  [ADR-015](../decisions/ADR-015-story-modal-history-is-driven-by-alpine.md).
- One `?story` parser, one version picker shared with the story page, and a link with no version →
  [ADR-016](../decisions/ADR-016-one-story-link-parser-and-one-version-picker.md).
- Dependency chips open any version in the same project, while the story page still links only
  to dependencies on main.
- Deliberate deviations from the mockup: the close button reads "Close" (the story's label), not
  "Close story", and the two-column breakpoint is 768px (the story's), not 1024px.
- The modal is read-only. Picking a mockup is SB-13.

## Known limitations & gotchas
- **Off-main rows with malformed IDs are not clickable.** On 2026-09-29, 23 real rows had IDs that
  cannot form a link (17 untracked coins, 3 coins worktrees, 2 coins branches, 1 untracked
  client-dashboard, all from oddly named mockup folders). Five of them (branch and worktree) showed
  thumbnails when rows expanded in place; they now have no view on the board. Owner decision
  pending.
- Clicking an off-main row of a story that is also on main opens the main version. The branch copy
  is one more click, via the rail.
- The story's own `Status:` / `Journey:` metadata lines render as the first paragraph of the text
  (existing `RenderStory` behaviour, same on the story page).
- "Open full page →" is hidden below 640px (`sm:inline-flex`).

## Change history
2026-09-29 — Story modal at `?story=<project>/<ID>` replaces expand-in-place; `ResolveStoryLink`, `FindStoryVersion` shared with the story page (SB-8, `f601c01`)
