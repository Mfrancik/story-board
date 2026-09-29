# SB-16 — Preflight history
Status: approved       Journey: none
Source: owner 2026-09-29 (/story), from the "what else" list: *"approve the preflight cost trend, i want this
to show us things like the tests that ran, failures, which fialures, time it took to run, etc.. and i want to
see in a table so i can go down the timeline to see progression of it."* Owner answer on test data: *"I dont
want to make too many changes to the preflight scripts right now … build what we do have logging currently,
use coins project as the reference"* — so this story reads only the existing cost CSV; test counts and
failures wait for F-5 (a kit run record).

## Story
As the owner, I want a project's preflight runs listed as a timeline table with their cost and duration, so
that I can scroll down the history and see whether preflight is getting slower or more expensive, and since when.

## Why
`bin/preflight-meter.py report` appends one row per run to `~/.claude/projects/<encoded path>/preflight-cost.csv`,
outside the repo. CLAUDE.md calls that CSV "the record", but nothing displays it; today it is read by `cat`.

## In scope
- A **Preflight** tab on the project page tab bar (Dashboard | Handbook | Stories | Preflight), at
  `/p/{project}/preflight`, inside the design-A shell.
- **Sources.** Every `preflight-cost.csv` under `~/.claude/projects/` whose folder is the project's encoded path
  (`/` → `-`), or that path followed by `--claude-worktrees-<name>` or `--claude-plans`. Runs made inside a
  worktree are part of the project's history (SB-15's run sits in `…story-board--claude-worktrees-sb-15`).
  Each row is labelled with where it came from: `main checkout`, or the worktree name.
- **Both CSV shapes.** Older files have no `project` column (coins: `ts,branch,mode,wall_s,…`); newer ones do.
  Read by header name, never by position. A missing column or an empty cell shows "—".
- **The table**, newest first, one row per run: date/time (local), branch, where, mode (`scoped` / `full` /
  "—"), wall time (`m:ss`), turns, tool calls, tokens (in + out, compact: `2.6M`), subagent share (%), pack
  size (KB), audit tier. An audit tier other than `sonnet` (e.g. `opus`, `opus+sonnet`) is flagged, because
  CLAUDE.md pins the audit to Sonnet — the flag says "check the tier", not "wrong", because of F-3.
- **Trend strip** above the table: wall time and tokens per run as two small line charts over the same
  timeline, plus three figures: runs, median wall time, median tokens — for the last 30 days vs. the 30 before.
- **Filters** (Alpine, no server call): mode (all / scoped / full), branch contains, where (all / main
  checkout / worktrees).
- The empty state: "<project> has no preflight runs recorded yet." and the paths that were looked in.
- A malformed row (wrong column count, unparseable `ts`) is skipped and counted: "2 rows could not be read".

## Out of scope (do NOT build)
- Test counts, failing test names, gate results, verdict (GO/NO-GO). The CSV does not hold them; F-5.
- Any change to `preflight.sh`, `bin/preflight-meter.py`, or the CSV format (owner: not now).
- An all-projects preflight view.
- Fixing the audit-tier labels (F-3).
- Storing the CSV in the board's database. It is read on page load.

## Acceptance criteria (executable — these become the Pest test names)
- Given a project whose CSV holds 3 rows, when `/p/{project}/preflight` loads, then the table lists 3 runs,
  newest first, with date, branch, mode, wall time as `m:ss`, turns, tool calls, tokens, audit tier.
- Given an old-shape CSV with no `project` column (coins) and a new-shape CSV, then both are read by header
  and show the same columns.
- Given runs in the main-checkout folder and in a `--claude-worktrees-sb-15` folder, then both are listed, and
  the worktree row shows "sb-15" as where it ran.
- Given a folder for a different project whose name starts with the same text (`story-board-x`), then its
  rows are not listed.
- Given an empty `mode` or `audit_model` cell, then that cell shows "—".
- Given an audit tier of `opus+sonnet`, then the cell is flagged "check the tier"; given `sonnet`, it is not.
- Given a row with an unparseable `ts`, then it is skipped and the page says "1 row could not be read".
- Given the mode filter is set to scoped, then only scoped rows show, without a server request.
- Given 40 runs across 60 days, then the trend strip shows runs, median wall time and median tokens for the
  last 30 days and the 30 before.
- Given no CSV for the project, then the empty state lists the paths looked in and the page returns 200.
- Given a disabled or unknown project, then `/p/{project}/preflight` returns 404 (`CheckProjectShown`), and
  `hydrate()` re-checks it.
- Given the page loads, then `board.preflight_history_viewed` is logged with `project`, `runs`, `skipped`.

## Applicable standards
- Design: design-A shell, `board/project-tabs` gains Preflight, `board/project-header` (SB-15). Numbers
  right-aligned and tabular. Charts per the dataviz method; no chart library unless the mockup pick needs one
  (ask before adding a dependency). Tokens are law; no raw palette.
- Codebase/DB: no schema change. One reader action (`ReadPreflightHistory`) that finds files and parses them;
  the claude projects root is read from config (`board.claude_projects_path`, default `~/.claude/projects`;
  adding a key to the existing `config/board.php` needs the owner's OK at build time). Reads only — never
  writes, locks or moves a CSV.
- Logging: `board.preflight_history_viewed` (info; `project`, `runs`, `skipped`). Unreadable files log
  `board.preflight_history_unreadable` (warning; `project`, `file`).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/SB-16/option-{a,b,c}.html (inside the design-A shell, with coins' real 36 rows)
- Chosen option: a
- Why I chose it: owner pick 2026-09-29 (/story mockup gate), no reason given. Direction: dense ledger: figures and both charts on top, one wide table with a sticky header; hovering a row marks the run on both charts. Keep the greyed "Tests · needs F-5" column.

## Do NOT touch
- `preflight.sh`, `bin/preflight-meter.py`, any `preflight-cost.csv`, `app/Services/GitReader.php`, any
  registered project's files.

## Data & interfaces
- Schema/migrations: none.
- Route: `GET /p/{project}/preflight` (named `projects.preflight`). Livewire: `ProjectPreflight`. Action:
  `ReadPreflightHistory`. `board/project-tabs` gains the tab. `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build. Fixture CSVs in a temp dir
  pointed to by config; never the real `~/.claude`.
- Journey test: none.
- Browser check: `/p/coins/preflight` lists 36 runs from 2026-08-26 to 2026-09-28; `opus` rows are flagged;
  the scoped filter leaves 15. `/p/story-board/preflight` includes the sb-10 and sb-15 worktree rows.
- Checked against real data (2026-09-29): coins has 36 rows (old shape, no `project` column): 15 scoped, 21
  with a blank mode; audit tier sonnet 9, opus+sonnet 8, opus 2, fable+sonnet 1, blank 16; mean wall time
  ~21 min. story-board has 9 rows in its main folder plus rows in `--claude-worktrees-sb-10`, `-sb-15` and
  `--claude-plans`.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-15 (project tabs, `board/project-header`) · Later: F-5 adds tests and failures
