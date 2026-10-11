# Story page and mockups
Status: active   ·   Last updated: 2026-10-11   ·   Stories: SB-4, SB-5, SB-7, SB-8, SB-21, RD-3

## Overview
`/p/{project}/s/{storyId}` is one story, short first (RD-3, option A,
[`docs/mockups/RD-3/option-a.html`](../mockups/RD-3/option-a.html)). Top to bottom it shows the tags and title, one
summary line, the one action the story is waiting for (a mockup pick), its mockups in one sandboxed frame, and
every other detail folded below. Story files run to hundreds of lines, and a decision usually needs only what
the story is and which mockup to pick. The page renders in the RD-2 [dark shell](dark-shell.md). Since RD-3 it
can also make the pick, through the same path the [mockup gallery](mockup-gallery.md) viewer uses.

## How it works
**Routes** (`routes/web.php`):
- `stories.show`: `Route::livewire('/p/{project:name}/s/{storyId}', StoryPage::class)`, constrained to
  `[A-Z]{2,}-[0-9]+[a-z]?`, so a malformed ID is a routing 404 and never reaches the component.
- `mockups.file`: `GET /p/{project:name}/m/{storyId}/{file}` → `MockupFileController` (see
  [Serving a mockup file](#serving-a-mockup-file)).

**Mount.** An unknown or disabled project never reaches the page: the route's `EnsureProjectIsShown` refuses it
first (SB-7). `app/Livewire/Board/StoryPage.php:mount()`:
1. `ParseVersion` 404s a `?v=` that is not all digits (`board.version_rejected`).
2. `FindStoryVersion::handle()` picks the row: the requested off-main version, else the on-ref row, else the
   story's first off-main version (see [Versions](#versions-sb-5)). No row → `board.story_not_found` and 404.
3. `RenderStory::read()` reads the markdown from git once, at the row's `sha`. That one read feeds:
   - `RenderStory::toHtml()` → `body` (the Full story text).
   - `ReadMockupGate::handle()` → `gate` (`visual`, `chosen`, `why`, quoted as written; ADR-009).
   - `app/Actions/Board/ReadStoryOutline.php:handle()` → `outline`: the summary, the acceptance criteria and the
     Do NOT touch items. Each criterion and item goes through `RenderStory::inline()` (inline markdown, same
     safe options as `toHtml()`), because the view prints them raw.
   - `lines`, the file's line count, for the Full story text header.

Every public property is `#[Locked]`: `body` and the outline are printed as HTML. The project is the
`GuardsProjectPage` trait's locked `$project` (a name), and `hydrate()` re-checks it on every update.

**Summary line** (`ReadStoryOutline`). The story's own `Summary:` line if it has one. Otherwise the first sentence
of its `## Story` section's first paragraph (ending at `.`, `!` or `?` followed by a space or the end), logged as
`board.story_summary_fallback` (debug). Links keep their words, and emphasis and code marks are stripped. Over
`SUMMARY_MAX` (200) characters it is cut back to the last whole word and ends in "…". No `Summary:` and no
`## Story` → no summary line. Section matching is by heading prefix, any case, so `## Acceptance criteria
(executable …)` matches. Items are top-level `-`, `*` or `1.` lines, with indented continuations joined and `[ ]`
checkboxes dropped.

**Render.** `render()` re-reads the project and row, then loads:
- the mockup set, **for on-ref rows only**, from `ReadMockupSets::find()`. The set, the viewer and the pick all
  read the ref. The set includes the local-pick cache, so a pick made here shows at once, marked "not pushed yet";
- today's page, `ReadCurrentVersion::handle()` on the set's Where and sha. It is kept only when its state is `SHOT`;
- dependencies on the ref in this project (`story_id`, `title`, `status`);
- every version of the story, plus a count by location kind for the Other versions header.

**The view** (`resources/views/livewire/board/story-page.blade.php`):
- **Header.** Breadcrumb (project / Stories / ID); `x-shell.tag`s for the ID, the status (`needs` tone and
  "· awaiting your pick" when awaiting, `go` when built, `live` when approved), the initiative, and any parse
  errors; the title; `data-summary`. An off-main row adds the amber `data-offmain-shown` line, and a git read
  failure adds `data-read-failed`.
- **Primary action** (`data-primary-action`). Shown only when the set is `AWAITING` and the mockups are servable.
  It reads "Pick option <choice>" and calls `$wire.pick(choice, reason)`. There is no confirm dialog (the
  mockup shows none). The result is an inline `data-pick-done` line or a `data-pick-refusal` alert, not a
  toast, because the shell layout has no Flux toast host.
- **Mockups** (`data-story-mockups`). Rendered only when servable, which is the same rule as the file routes:
  the gate is visual, `mockups.dir` is exactly `docs/mockups/<ID>`, there are options, and the row is in git.
  - Alpine on `<main>` holds `view` (what the frame shows), `choice` (what a pick records), `reason` and `urls`.
  - The buttons (`data-mockup-option`, `aria-pressed`) are Today, when a shot exists, then each option. Today
    changes only `view`. An option sets both `view` and `choice`. The initial option is the picked one, else
    the first.
  - Picked state (`data-picked`) shows the letter, the reason, and "not pushed yet" for a local pick. An
    unparsed written choice is quoted (`data-chosen-text`) and no option is marked (ADR-009).
  - One iframe (`data-mockup-frame`, `sandbox="allow-scripts"`), scaled from 1280 px by the gallery's
    `mockupFit` Alpine helper. The Today pane reuses `x-board.mockup-current`.
  - "Full screen ↗" opens the selected option file, or the journey shot when Today is shown, in a new tab.
    "Side by side" links to the gallery viewer (`mockups.show`) and appears only when there is a set, so
    never on an off-main `?v=` row. Off-main rows show their mockups but offer no pick.
  - "Why this one?" (`#pick-reason`, `maxlength` = `StoryPickWriter::REASON_MAX`) appears only when awaiting.
- **Details.** Five native `<details>` (`data-detail="criteria|depends|untouchable|versions|text"`), all closed
  on load. Each header carries a count or summary, and "None…" when empty:
  - **Done when**: the criteria count, then the numbered list.
  - **Depends on**: each ID, linked with its title and status when it is on the ref, else "not on the board".
  - **Do not touch**: the item count, then the list.
  - **Other versions**: counts by kind, then the versions banner (`data-offmain-banner`).
  - **Full story text**: path and line count, then the ref@sha line, parse errors, and the rendered body.

**Picking** (`StoryPage::pick()` → `app/Actions/Board/PickMockup.php:handle()`). `PickMockup` is shared with the
gallery's `MockupViewer::pick()`. It re-reads the project, set and row on the server, because the browser can send
any option for any story. With no set it logs `board.mockup_pick_refused` and refuses. Otherwise it calls
`StoryPickWriter::pick()` and turns a `StoryPickRefusedException` into "Pick refused: <reason>.". In a `finally`
it always calls `ReadMockupSets::forget()`. It returns `{branch, refusal}`. The writer's guards and refusals are
unchanged (see [Mockup gallery](mockup-gallery.md) and ADR-032).

### Versions (SB-5)
A story can also exist off main: on an unmerged branch, in a worktree, or untracked (see
[Not on main](not-on-main.md)). Each version is its own `stories` row.
- `?v=<row id>` shows that version. The lookup is scoped to this project and story ID, so a row from another
  story or project is a 404.
- An off-main row gets the amber line (`Story::placePhrase()`) with a link to the version on main, or says there
  is none.
- `FindStoryVersion::others()` lists every *other* version in the Other versions detail. The ref's row reads "The
  version on main: <status>". An off-main row reads "<what> <where> — not on main", where *what* is `Exists
  only`, `Picked X`, the capitalised status, or `Changed`.
- An untracked version has no text or mockups on the board (`Story::isInGit()` is false). The page says to open
  the file in that checkout.
- Mockup URLs come from `Story::mockupUrl()`, which adds `v` for off-main rows, so the frame reads the branch's
  commit.

### Serving a mockup file
`app/Http/Controllers/MockupFileController.php:__invoke()` 404s a disabled project and calls
`app/Actions/Board/ReadMockupFile.php:handle()`:
1. Rejects a `storyId` not shaped like an ID, and any `file` that is not plain segments (`isPlainRelativePath()`:
   no `.`, `..`, hidden files, `//`, backslashes, NULs, or anything over 255 bytes). A rejected path logs
   `board.mockup_path_rejected`.
2. Takes the **directory from the snapshot**, not the URL. With `?v=`, the row must be a `branch` or `worktree`
   version of this project and story; untracked rows are never served. `mockups.dir` must equal
   `docs/mockups/<storyId>`.
3. Reads `<dir>/<file>` with `GitReader::show()` at the row's `sha`. It never reads the working tree.

Any failure is a 404. The response carries an allow-listed content type, a CSP `sandbox` (an opaque origin even
when the file is opened directly in a tab, as "Full screen" does), `nosniff`, `no-referrer` and
`private, max-age=60` ([ADR-008](../decisions/ADR-008-mockups-served-from-git-in-a-sandbox.md)).

## Data model
No migrations. It reads `projects` and `stories` rows on the ref and off main (`mockups` JSON, `depends_on`,
`parse_errors`, `sha`, `location_kind`, `location`, `branch`). Story text and mockup bytes come from git on
request. The only write is the pick: two lines of the story file and one local commit in the project, made by
`StoryPickWriter` (ADR-032).

## Interfaces
- `GET /p/{project}/s/{storyId}[?v=<row id>]` (`stories.show`) → `App\Livewire\Board\StoryPage`. 404 for an
  unknown or disabled project, an unknown story, a malformed ID, or a `v` that is not a version of this story.
- Livewire action `StoryPage::pick(string $option, string $reason)`. It sets `done` ("Picked option B, committed
  on <branch> (not pushed).") or `refusal`.
- `PickMockup::handle(string $projectName, string $storyId, string $option, string $reason): array{branch: ?string, refusal: ?string}`.
- `ReadStoryOutline::handle(string $markdown, array $context = []): array{summary: ?string, criteria: list<string>, untouchable: list<string>}`.
- `RenderStory::read(Story): ?string`, `toHtml(string): string`, `inline(string): string`.
- `GET /p/{project}/m/{storyId}/{file}[?v=<row id>]` (`mockups.file`) → raw bytes or 404.
- Blade `x-shell.tag` (`tone`: `needs|go|live|fail|null`, `mono`): the shell's outlined pill.
- Test hooks: `data-tag`, `data-summary`, `data-primary-action`, `data-story-mockups`, `data-mockup-option`,
  `data-mockup-frame`, `data-full-screen`, `data-picked`, `data-chosen-text`, `data-pick-done`,
  `data-pick-refusal`, `data-detail`, `data-offmain-shown`, `data-offmain-banner`, `data-read-failed`.
  `data-story-mockups` is not `data-mockups`. See the RUNBOOK for why.

## Configuration
None of its own. The frame leans on `PHP_CLI_SERVER_WORKERS` like every page with frames.
`@tailwindcss/typography` (`prose-invert`) styles the story text.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.story_viewed` | info | `StoryPage::mount()` | `project`, `story`, `version` (row `location`; null on the ref) |
| `board.story_summary_fallback` | debug | `ReadStoryOutline::handle()` | `project`, `story` |
| `board.story_not_found` | info | `StoryPage::mount()` | `project`, `story`, `v`, `reason` |
| `board.version_rejected` | warning | `ParseVersion` (from `mount()`), `MockupFileController` | `project`, `story`, `v` |
| `board.story_read_failed` | warning | `RenderStory::read()` | `project`, `story`, `error` |
| `board.mockup_pick_refused` | warning | `PickMockup` (no set), `StoryPickWriter::pick()` (its guards) | `project`, `story`, `reason` |
| `board.mockup_picked` | info | `StoryPickWriter::pick()` | `project`, `story`, `option`, `commit` |
| `board.project_page_refused` | info | `EnsureProjectIsShown` | `project`, `reason` |
| `board.mockup_served` / `mockup_not_found` / `mockup_path_rejected` | debug / info / warning | `ReadMockupFile` | `project`, `story`, `file` |

Every line carries `request_id`. A healthy view logs one `story_viewed`, usually one
`story_summary_fallback`, and one `mockup_served` for the frame (its own request). **`story_summary_fallback`
firing on most stories is expected** until the kit's `/story` writes a `Summary:` line (F-6). A pick logs
`mockup_picked` or `mockup_pick_refused` in the Livewire update's request. Any `mockup_path_rejected` means a URL
the board never builds.

## Testing & verification
- `tests/Feature/Board/StoryPageTest.php`: one `it()` per acceptance criterion, named as written, plus the
  no-summary case, the shell and tags, Depends on links, one sandboxed frame with no `allow-same-origin`, no Today
  without a shot, a quoted unparsed choice, a no-set refusal, view logging, disabled project and malformed ID,
  and an unreadable story. Picks run against a real fixture git repo and assert the commit subject.
- `tests/Browser/StoryPageTest.php`: switches options without a page load and picks B with a reason into the
  story file; a long built story fits one screen with Details closed, at 1280 and 375 px.
- `tests/Feature/Board/NotOnMainPageTest.php` (versions), `MockupFileTest.php` (serving and traversal),
  `tests/Browser/MockupSandboxTest.php` (opaque origin).
- `tests/Feature/Board/AppShellTest.php` checks the story page's sidebar through the shell's hooks
  (`data-nav-project`, "Open menu").
- Acceptance criteria: see `stories/redesign/RD-3-short-story-page.md`.

## Key decisions & tradeoffs
- One pick path for both pick surfaces, `PickMockup` →
  [ADR-042](../decisions/ADR-042-one-pick-action-shared-by-story-page-and-viewer.md).
- The page is replaced in place, trimmed to the approved mockup, with native `<details>` →
  [ADR-043](../decisions/ADR-043-short-story-page-replaces-the-long-one-in-place.md).
- Mockups come from `git show` through a sandboxed route →
  [ADR-008](../decisions/ADR-008-mockups-served-from-git-in-a-sandbox.md). An unparsed choice is quoted, never
  guessed → [ADR-009](../decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md).
- The status label is built as one PHP string inside the tag, not with an inline `@if` (RUNBOOK).
- The option buttons are Alpine and the Details are native, so only the pick calls the server.

## Known limitations & gotchas
- Not built (out of scope, shown dashed in the mockup record): "Approve without a pick", "Edit story", and
  "Cost & history". No writer or data exists for them.
- Most stories have no `Summary:` line, so the summary is the first sentence of `## Story` (F-6).
- The old compare overlay, the width toggle (375/768/1280), the approved-only "Copy `/build`" button and the
  dark toggle are gone. Comparing is in the gallery viewer ("Side by side").
- Off-main `?v=` rows show mockups but no pick and no "Side by side": the set and the writer read only the ref.
- The Today button appears only when `ReadCurrentVersion` finds a matching journey shot.
- The story modal (`StoryModal`) still shows the long form until RD-9 deletes it.
- The shell sidebar has no Production item, so `/prod` is reachable from here only through the old pages (F-7).
- The sandbox gives frames an opaque origin, so a mockup cannot use `localStorage` or cookies. `?v=` IDs change
  on every refresh, so old `?v=` links 404.

## Change history
2026-09-29 — Sandboxed raw-mockup route `mockups.file` (SB-4, `817e411`, `7f4d864`)
2026-09-29 — Story page (layout B), mockup panel, full-screen compare; compare frames behind `x-if` (SB-4, `c7d383c`, `e4c2d9e`)
2026-09-29 — Versions: `?v=`, the off-main line and banner, branch mockups served (SB-5, `7165b18`, `f83e02e`)
2026-09-29 — Refused before binding by `EnsureProjectIsShown`; sidebar shell (SB-7)
2026-09-29 — Version picking moved to `FindStoryVersion`, shared with the story modal (SB-8, `f601c01`)
2026-09-29 — Picking moved to the gallery viewer (SB-21, `486be22`)
2026-10-11 — Short story page in the RD-2 shell: tags, title, summary line, Pick, one mockup frame with Today, folded Details; `PickMockup` shared with the viewer; `ReadStoryOutline`, `RenderStory::inline()`, `x-shell.tag`. Compare overlay, `/build` button and dark toggle removed (RD-3, `0e8542b`)
