# Mockup gallery
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-21

## Overview
`/mockups` lists every mockup set across the enabled projects as live-thumbnail cards, with the sets
awaiting a pick first. A card opens a full-screen viewer with option tabs, a width switch and side-by-side
compare. For a set awaiting a pick, the viewer has **Pick a / b / c**. Before this, a set was opened
by path and a pick meant editing the story file by hand. Nothing showed which sets were still waiting.
The pick is also the board's **first write into a registered project**. It is narrowed to two lines of
one file and one local commit ([ADR-032](../decisions/ADR-032-the-board-writes-narrowly-one-pick-one-file-one-commit.md)).
The layout is mockup option A (grid + lightbox).

## How it works
**Routes** (`routes/web.php`):
- `mockups`: `GET /mockups` → `App\Livewire\Board\MockupGallery`. `?project=<name>` sets the starting filter.
- `mockups.show`: `GET /mockups/{project}/{story}` → `App\Livewire\Board\MockupViewer`, behind `EnsureProjectIsShown`.
- `mockups.frame`: `GET /mockups/{project}/{story}/file/{file}` → `MockupSetFileController`, also behind
  `EnsureProjectIsShown`. The story asked for `mockups.file`, but SB-4's story-page route already has that
  name (`/p/{project}/m/{storyId}/{file}`). **Later stories (SB-23, SB-24) must use `mockups.frame`.**

**Which sets exist.** Everything goes through `app/Actions/Board/ReadMockupSets.php`: the gallery, the
viewer, the file route, the sidebar count and the project page button. SB-24's app map is meant to use it too.
- `candidates()`: one query for on-ref rows whose snapshot `mockups.dir` is exactly
  `docs/mockups/<story_id>` and whose options list is not empty. This is the same rule both file routes enforce.
- `readings()`: reads each story file with **one** `GitReader::showMany()` (`git cat-file --batch`)
  per commit. From the text it takes:
  - the gate quote (`ReadMockupGate`);
  - whether the board can pick it (`StoryPickWriter::refusalFor() === null`);
  - the **Where**: the first backticked `/path` on the story's `- Routes:` line.

  The result is cached as `board:mockup-gates:{project_id}:{sha}` for a day. The batch read exists because
  coins has 263 sets, and one `git show` per set was too slow.
- `build()` sorts each set into a state:
  - **picked**: the kit parser's `mockups.chosen` letter.
  - **awaiting**: no letter, status `draft`/`approved`, and the text can be picked.
  - **other**: anything else. If the story wrote a choice the parser cannot read, it is quoted as
    `recorded` and never turned into a letter ([ADR-009](../decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md)).

  Sort order: awaiting first, then newest `dated_on`, then higher ID.
- `withLocalPicks()`: a board pick is committed but not pushed, so the ref still reads "awaiting".
  For **awaiting sets only**, the gallery re-reads those stories on the checkout's local branch
  (`StoryPickWriter::boardBranch()`, e.g. `origin/main` → `main`). A set whose story now holds a single
  letter becomes picked with `pushed: false` ("Picked X · not pushed"). This is cached 300 s as
  `board:mockup-local:{project_id}`, fingerprinted on the project sha plus the awaiting paths, and
  `forget()` drops it after every pick attempt.

**Gallery** (`resources/views/livewire/board/mockup-gallery.blade.php`, Alpine `mockupGallery` in
`resources/js/mockup-gallery.js`). Sets are grouped by project, and each card holds a live thumbnail
frame (`mockupFit` scales a 1280 px page into the card). Filters for status, project and search over
ID and title run in Alpine over the single render, and the component has no actions. The status filter
opens on "Awaiting pick" (the sets that need the owner) only if a set awaits in the project the page
opens on (`?project=`, or any project when none is given); otherwise it opens on "All". Counting other
projects would open a project with nothing awaiting on the "No mockup sets match" state; "Clear filters" resets to All. Each group shows
its first 12 cards until "Show all". A search or status filter shows every match. With no sets it shows
the empty state (`data-mockups-empty`), which says mockups come from `docs/mockups/<ID>/`.

**Viewer** (`MockupViewer`, `mockup-viewer.blade.php`, Alpine `mockupViewer`):
- **Header and text.** The title bar shows ID and title, with the state badge (`board/mockup-state`) and
  the position among the project's sets. The description comes from `ReadMockupSets::describe()`: the set's
  `index.html` `<meta name="description">`, else the first paragraph of the story's `## Story`. **Where**
  reads "not named in the story" when the story has no Routes path.
- **Tabs and width.** Tabs are Current / A / B / C. Current is the `board/mockup-current` placeholder until SB-23.
  Switching tabs swaps an iframe `src` in Alpine, with no page load. The width switch is 1280 / 768 / 375,
  and the frame keeps its real viewport width, so the mockup's own breakpoints apply.
- **Side by side.** Two panes, each with its own picker, plus **⇄ Swap**. Below 768 px the panes stack
  (`grid-rows-2`, then `md:grid-cols-2`) and Swap is the "toggle". That follows the mockup; it is not a
  one-pane-at-a-time switch. The panes are `x-if`, so their frames load only while compare is open
  (see RUNBOOK, "two extra mockup requests").
