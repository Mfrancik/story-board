# Preflight history
Status: active   ·   Last updated: 2026-09-30   ·   Stories: SB-16, SB-19

## Overview
`/p/{project}/preflight` is the project page's fourth tab (Dashboard | Handbook | Stories | Preflight).
It lists every recorded preflight run of the project as a ledger, newest first, with its wall time,
turns, tool calls, tokens, subagent share, pack size and audit tier. Two line charts and three trend
figures sit above the table. `bin/preflight-meter.py report` appends one row per run to a
`preflight-cost.csv` outside the repo. CLAUDE.md calls that CSV "the record", but until this page it was
only read with `cat`. The page answers "is preflight getting slower or more expensive, and since when".
A Columns picker (SB-19) lets the viewer hide ledger columns they do not need, remembered per project in
this browser.

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
- The Columns picker (SB-19), in the same row as the filters. See **Columns picker** below.
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

**Columns picker (SB-19).** The view's `$columns` map lists the ledger's twelve keys in table order
(`when, branch, where, mode, wall, turns, tools, tokens, share, pack, tier, tests`); every `th` and `td`
carries its key as `data-col`, and the picker renders one checkbox per key (`data-col-toggle`). `$hideable`
is every key but `when`, passed to Alpine as the component's fifth argument. When is the timeline's key,
so its checkbox is a static `checked disabled` input; the "always shown" tooltip is a `title` on its
`label`, because disabled inputs do not reliably show tooltips.
- Hiding is `x-show="shown('<key>')"` on each `th`/`td`, the same mechanism rows use; no CSS was added.
  With nothing hidden the table keeps its approved `min-w-5xl`. With any column hidden an object-form
  `x-bind:class` swaps it for `min-w-max`, so the table really narrows (the object form is what lets
  Alpine remove the static class).
- State lives in `preflightHistory`: `hiddenCols`, `colsOpen`, `shown()`, `hiddenCount`, `columnsLabel`
  ("Columns" or "Columns · N hidden"), `toggleColumn()` and `resetColumns()`. `toggleColumn()` ignores
  keys outside `hideable` and rebuilds the list from `hideable`, so it stays in table order.
- Storage: `readHiddenColumns()` / `writeHiddenColumns()` in `resources/js/preflight-history.js`, key
  `board.preflight.hidden-columns.<project name>`, value a JSON array of hidden keys. The key is removed
  when nothing is hidden (Reset, or re-ticking the last one), so "all shown" and "never chosen" are the
  same state. On read only keys in `hideable` survive, so a stale or hand-edited value cannot hide When
  or invent columns; a parse or storage failure means all columns. A failed write is ignored: the choice
  still applies on the page, it just does not survive a reload.
- The popover is plain Alpine (the board had no Alpine popover; Flux dropdowns live only in the layouts).
  It is positioned with `x-anchor.bottom-start.offset.4` (the anchor plugin ships in Livewire 3's
  Alpine), whose flip/shift keeps it on screen at 375px. Click-outside and Escape close it; the button
  carries `aria-expanded` / `aria-controls`. The button copies the Stories tab's "Expand all" classes and
  Reset columns copies the "Clear filters" link.
- Columns and filters are independent: filters scope rows and the trend figures, columns only hide
  cells. The trend strip never follows the columns.

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
- JS: `preflightHistory(runs, now, main, project, hideable)`, exported and registered as
  `Alpine.data('preflightHistory')`. `now` is the server's time, so client and server windows agree;
  `project` keys the saved columns; `hideable` is the `data-col` keys the picker may hide, in table order.
- DOM hooks (for tests): `data-col` on every `th`/`td`, `data-col-toggle`, `data-col-option`,
  `data-columns-button`, `data-columns-panel`, `data-columns-reset`.
- Browser storage: `localStorage['board.preflight.hidden-columns.<project>']`, a JSON array. Per viewer
  only; the server never reads it.
- `board/project-tabs` gains `preflight`. Test helper: `SessionFixture::preflightCsv($folder, $lines)`.

## Configuration
- `board.sessions_path` (`BOARD_SESSIONS_PATH`, default `$HOME/.claude/projects`). This key is shared
  with Live sessions. `tests/TestCase.php` points it at an empty temp folder for every test.
