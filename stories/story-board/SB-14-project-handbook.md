# SB-14 — Project handbook: rules, lessons and how each project differs from the kit
Status: approved         Journey: none
Source: owner 2026-09-29 (/story): *"a section wehre i can view project specific things such as lessons
we've added, or certain rules if those are already organized well. often times dev standards package may
have different things project to project."*

## Story
As the owner, I want to read each project's rules, lessons, standards, runbook, decisions and skills on
the board, and see which kit files a project has changed or is missing, so that I know how each project
is run without opening its repo.

## Why
Every project starts from the dev-standards kit and then drifts. It learns its own lessons, edits its
standards, and adds or drops skills. Today that is invisible unless you open each repo, and the drift is
exactly what the owner asked to see.

## In scope
- A "Handbook" tab on `/p/{project}` (SB-10), at `/p/{project}/handbook`, with these sections, each read
  from git at the project's snapshot SHA through `GitReader::show()` / `listFiles()`:
  - **Rules**: `CLAUDE.md`, rendered.
  - **Lessons**: `docs/LESSONS.md` split into its `## L-<n>` entries (number, date, name, scope, and the
    body when expanded), newest first.
  - **Standards**: each `docs/standards/*.md`, rendered, one per sub-tab.
  - **Runbook**: `docs/RUNBOOK.md`, rendered.
  - **Decisions**: the list of `docs/decisions/*.md` files with a filter box, 50 per page. A file is
    rendered when opened.
  - **Skills**: the names of `.claude/skills/*/`, each marked as from the kit or project-only.
- **Kit comparison**: for each standards file and each kit skill's `SKILL.md`, a badge. The kit is read
  from its own repo (path and ref in config, default `~/Code/dev-standards` at `origin/main`) through
  `GitReader`. Badges:
  - "Same as kit" (identical blob).
  - "Changed in this project".
  - "Missing" (in the kit, not in the project).
  - "Project only" (in the project, not in the kit).
- A missing section (asset-track has no `docs/LESSONS.md` and no standards) shows a designed empty state
  that names the file that isn't there.
- Rendering uses the same safe markdown as `RenderStory` (`html_input=escape`, no unsafe links).

## Out of scope (do NOT build)
- A line-by-line diff between a project file and the kit. The badge and both texts, readable one after
  the other, are enough for now. Log it with /feature if it's wanted.
- Editing or syncing anything, in the project or the kit.
- Comparing `CLAUDE.md` to the kit (every project rewrites it, so the badge would always say changed).

## Acceptance criteria (executable — these become the Pest test names)
- Given coins, when `/p/coins/handbook` loads, then Lessons lists 17 entries, newest first, each with
  its number, date and name.
- Given client-dashboard, then Lessons lists 6 entries. Given rent-track, then it lists 3.
- Given asset-track, which has no `docs/LESSONS.md` and no `docs/standards/`, then Lessons says
  "asset-track has no docs/LESSONS.md" and Standards shows the same kind of empty state. The page still
  returns 200.
- Given coins' `docs/standards/codebase-standards.md` differs from the kit's blob, then it is badged
  "Changed in this project". Given client-dashboard's `.claude/skills/story/SKILL.md` matches the kit,
  then it is badged "Same as kit".
- Given asset-track, then `docs/standards/codebase-standards.md` is badged "Missing".
- Given a skill folder that is in the project and not in the kit (e.g. rent-track `new-volt-page`), then
  it is badged "Project only".
- Given coins' 1,747 decision files, then the Decisions list shows 50 per page, and typing in the filter
  narrows the list by file name.
- Given the kit path is missing or not a repo, then the badges are hidden, the page shows "Kit not found
  at <path> — comparison unavailable", `board.kit_unreachable` is logged, and every section still renders.
- Given a decision file name with `..` or a leading `-` in the URL, then it is refused without calling git
  and `board.handbook_refused` is logged with reason `bad_path`.
- Given the handbook loads, then no project's or the kit's `git status --porcelain` changes.

## Applicable standards
- Design: this is a new page layout (a tabbed document reader inside the design-A shell), so it gets a
  **mockup gate of its own** at the start of its build: `docs/mockups/SB-14/option-{a,b}.html`, opened for
  the owner, and no build before the pick. Tabs are Alpine (pure UI). Content loads through Livewire only
  when a tab is first opened.
- Codebase/DB: no schema change; nothing is stored. All git goes through `GitReader` (`show`,
  `listFiles`, `resolve`). The kit is a second repo read through the same gateway, and it is not
  registered as a project. Config: `board.kit_path` and `board.kit_ref` (env `BOARD_KIT_PATH`,
  `BOARD_KIT_REF`). Owner confirmation is needed before touching `config/`. L-5: every refusal gets a
  test and a log line.
- Logging: `board.handbook_viewed` (info; `project`, `section`), `board.handbook_refused` (info;
  `project`, `path`, `reason`), `board.kit_unreachable` (warning; `path`, `error`).

## Design mockup gate
- Mockups: docs/mockups/SB-14/option-{a,b}.html (made at build start; they must sit inside the design-A
  shell).
- Chosen option: _pending_
- Why I chose it: _pending_

## Do NOT touch
- Any registered project's files, `~/Code/dev-standards` (read only through `GitReader`).

## Data & interfaces
- Schema/migrations: none.
- Route: `GET /p/{project}/handbook` (named `projects.handbook`). Livewire: a new `ProjectHandbook`
  component. Config keys `board.kit_path` and `board.kit_ref`. `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build. The fixtures are a
  fixture project repo and a fixture kit repo (via `GitFixture`), never the real kit.
- Journey test: none.
- Browser check: `/p/coins/handbook` shows 17 lessons and standards badged "Changed in this project".
  `/p/asset-track/handbook` shows the empty states.
- Checked against real data (2026-09-29; `git ls-tree` / `git show` at each `origin/main`, the kit at
  `~/Code/dev-standards` HEAD):
  - `## L-` entries: coins 17, client-dashboard 6, rent-track 3. asset-track has no `docs/LESSONS.md`.
  - `docs/decisions/*.md` files: coins 1,747, client-dashboard 34, rent-track 6, asset-track none.
  - Each standards file compared with the kit:

    | Project | codebase | design | logging |
    |---|---|---|---|
    | coins | differs | differs | differs |
    | client-dashboard | differs | differs | differs |
    | rent-track | differs | same | same |
    | asset-track | missing | missing | missing |

  - `story` SKILL.md is the same as the kit in coins and client-dashboard, and differs in rent-track and
    asset-track. `preflight` SKILL.md is the same only in client-dashboard.
  - The kit has 6 skills: build, document, feature, lesson, preflight, story.
  - Project-only skills:
    - coins, 7: fluxui, fortify, laravel-best-practices, livewire, pest-testing, scout, tailwindcss.
    - client-dashboard, 6: the same minus scout.
    - rent-track: `new-volt-page`.
    - asset-track: `ui-ux-pro-max`.
  - Kit skills missing from a project: asset-track has no build or feature, and rent-track has no build or
    feature.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7, SB-10