- **Keys.** Esc closes the pick dialog, or else goes back to the gallery. ←/→ step through the project's
  sets, ignored while focus is in an input, select or textarea.
- **Pick.** Pick opens a dialog with a one-line reason field. `confirmPick()` calls the component's one
  server action, `pick($option, $reason)`. That action re-reads the set and row server-side, calls
  `StoryPickWriter::pick()`, and always calls `forget()`. The result is a success toast ("… committed on
  <branch> (not pushed)"), or `refusal` shown in the dialog (`data-pick-refusal`). `hydrate()` re-checks the
  project is still shown on every update (ADR-019 amendment).

**Writing the pick** (`app/Services/StoryPickWriter.php:pick()`). Guards run in this order. Each one logs
`board.mockup_pick_refused` and throws `StoryPickRefusedException` before anything is written:
1. project off the board;
2. option not in the set;
3. ref already records a letter;
4. status not `draft`/`approved`;
5. file not a plain `stories/**.md` inside the checkout (symlinks refused, `storyPath()`);
6. rebase, merge, cherry-pick, revert or bisect in progress (marker files in the git dir);
7. checkout on a different branch from the one the board reads (the reason names both);
8. the file shows in `status --porcelain` (modified, staged or untracked);
9. `refusalFor()`: no gate section, `n/a` gate, no `Chosen option:` line, or a Chosen value that is not
   a placeholder (empty, `_pending_`, `-`/`—`, `TBD`, or the template's `_(filled AFTER …)_`).

The gallery's "awaiting" uses the same `refusalFor()`, so gallery and writer cannot disagree.

`rewrite()` replaces only the Chosen value and the Why value in place, including their indented
continuation lines. If there is no Why line, it inserts one under Chosen. The reason is written as
`owner pick <Y-m-d> (board): <reason>` (`reasonLine()`: whitespace collapsed, cut to 200 characters). An
empty reason is written as `owner pick <date> (board), no reason given.` The commit is
`git -c core.fsmonitor=false commit --quiet --no-verify -m "docs(<ID>): record mockup pick <x>" --only -- <path>`
with a 30 s timeout, so other staged work stays staged and out of the commit. If the commit fails
(e.g. `index.lock`), the original bytes are written back. Nothing is ever pushed.

**Serving a frame** (`app/Actions/Board/ReadMockupSetFile.php:handle()`). The route finds the set, lists
`<dir>/` at the set's commit, and requires `<dir>/<file>` to be **exactly** in that listing. It never
joins a path, so `../../.env` or `%2e%2e` is simply not in the listing. A miss logs
`board.mockup_file_refused` and 404s without calling `show`. The response reuses
`MockupFileController::TYPES` and sends `Content-Security-Policy: sandbox allow-scripts; default-src …;
frame-ancestors 'self'`. Unlike SB-4's route, this CSP does **not** include `allow-popups`.

**Entry points.**
- The sidebar has a **Mockups** link with a `pick`-toned awaiting count (`Sidebar::render()` →
  `awaitingCount()`).
- The project page header has a **Mockups N** button, shown only when `countFor()` > 0 (a snapshot count, no git).
- The story modal has **Open in mockup gallery**, only for the on-ref version (the viewer reads the ref).

## Data model
No schema change. Reads `projects` and on-ref `stories` rows (`mockups` JSON `dir`/`options`/`chosen`,
`status`, `path`, `sha`, `dated_on`). Two cache keys, `board:mockup-gates:{id}:{sha}` and
`board:mockup-local:{id}`. Writes one story file in a project's checkout (the pick only).

## Interfaces
- `ReadMockupSets::handle(?Project): list<MockupSet>`, plus `find()`, `awaitingCount()`, `countFor()`,
  `describe()` and `forget()`. The `MockupSet` shape is the `@phpstan-type` in the class docblock.
- `StoryPickWriter::pick(Project, Story, list<string> $options, string $option, string $reason): string`
  (commit sha), which throws `App\Exceptions\StoryPickRefusedException`. Its pure helpers are `refusalFor()`,
  `pickedLetter()`, `boardBranch()` and `reasonLine()`.
- `GitReader::showMany(path, ref, files): array<path, ?bytes>` (one `cat-file --batch`). It refuses a path
  containing a newline. `GitReader::run()` gained an optional stdin `$input`. Both are reads only, and the
  allow-list is unchanged.
- `MockupViewer::pick(string $option, string $reason)` is the only Livewire action. `$project`, `$story` and
  `$refusal` are `#[Locked]`.
- Test hooks: `data-set`, `data-state`, `data-filter-status`, `data-filter-project`, `data-show-all-sets`,
  `data-mockups-empty`, `data-viewer`, `data-viewer-frame`, `data-viewer-description`, `data-viewer-where`,
  `data-option-tab`, `data-width`, `data-compare-toggle`, `data-compare-pane`, `data-compare-swap`,
  `data-pick`, `data-pick-dialog`, `data-pick-refusal`, `data-current-placeholder`, `data-sidebar-mockups`,
  `data-awaiting-count`, `data-project-mockups`, `data-open-gallery`.

## Configuration
None of its own. Frames lean on `PHP_CLI_SERVER_WORKERS` like the story page.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.mockups_viewed` | info | `MockupGallery::mount()` | `sets`, `awaiting`, `project` (starting filter or null) |
| `board.mockup_viewed` | info | `MockupViewer::mount()` | `project`, `story`, `option`, `compare` |
| `board.mockup_picked` | info | `StoryPickWriter::pick()` | `project`, `story`, `option`, `commit` |
| `board.mockup_pick_refused` | warning | `StoryPickWriter::pick()`, `MockupViewer::pick()` | `project`, `story`, `reason` |
| `board.mockup_file_refused` | warning | `ReadMockupSetFile` | `project`, `path` |
| `board.mockup_not_found` | info | `ReadMockupSetFile`, `MockupViewer::mount()` | `project`, `story`, `reason`, `file`? |
| `board.mockup_served` | debug | `ReadMockupSetFile` | `project`, `story`, `file`, `bytes` |
| `board.mockup_sets_unreadable` | warning (info for the local-branch check) | `ReadMockupSets` | `project`, `ref` or `story`, `error` |

Every line carries `request_id`. A healthy viewer open logs one `mockup_viewed` and then one `mockup_served`
per visible frame, each in its own request. If a pick went wrong, find its `mockup_pick_refused` line: the
`reason` is the same sentence the dialog showed. A `mockup_file_refused` is a URL the board never builds.
When `mockup_sets_unreadable` fires, sets still list from the snapshot but lose reasons and Where until
git reads again.

## Testing & verification
- `tests/Feature/Board/MockupGalleryTest.php` has one `it()` per gallery/viewer acceptance criterion. It
  also covers the Story-line fallback, the sidebar count, the modal and project-page links, git failure,
  and `showMany()`.
- `tests/Feature/Board/MockupPickTest.php` has one `it()` per pick criterion, run against a throwaway fixture
  repo. It also has one per extra guard: merge in progress, unknown option, missing file, status,
  project off, symlink, commit failure restoring the file, and Why line insertion.
- `tests/Browser/MockupGalleryTest.php` walks gallery → viewer → switch option → compare → 375 px stack →
  Esc, and picks b in a fixture checkout.
- Picks are never tested on a real project.

## Key decisions & tradeoffs
- The board writes, narrowly: one gateway, two lines, one file, one local commit, never pushed, and
  refusal before any write → [ADR-032](../decisions/ADR-032-the-board-writes-narrowly-one-pick-one-file-one-commit.md).
- **Open question for the owner:** the commit passes `--no-verify`, which skips the project's own git hooks.
  The build treated that as the same principle as `core.fsmonitor=false` ("the board runs git, never a
  project's helpers"). It is a judgement call and has not been confirmed. If a project relies on a
  commit-msg or pre-commit hook for story files, drop the flag.
- Only git results are cached; set rows are read fresh on every call. Caching whole sets made the query
  count differ between cold and warm loads, which broke the "same number of queries" tests (see RUNBOOK).
- Local picks are checked only for awaiting sets, so the extra git cost is bounded by what is actually waiting.
- Files are allowed by exact match against the listing rather than by path validation. This is stricter
  than SB-4's `isPlainRelativePath()` and needs no traversal rules.

## Known limitations & gotchas
- **A pick is visible in two places at different times.** The gallery shows "Picked X · not pushed" at
  once. The story modal, story page and What needs me read the ref, so they show it only after a push
  and a refresh.
- A pick committed **outside** the board (by hand in the checkout) can take up to 300 s to show in the gallery.
- The sidebar's awaiting count checks placeholders, but the What needs me pick card does not, so the two
  can differ slightly.
- Re-picking, clearing, and "Neither / park" are not available from the board. The writer refuses any
  Chosen value that is not a placeholder.
- If the checkout is behind the ref, the pick is refused only when the ref already has a letter. Other
  changes on the ref can conflict with the pick commit on pull.
- The Current tab is a placeholder until SB-23.
- The frame CSP has no `allow-popups`, so a mockup's `target=_blank` links do nothing.
- Every thumbnail is a live iframe and a PHP request. The 12-per-group cap is what keeps `/mockups` usable.
- SB-13 ("Pick a mockup from the board", approved) overlaps this story's pick. It has not been rescoped.

## Change history
2026-09-29 — Gallery, full-screen viewer, compare, pick from the board; `mockups.frame` route;
`GitReader::showMany()`; `StoryPickWriter`; sidebar/project page/modal entry points (SB-21, `486be22`)
2026-09-29 — Gallery opens on "Awaiting pick", falling back to All when none await (SB-21, `b1a3ab7`)
2026-09-29 — Opening status counts only the project the page opens on, so `?project=X` with nothing awaiting in X opens on All (SB-21, `680b4db`)
