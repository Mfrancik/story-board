# Preflight history
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-16

## Overview
`/p/{project}/preflight` is the project page's fourth tab (Dashboard | Handbook | Stories | Preflight).
It lists every recorded preflight run of the project as a ledger, newest first, with its wall time,
turns, tool calls, tokens, subagent share, pack size and audit tier. Two line charts and three trend
figures sit above the table. `bin/preflight-meter.py report` appends one row per run to a
`preflight-cost.csv` outside the repo. CLAUDE.md calls that CSV "the record", but until this page it was
only read with `cat`. The page answers "is preflight getting slower or more expensive, and since when".

## How it works
**Route.** `routes/web.php` registers `GET /p/{project:name}/preflight` (`projects.preflight`) behind
`EnsureProjectIsShown`, so an unknown or disabled project is a 404 before the component mounts (ADR-013).

**Component.** `app/Livewire/Board/ProjectPreflight.php` holds only the locked project name. `render()`
calls `ReadPreflightHistory::handle()`, logs `board.preflight_history_viewed` and renders. The runs go to
the view and are never a public property, so they stay out of the Livewire snapshot. The component has
no actions and does not re-render during a normal visit. `hydrate()` re-checks `CheckProjectShown`
(ADR-019 amendment), the same way the other project tabs do.

**Finding the files.** `app/Actions/Board/ReadPreflightHistory.php:files()`:
1. The root is `board.sessions_path`, the same `~/.claude/projects` that Live sessions (SB-11) reads.
   The story asked for a new `board.claude_projects_path` key. The existing key was reused instead, so
   `config/` stayed untouched ([ADR-027](../decisions/ADR-027-preflight-history-reads-the-cost-csvs-by-folder-name-on-load.md)).
2. The project's folder name is its path with every non-alphanumeric character (spaces included)
   replaced by `-`. This is the same rule as `tests/Support/SessionFixture.php:folderFor()`.
3. A folder under the root counts only when its name is **exactly** that name (`main checkout`), that
   name plus `--claude-plans` (`plans`), or starts with that name plus `--claude-worktrees-` (where =
   the worktree name, such as `sb-15`). A prefix match alone would pull in `story-board-x`.
4. The names are compared **case-insensitively**. macOS paths are case-insensitive, and a project
   registered as `/Users/…/code/story-board` must still find Claude's `-Users-…-Code-story-board`.

**Reading a file.** `read()` reads the header and keys each row by column name, never by position. This
lets the old shape (coins: no `project` column) and the new shape share one reader. A file with no `ts`
column (empty files included) or one that cannot be opened is skipped and logged as unreadable. A row is
skipped and counted when:
- its cell count differs from the header, or
- its `ts` is not strict ISO-8601 (`TS` regex, checked before `CarbonImmutable::parse`, so Carbon never
  gets the chance to read `yesterday` as a date).

In `run()`, a missing column or an empty cell becomes null and renders as "—". A non-numeric value in a
number column is also "—", and the row is kept. Mode `?` (newer meter rows write it when scoped/full is
unknown) is treated as unknown. `tokens` = in + out. The subagent share is `subagent_tokens / tokens`
and is not capped at 100%. `flagged` is set for any named tier other than `sonnet` (`PINNED_TIER`). The
flag reads "check the tier", not "wrong", because the meter's tier labels are themselves suspect (F-3).
A new-shape file's `project` column is not used. A row belongs to the project because of the folder it
is in.

**Trend figures.** `handle()` computes runs, median wall time and median tokens for (now−30d, now] and
(now−60d, now−30d] (`WINDOW_DAYS`) over all runs, so the server's HTML already shows them.

**The view and the client side.** `resources/views/livewire/board/project-preflight.blade.php` (mockup
option A, the dense ledger) has these parts:
- `board/project-header` and `board/project-tabs current="preflight"`. The header shows the run count,
  the date range and a Refresh link that re-navigates, since the page reads files only on load.
- The skipped-rows notice ("N rows could not be read").
- The filters: mode (All / Scoped / Full, with counts), branch contains, and where (All / Main checkout
  / Worktrees). "Worktrees" means anything that is not the main checkout, so `plans` is included.
- The trend strip, the two charts, and the table with a sticky header. The Tests column is greyed with
  zinc tokens and reads "needs F-5".
- The empty state, listing the three paths looked in.

The server renders every row, the figures and the count, so HTTP tests can assert on them. The
`preflightHistory` Alpine component in `resources/js/preflight-history.js` (imported from
`resources/js/app.js`, registered with `Alpine.data`) receives a slim copy of the runs and handles:
- hiding filtered rows with `x-show`,
- rewriting dates from the server's UTC into the viewer's time zone,
- recomputing the three figures over the visible runs,
- drawing both charts as inline SVG through `x-html`,
- the hover link: a row marks its run on both charts, and the pointer over a chart marks the nearest
  run.

Nothing on the page calls the server after load
([ADR-028](../decisions/ADR-028-preflight-page-renders-on-the-server-and-recomputes-in-alpine.md)).