- The Columns picker has no configuration. To clear a viewer's saved columns, use Reset columns or
  delete the `board.preflight.hidden-columns.<project>` key in the browser's storage.

## Observability
- `board.preflight_history_viewed` (info): `project`, `runs`, `skipped`. There is one per page load.
  Skipped rows are **not** logged one by one. This count is the only log trace of them.
- `board.preflight_history_unreadable` (warning): `project`, `file`. It fires for a missing root (with
  `file` = the root), a CSV that cannot be opened, or a CSV with no `ts` column (including an empty
  file).
- `board.project_page_refused` (info, `request: update`): the project left the board mid-visit.
- Healthy: one `viewed` line per visit, no `unreadable`, and no Livewire update requests while
  filtering, hovering or toggling columns. The Columns picker logs nothing by design (pure UI, and a
  failed storage write is not something the server needs to know). If `runs: 0` appears for a project you know has runs, see the RUNBOOK entry
  "Preflight tab shows no runs".

## Testing & verification
- `tests/Feature/Board/ProjectPreflightTest.php` has one `it()` per acceptance criterion. Extras cover:
  a wrong-column-count row counted alongside an unparseable one, a missing root, a no-`ts` file, "never
  writes to a CSV it reads", `hydrate()` refusal, and the tab. Fixtures are temp CSVs written with
  `SessionFixture::preflightCsv()`, never the real `~/.claude`.
- `tests/Browser/ProjectPreflightTest.php` covers the scoped filter with no server request, both charts
  drawn, row hover marking both charts, and the 375px layout with no sideways scroll. SB-19 adds one
  `it()` per picker acceptance criterion. "No server request" is proved by an equal
  `performance.getEntriesByType('resource')` fetch count before and after a toggle. The
  localStorage-throws case installs a Playwright init script
  (`$page->page()->context()->addInitScript()`) that makes `Storage.prototype` `getItem` / `setItem` /
  `removeItem` throw `SecurityError`, then reloads; the stub lets `flux.*` keys through (see gotchas).
  The combined-filter case asserts the trend figures follow the mode filter, not the columns.
- The feature test asserts every `th` and `td` carries its `data-col` key in the picker's order, and that
  no toggle is wired to Livewire.
- Browser check (SB-19): `/p/coins/preflight` → Columns → untick Turns, Tool calls, Pack → the table
  narrows and the button reads "Columns · 3 hidden" → reload → still hidden → Reset columns → all back.
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
- Columns picker: hidden columns are saved in `localStorage` per project, not in the DB or per user;
  they are a viewer convenience and the server never sees them. When cannot be hidden. No mockup round:
  the owner waived it for a small control inside the approved SB-16 layout.
- The picker's checkboxes are native inputs styled `size-4 accent-accent` (the existing `--color-accent`
  token). The board had no checkbox and no forms plugin; the owner has yet to confirm this look.

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
- **Flux reads `localStorage` unguarded.** With site data blocked, `flux.appearance` is read by
  `@fluxAppearance` in `<head>` and by `flux.min.js` on `alpine:init`, which throws an uncaught
  `SecurityError` before this page's code runs. The Columns picker itself survives (its reads and writes
  are wrapped), but the SB-19 browser test has to let `flux.*` keys through its throwing stub. This is a
  pre-existing Flux issue, not fixed here (RUNBOOK 2026-09-30).
- Saved columns live in one browser. A different browser, a private window or cleared site data shows
  every column. Columns cannot be reordered or resized.
- A column key renamed in `$columns` silently drops from viewers' saved choice (the read keeps only
  current `hideable` keys), which shows it again. That is the safe direction.
- `preflightHistory` was the first `Alpine.data` registration in the codebase. Chart code was too big
  for an inline `x-data`.

## Change history
2026-09-29 — Preflight tab at `/p/{project}/preflight`: ledger of runs from every `preflight-cost.csv`
of the project (main checkout, worktrees, plans), trend figures, two SVG charts, Alpine filters and hover
(SB-16, `baeeeb7`)
2026-09-30 — Columns picker in the filter row: hide any ledger column but When, "Columns · N hidden",
Reset columns, saved per project in localStorage with a safe fallback (SB-19, `8e0fa41`)
