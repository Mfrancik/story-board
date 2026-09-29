# Project handbook
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-14

## Overview
`/p/{project}/handbook` shows how one project is run: its rules (`CLAUDE.md`), lessons, standards,
runbook, decisions and skills, read from git at the project's snapshot SHA. Every project starts from
the dev-standards kit and then drifts, so each standards file and each kit skill carries a badge against
the kit: Same as kit, Changed in this project, Missing, or Project only. Before this, drift was
invisible unless you opened each repo. Design A, mockup option a (`docs/mockups/SB-14/option-a.html`).

## How it works
**1. Route and mount.** `routes/web.php` registers `projects.handbook` behind `EnsureProjectIsShown`
(ADR-013), so an unknown or disabled project 404s before the component mounts.
`app/Livewire/Board/ProjectHandbook.php:mount()` stores the project **name** (not the model) and calls
`app/Actions/Board/ResolveKit.php:handle()` once per visit. That checks `board.kit_path` is a directory
and a git repo, then resolves `board.kit_ref` to a SHA. Any failure logs `board.kit_unreachable` and
returns `sha: null`, which hides every badge and shows "Kit not found at <path> — comparison
unavailable". Everything else still renders. A `?decision=<file>` in the URL makes Decisions the first
tab and opens that file.

**2. First render, then no more renders.** `render()` fills only the first tab (Rules, or Decisions)
server-side. Every other tab is Alpine: the first time it opens, `go()` calls the `#[Renderless]`
`loadSection()`, and the returned HTML is kept in Alpine's `bodies` and injected with `x-html`. The
component never re-renders after the first response, so opening a later tab never resends sections
already loaded (coins' RUNBOOK is about 20,000 lines). See
[ADR-024](../decisions/ADR-024-handbook-sections-load-lazily-and-stay-client-side.md). A failed call
removes the tab from `loaded`, so the next click retries.

**3. Reading a section.** `ProjectHandbook::section()` logs `board.handbook_viewed`, then maps the
section to a partial in `resources/views/livewire/board/handbook/` and a reader on
`app/Actions/Board/ReadHandbook.php`:
- **Rules / Runbook:** `page()` renders `CLAUDE.md` / `docs/RUNBOOK.md` whole, through
  `RenderStory::toHtml()` (escaped HTML input, no unsafe links).
- **Lessons:** `lessons()` → `parseLessons()` splits `docs/LESSONS.md` into `## L-<n>` entries (number,
  date, name, `Scope:` line, rendered body), highest number first. It strips `<!-- -->` comments and
  tracks ``` / ~~~ fence state before matching a heading, so the kit's commented entry template is not
  counted (see RUNBOOK).
- **Standards:** `standards()` lists `docs/standards/*.md` directly in the folder (not subfolders), in
  the project and the kit, one sub-tab per file. A changed file can show the kit's text below its own,
  and a missing one shows the kit's text alone.
- **Decisions:** `decisions()` lists the file names directly in `docs/decisions/`, newest name first.
  Every name is sent once. Paging (`DECISIONS_PAGE`) and the file-name filter are Alpine over the full
  list, with no round trip.
- **Skills:** `skills()` lists folders under `.claude/skills/`, each marked from the kit or
  project-only, and badges a kit skill by its `SKILL.md`.

`ReadHandbook::read()` lists a file before `show`ing it, so a missing file (null → the
`board/handbook-empty` state naming the file) is told apart from a failing repo (`GitReaderException`).
A project with no snapshot SHA yet gets the `unread` partial and a `no_snapshot` refusal, with no git
call. A git failure on the project renders the `failed` partial for that section only and logs
`board.handbook_read_failed`. The other sections still load.

**4. Kit badges.** `ReadHandbook::badge()` compares the file bytes from `GitReader::show()`. Equal
bytes are the same blob, because git names a blob by a hash of its bytes. `GitReader::storyBlobs()` only
covers `stories/`, and `GitReader` was not changed. All the kit reads for a section run inside one
`guardKit()` call, so a kit that fails partway through a section (moved or deleted after the page
loaded) hides **every** badge in that section, never some of them. It logs `board.kit_unreachable`.
A kit skill folder whose `SKILL.md` is missing on one side is badged Changed, not Missing.

**5. Decisions modal.** A decision row dispatches `handbook-decision`. The page calls the renderless
`openDecision()` and shows the HTML in the design-A centred modal. `?decision=` is written with
`history.replaceState`, so a reload or shared link lands on the same file, but opening a decision adds
no Back step. Escape or the backdrop closes it. `ReadHandbook::isSafeDecisionName()` refuses names that
are empty, contain `..`, `/` or `\`, start with `-`, or do not end in `.md`, **before** any git call
(`board.handbook_refused`, reason `bad_path`). A safe name not in the tree is `not_found`.

**6. Mid-visit re-check.** `hydrate()` runs `CheckProjectShown::refusal()` on every Livewire request,
because route middleware never sees update requests (ADR-019 amendment). A project switched off or
removed mid-visit logs a refusal and redirects home, and the pending call returns nothing.

**Page tabs.** `resources/views/components/board/project-tabs.blade.php` puts Dashboard | Handbook
links under the project header on both `/p/{project}` and the handbook. They are real links
(`wire:navigate`), so each tab has its own URL.

## Data model
None. No migration, nothing stored, nothing cached. Every section is read from git when opened.

## Interfaces
- Route: `GET /p/{project}/handbook`, name `projects.handbook`. Query: `?decision=<file.md>`.
- Livewire `ProjectHandbook`, locked props `project`, `kitPath`, `kitSha`, `initial`, `decision`,
  `decisionRefusal`. Constants `SECTIONS` (tab order) and `DECISIONS_PAGE`.
  - `loadSection(string $section): ?string` (renderless) — HTML, or null for an unknown section or a
    project gone from the board.
  - `openDecision(string $file): ?string` (renderless) — HTML, or null when refused, missing or unreadable.
- `ReadHandbook`: `page()`, `lessons()`, `parseLessons()`, `standards()`, `decisions()`, `decision()`,
  `skills()`, static `isSafeDecisionName()`. Badge constants `SAME`, `CHANGED`, `MISSING`, `PROJECT_ONLY`.
- `ResolveKit::handle(): array{path, sha, error}`.
- Blade components: `board/project-tabs`, `board/kit-badge` (`badge`, `dot`), `board/handbook-empty`
  (`project`, `file`, hint slot), `board/prose` (`html`). See `docs/UI-INVENTORY.md`.
- Test hooks: `data-handbook-tab`, `data-section`, `data-kit-badge="<badge>"`, `data-kit-unreachable`,
  `data-kit-ref`, `data-decisions`, `data-decision-filter`, `data-decision-open`, `data-decision-refused`,
  `data-project-tab`.

## Configuration
`config/board.php` (owner-approved addition):
- `kit_path` — env `BOARD_KIT_PATH`, default `$HOME/Code/dev-standards`. The kit's checkout.
- `kit_ref` — env `BOARD_KIT_REF`, default `origin/main`. The ref the badges compare with.

The kit is read through `GitReader` like a project, but it is never registered as one. The board does
not fetch the kit, so `origin/main` is whatever the kit's checkout last fetched.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.handbook_viewed` | info | `ProjectHandbook::section()` | `project`, `section` — once per section load |
| `board.handbook_refused` | info | `ProjectHandbook::refuse()` | `project`, `path`, `reason`: `bad_path` \| `not_found` \| `unknown_section` \| `no_snapshot` \| `unknown` / `disabled` (mid-visit, from `CheckProjectShown`) |
| `board.kit_unreachable` | warning | `ResolveKit::handle()`, `ReadHandbook::guardKit()` | `path`, `error` |
| `board.handbook_read_failed` | warning | `ProjectHandbook::section()`, `decisionHtml()` | `project`, `section`, `error` |

Each line carries `request_id`.
- **Healthy:** one `board.handbook_viewed` for the first tab on page load, then one per tab on its first
  open only. A second click on a tab logs nothing.
- **No badges anywhere:** `board.kit_unreachable` names the path and why (`no such directory`, `not a
  git repository`, `ref … does not resolve`). Fix `BOARD_KIT_PATH` / `BOARD_KIT_REF`, or fetch the kit.
- **One section says it could not be read:** `board.handbook_read_failed` for that project. Usually the
  checkout moved or the snapshot SHA is gone. A refresh (`board:refresh <project>`) re-snapshots it.
- **A decision link does nothing:** `board.handbook_refused` with its `path` and `reason`.

## Testing & verification
- `tests/Feature/Board/ProjectHandbookTest.php`: one `it()` per acceptance criterion, plus extras:
  comment/fence-safe lesson parsing, coins' kit-only `disposal-standards.md` badged Missing, a kit ref
  that does not resolve, the kit vanishing after page load, safe rendering, the `handbook_viewed` context,
  unknown-section / not-found / no-snapshot refusals, the mid-visit redirect, the 404, the Dashboard |
  Handbook tabs, and a project git failure. Fixtures are a project repo and a kit repo built with
  `GitFixture`, never the real kit. The real-data counts in the criteria (16 / 5 / 2 lessons) are
  mirrored by fixture shapes.
- `tests/Browser/ProjectHandbookTest.php`: reach the handbook from the project page and fill a tab on
  first open; page decisions, filter and open one in the modal; asset-track's empty states at 375 px with
  no sideways scroll.
- Real data at build time: coins 16 lessons, client-dashboard 5, rent-track 2, asset-track none. The
  kit has `disposal-standards.md`, which coins lacks, so coins shows it as Missing. Decision counts
  include `docs/decisions/README.md`.
- **Pending (owner-side):** the story's browser check against real repos (`/p/coins/handbook` shows 16
  lessons and standards badged Changed; `/p/asset-track/handbook` shows the empty states). The builder's
  permissions blocked reading the real repos, so this has not been done yet.

## Key decisions & tradeoffs
- Sections load on first open through renderless calls and stay in Alpine; the component never
  re-renders → [ADR-024](../decisions/ADR-024-handbook-sections-load-lazily-and-stay-client-side.md).
- Badges compare bytes via `GitReader::show()` rather than adding a blob-listing method to the
  do-not-touch `GitReader`. It costs one `show` per compared file.
- Kit reads per section are all-or-nothing, so a page never mixes badged and unbadged files.
- `CLAUDE.md` is not compared with the kit: every project rewrites it, so it would always say Changed.
- The whole decisions name list is sent once (coins: about 150 KB) so filtering is instant, rather than
  paging on the server.

## Known limitations & gotchas
- **Deviations from mockup option a**, both follow-up candidates:
  - No counts on the section tabs and no drift summary line. Either would mean reading every section on
    page load, which defeats lazy loading.
  - The Runbook is rendered whole, as the story says, not as the mockup's filterable list of entries.
- No line diff between a project file and the kit (out of scope). The badge and both texts are shown.
- The kit is not fetched by the board. Badges compare against the kit checkout's last-fetched ref.
- Lessons are recognised only by `## L-<n>` headings. A different heading shape is silently not an entry.
- Decisions and standards are only the files directly in their folder. Subfolders are ignored.
- Decisions are sorted by file name, so "newest first" holds only for date- or number-prefixed names.
- `tests/TestCase.php` does not point `board.kit_path` at a fixture by default (unlike
  `board.sessions_path`). A new test that renders the handbook must set it, or it reads the real kit.

## Change history
2026-09-29 — Handbook page, `ReadHandbook`, `ResolveKit`, kit badges, Dashboard | Handbook tabs, `kit_path`/`kit_ref` config (SB-14, `95314c6`)