**Charts.** There is no chart library. Each chart has one series (wall time, tokens), drawn at its
measured width (a `ResizeObserver`) so labels never stretch. The x-axis spans **all** runs, not only
the visible ones, so a filter never rescales time. A dashed line marks the 30-day boundary the figures
compare across. The series colour is the new `--color-series` token in `resources/css/app.css`
(sky-600, and sky-400 in dark mode).

## Data model
None. No migration and nothing stored. The page reads `preflight-cost.csv` files under
`board.sessions_path` on every load and never writes, locks or moves them.

## Interfaces
- Route: `GET /p/{project:name}/preflight` → `projects.preflight`. Returns 404 for an unknown or disabled
  project.
- `ReadPreflightHistory::handle(Project): array{runs, skipped, looked, trend{current, previous}, now}`.
  Each run has `ts, branch, where, mode, wall, turns, tools, tokens, tokens_in, tokens_out, share, pack,
  tier, flagged`. The full shape is in the docblock.
- Formatters: `ReadPreflightHistory::wall()` (`m:ss`), `tokens()` (`2.6M`, `517k`), `pack()` (KB).
  Constants: `MAIN`, `PLANS_WHERE`, `PINNED_TIER`, `WINDOW_DAYS`, `FILE`.
- JS: `preflightHistory(runs, now, main)`, exported and registered as `Alpine.data('preflightHistory')`.
  `now` is the server's time, so client and server windows agree.
- `board/project-tabs` gains `preflight`. Test helper: `SessionFixture::preflightCsv($folder, $lines)`.

## Configuration
- `board.sessions_path` (`BOARD_SESSIONS_PATH`, default `$HOME/.claude/projects`). This key is shared
  with Live sessions. `tests/TestCase.php` points it at an empty temp folder for every test.

## Observability
- `board.preflight_history_viewed` (info): `project`, `runs`, `skipped`. There is one per page load.
  Skipped rows are **not** logged one by one. This count is the only log trace of them.
- `board.preflight_history_unreadable` (warning): `project`, `file`. It fires for a missing root (with
  `file` = the root), a CSV that cannot be opened, or a CSV with no `ts` column (including an empty
  file).
- `board.project_page_refused` (info, `request: update`): the project left the board mid-visit.
- Healthy: one `viewed` line per visit, no `unreadable`, and no Livewire update requests while
  filtering or hovering. If `runs: 0` appears for a project you know has runs, see the RUNBOOK entry
  "Preflight tab shows no runs".

## Testing & verification
- `tests/Feature/Board/ProjectPreflightTest.php` has one `it()` per acceptance criterion. Extras cover:
  a wrong-column-count row counted alongside an unparseable one, a missing root, a no-`ts` file, "never
  writes to a CSV it reads", `hydrate()` refusal, and the tab. Fixtures are temp CSVs written with
  `SessionFixture::preflightCsv()`, never the real `~/.claude`.
- `tests/Browser/ProjectPreflightTest.php` covers the scoped filter with no server request, both charts
  drawn, row hover marking both charts, and the 375px layout with no sideways scroll.
- Real data (read-only, 2026-09-29): coins has 36 runs, 0 skipped, 15 scoped and 11 flagged, from Aug 26
  to Sep 28. story-board has 13 runs (main 9, sb-10 2, sb-15 1, plans 1).

## Key decisions & tradeoffs
- The page reuses `board.sessions_path`, matches folders by exact encoded name or known suffix, and
  compares case-insensitively →
  [ADR-027](../decisions/ADR-027-preflight-history-reads-the-cost-csvs-by-folder-name-on-load.md).
- The server renders, and Alpine recomputes on filter. Median and the `m:ss` / `2.6M` formats exist in
  PHP and JS on purpose →
  [ADR-028](../decisions/ADR-028-preflight-page-renders-on-the-server-and-recomputes-in-alpine.md).
- Two single-series charts instead of one dual-axis chart. No chart library.
- The Tests column is greyed with zinc tokens rather than the mockup's striped gradient, because tokens
  are law.

## Known limitations & gotchas
- **Median and the formats are duplicated.** `ReadPreflightHistory::wall()` / `tokens()` / `median()`
  and `fmtWall` / `fmtTok` / `median` in `preflight-history.js` must change together. Otherwise the
  figures jump when the first filter is applied.
- The server text is UTC until Alpine runs. The header's date range stays UTC.
- There are no test counts, failures, gate results or verdict. The CSV does not hold them; that waits
  for F-5.
- A project must be registered to have a Preflight tab. story-board is not registered in the dev DB, so
  `/p/story-board/preflight` is a 404 until it is.
- Every CSV is re-read on every load. That is fine at tens of rows, but nothing pages or caches.
- `preflightHistory` was the first `Alpine.data` registration in the codebase. Chart code was too big
  for an inline `x-data`.

## Change history
2026-09-29 — Preflight tab at `/p/{project}/preflight`: ledger of runs from every `preflight-cost.csv`
of the project (main checkout, worktrees, plans), trend figures, two SVG charts, Alpine filters and hover
(SB-16, `baeeeb7`)
