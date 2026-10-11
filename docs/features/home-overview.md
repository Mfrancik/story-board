# Home overview
Status: active   ·   Last updated: 2026-10-11   ·   Stories: RD-5, RD-6, RD-7, RD-8, RD-9

## Overview
`/` is the redesign's home, Home A "command center" ([`docs/mockups/RD-5/option-a.html`](../mockups/RD-5/option-a.html))
in the [dark shell](dark-shell.md). It answers three questions at a glance: how many things need the owner, what
is being built right now, and how preflight is doing. A header sentence ("7 things need you. 3 sessions are
building.") sits over four headline figures, then two panels: **Needs me** (oldest first) and **Live now** (one card
per running Claude Code session, with what it is doing and how full its context is). It replaces the SB-9
[all-projects dashboard](what-needs-me-home.md), which RD-9 deleted ([retired pages](retired-pages.md)). A **Projects** filter
in the header (RD-6) narrows every panel to chosen projects; by default it covers them all. Below the two panels
(RD-7, [`docs/mockups/RD-7/option-a.html`](../mockups/RD-7/option-a.html)) sit a **Projects** grid, one tile per
project, and **Shipped this week**, the build commits of the last 7 days.

## How it works
**Route.** `routes/web.php` maps `/` (name `home`) to `app/Livewire/Board/Overview.php`, inside the
`RefreshStaleProjects` group, with no other middleware. The filter uses `?projects=` (plural). The pre-SB-7
`?project=` (singular) is read by nothing since RD-9 deleted its redirect, so an old bookmark renders the whole board
([ADR-058](../decisions/ADR-058-legacy-project-query-is-ignored.md)).

**Render.** `Overview::render()` first counts enabled projects. With none, the page renders one empty state linking
to `/projects` (Manage projects) and no panels. Otherwise it resolves the project filter once (below), then fetches
five sources, each through `Overview::attempt()`, a per-panel `try/catch`, and each handed the same filter:
- `needs`: `app/Actions/Board/ListNeedsMe.php:handle($filter)`
- `live`: `app/Actions/Board/ListLiveSessions.php:overview($filter)`
- `figures`: `app/Actions/Board/ReadOverviewFigures.php:handle($filter)`
- `tiles`: `app/Actions/Board/ListProjectTiles.php:handle($filter)` (RD-7)
- `shipped`: `app/Actions/Board/ListShippedThisWeek.php:handle($filter)` (RD-7)

A source that throws becomes `null`, logs `board.overview_panel_failed`, and that panel (and any figure built from
it) shows its error state ("could not be read", or a red alert with Try again, which is `wire:click="$refresh"`).
The other panels still render ([ADR-049](../decisions/ADR-049-overview-panels-fail-alone-and-log-once-per-load.md)).

**Project filter (RD-6).** `Overview::$projects` is a `#[Url(history: true, except: '')]` string, the raw
comma-separated `?projects=` value. History mode means each change pushes a history entry, so Back undoes it. An
empty value leaves the URL bare. On each render, `app/Actions/Board/ResolveProjectFilter.php:handle()` turns it into
one `app/Services/ProjectFilter.php` value:
- It splits on commas, trims, and drops blanks and repeats. It loads the named projects and matches names **exactly
  in PHP**, because MySQL's collation would let `Coins` match `coins`.
- Unknown and disabled names are dropped. If none survive, the filter is `ProjectFilter::all()` (every enabled
  project), so a stale bookmark narrows nothing rather than emptying the page.
- `ProjectFilter::projects()` is the enabled-projects query narrowed to the chosen names. `ListNeedsMe` and
  `ReadOverviewFigures` use it as a `whereIn('stories.project_id', …select('id'))` subquery, and the preflight
  figure loops over it. `includes()` serves Live now, whose sessions are matched in memory. Both reapply
  "enabled", so a project switched off mid-session still drops out.

This is the only home of the "which projects" rule; no action reads the URL. RD-7's tiles and Shipped this week
take the same value ([ADR-051](../decisions/ADR-051-one-project-filter-value-for-every-home-panel.md)).

The header control (`resources/views/livewire/board/overview.blade.php`) uses the Preflight page's
popover-of-checkboxes style:
- **Button.** It reads "All projects", the one chosen name, or "N projects". Alpine opens and closes it (Escape
  returns focus to the button, and a click outside closes it), with no round trip.
- **Checkboxes.** There is one per enabled project, sorted by name. A tick calls `toggleProject(name)`, which
  rebuilds the list from the *resolved* names, then adds or removes that one. Each box's ticked state is
  `x-bind`ed to `$wire.projects`, so Back unticks it too.
- **Chips.** With a filter on, a "Showing" row lists the chosen names as removable chips (`<x-shell.tag
  tone="needs" mono>`). Each chip calls `toggleProject`.
- **Clear.** It appears twice, on purpose: beside the chips, and at the foot of the dropdown, where it is disabled
  when nothing is chosen. Both call `clearProjects()`.

The URL keeps names in the order they were picked. The header sentence's numbers follow the filter, because they
are counted from the filtered panels, but the sentence does not name the filter. The chips do that.

**Needs me.** `ListNeedsMe` owns the three predicates SB-3 used: `awaitingPick()` (draft or approved, mockup options,
no chosen option), `toApprove()` (draft, not parked) and `readyToBuild()` (approved). They are public static query
narrowers (the old `ListWhatNeedsMe` called them too until RD-9 deleted it). `handle()` runs them
over `base($filter)` (the filter's enabled projects, on the ref: `location_kind IS NULL`) in the order pick, draft, ready, keyed by story
row ID with `??=`. A story awaiting a pick is therefore listed **once, as a Pick**, even though it is also a draft
or approved, and "ready" means approved minus those awaiting a pick
([ADR-047](../decisions/ADR-047-needs-me-lists-each-story-once.md)). Rows sort by `dated_on` (the first date in
the story's `Source:` line), undated last, then project and path. Each row shows a kind tag (Pick amber, Draft blue,
Ready green), title, project and ID, and an age: `today`, `Nd`, or `undated`. Only dates are stored, so there
are no hours. A row links to the [story page](story-page-and-mockups.md) with `wire:navigate`. A row whose ID is
malformed (`Story::hasPage()` false) is shown without a link.

**Headline figures** (`resources/views/components/shell/figure.blade.php`, a label, a mono number, an accent and a
line; a null value reads "—"):
- **Live sessions**: number of cards, "N project(s)" they span, and "Longest: <story or project>, <duration>"
  from the earliest `started_at`.
- **Needs me**: number of rows, "N over a day" (rows dated before today), and "2 picks · 1 draft · 3 ready".
- **Preflight · last 7 days**: `ReadOverviewFigures` reads the runs of every project in the filter through
  `ReadPreflightHistory::handle()` and sums them with `ReadPreflightHistory::figures()` (made public for this) over
  `(now − 7 days, now]`. It shows the run count and the median wall time via `Overview::duration()` (`1h 12m`,
  `3m 12s`, `40s`). With no runs it reads 0 and "no runs this week".
- **In flight**: every approved story on the ref of a project in the filter. This overlaps Needs me's "ready" (that set
  plus approved stories still awaiting a pick); the owner ruled to keep it as written (ADR-047).

**Live now.** `ListLiveSessions::overview()` reuses `matched()` (deepest-anchor project matching, disabled and
unregistered sessions dropped with `board.session_ignored`) and `storyRows()`, the story-link rules from
[Live sessions](live-sessions.md), split out of `links()`. It returns IDs only, so it reads no story text and runs
no git. Sessions of projects outside the filter are skipped silently. They are filtered out, not ignored, so they
do not log `board.session_ignored`. Each card: project, branch, linked story IDs as tags (links to the story page), elapsed time since
`started_at`, the **doing** line, and the **context bar**:
- `window()` is `CONTEXT_WINDOW` (200k) unless the model id contains `[1m]` or the tokens already exceed 200k, in
  which case it is `LONG_CONTEXT_WINDOW` (1M), so a bar never reads over 100%.
- The bar is `tokens / window`, labelled with `ReadPreflightHistory::tokens()` ("142k / 200k"). It turns amber
  (`bg-needs`) at 60% or more. With no usage record it reads "—".
- With no tool call yet, the doing line reads "No tool calls yet".

The card data comes from `app/Services/SessionReader.php`, extended under its existing defensive rules
([ADR-048](../decisions/ADR-048-live-cards-summarise-the-latest-tool-call-and-usage.md)):
- `readFile()` still reads only the last `TAIL_BYTES`, and now also the first `HEAD_BYTES` (64 KB) when the tail
  starts mid-file, because a long session's tail no longer holds line 1. `started()` takes the first readable
  `timestamp` there.
- `activity()` walks the tail from the end over `"type":"assistant"` lines only, skipping sidechain (subagent)
  turns, until it has both the newest usage record and the newest `tool_use` block.
- `tokens()` = input + cache creation + cache read + output. It is null unless every present field is a
  non-negative int.
- `summarise()` makes one line from the tool name plus a path made relative to the session cwd, or for Bash the
  command's first line with a leading `cd … &&` stripped (`command()`). Grep/Glob show the pattern, Agent the
  description, WebFetch the host, and an unknown tool "Using <name>". Control characters are flattened and the
  line is cut to `DOING_MAX` (120). Edit/Write content, prompts and tool output are never read into the result.
- A present but malformed usage or tool record leaves that field null and logs `board.live_session_unparsed`
  once per file version (`Cache::add` keyed on file, mtime and reason).

**Projects grid (RD-7).** `ListProjectTiles::handle()` returns one tile per covered project, A–Z
([ADR-054](../decisions/ADR-054-project-tiles-reuse-existing-rules-and-stored-data.md)). It reads the board's
database, the preflight CSVs and (since RD-8) the preflight run records only, never git and never a production database:
- **Progress.** `kinds()` is one grouped `status` query over on-ref stories, mapped through
  `ReadProjectStories::kind()`, so the tile counts exactly as the project's Stories page does. The tile draws
  `<x-board.status-bar>` and "21 built · 2 in flight · 2 draft" (in flight is the `approved` kind).
- **Off main.** A count of the project's stored off-main story rows (`Story::offMain()`), not of distinct branches;
  "N off main", or "clean" at zero.
- **Last preflight.** The newest run from `ReadPreflightHistory::handle()`: `Overview::ago()` ("6h ago", whole units
  rounded down), `Overview::duration()` wall time, and the CSV's mode as a tag. "no runs yet" with none. The data
  carries `ran_on` (RD-8): `ReadPreflightRuns::match()` over **all** the project's runs and records, so it is
  exactly what the Preflight page's newest row reads. The tile appends "on brain" / "on mac"
  (`data-tile-ran-on`), and nothing when no record matches. Records are only read when the project has a run.
  No writer exists until RD-10, so today the suffix never shows. How records are found and matched:
  [Preflight history](preflight-history.md), [ADR-055](../decisions/ADR-055-preflight-run-record-format.md).
- **Production.** `prod()`: only with a `ProdConnection` and an enabled metric, the first metric (by `position`, then
  id), its latest stored `ProdSnapshot` in the last `PROD_DAYS` (7) board-local days, formatted by
  `app/Services/Production/MetricNumber.php:format()` (RD-9; it was `ListProductionDashboard::format()`), and a 7-day series drawn by `<x-board.sparkline decorative>`. With no
  snapshot in the window, no production row shows. `ProductionReader` is never called.
- **Live dot.** Computed in the view from the Live now cards (`$cards`), so a tile reads "session running" exactly
  when a card for its project is shown. If Live now failed, no tile is live.

Each tile is one link to `/p/{project}/stories` (`projects.stories`). The header carries a Manage projects link. A
failed source shows the panel's error with Try again; an empty filter result reads "No projects match the filter."

**Shipped this week (RD-7).** `ListShippedThisWeek::handle()` walks the covered projects A–Z
([ADR-053](../decisions/ADR-053-shipped-this-week-is-build-commits-cached-per-snapshot.md)):
- `read()` calls `GitReader::log($path, $ref, now − 7 days)`, the one `log` form `GitReader` admits
  ([ADR-052](../decisions/ADR-052-git-log-is-admitted-in-one-fixed-form.md)), and keeps subjects matching
  `BUILD_COMMIT`, `^(feat|fix|test)\(<ID>\):`. `docs`, `chore` and `refactor` never count. The result is cached
  `CACHE_SECONDS` (300) under `board.shipped:<project id>:<sha1(ref|sha)>`, so the 30 s poll does not re-run git and
  a refresh that moves the sha reads afresh.
- A `GitReaderException` for one project logs `board.shipped_unreadable`, and the panel names it ("Could not read git
  history for …") above the other projects' rows.
- Rows sort newest first (ties by project, then ID). One row per commit, so a story with a `feat` and a `fix` this
  week shows twice. `titles()` fetches each story's on-ref title in one query. A story not in the snapshot shows the
  commit's subject, unlinked; the rest link to the story page.
- Each row: day and time (`D H:i`, app timezone), ID tag, title, project. None: "Nothing shipped in the last 7 days."

**Polling.** `wire:poll.30s` sits on the Live now `<section>` only, but it re-renders the whole component, so the
figures, the header sentence and the tiles follow the cards; each poll re-queries the tiles. The session scan itself
is still cached 20 s (`board.sessions`), and each project's shipped read 5 min.

## Data model
None. No migration. Reads `stories` (`status`, `is_parked`, `mockups`, `dated_on`, `location_kind`), `projects`
(`is_enabled`), the preflight cost CSVs and the session transcripts. Cache keys added:
`board.live_session_unparsed:<sha1(file|mtime|reason)>` (the once-per-version marker, `LIVE_MINUTES`) and, RD-7,
`board.shipped:<project id>:<sha1(ref|sha)>` (one project's build commits, 300 s). RD-7 also reads
`stories.title`, `prod_connections`, `prod_metrics` (`is_enabled`, `position`) and `prod_snapshots` (`day`, `value`),
and runs `git log` through `GitReader`.

## Interfaces
- `ProjectFilter` (`app/Services/ProjectFilter.php`, `final readonly`): `all()`, `only(array $names)`, public
  `names` (pick order; `[]` is every project), `isAll()`, `projects(): Builder<Project>`, `includes(string): bool`.
- `ResolveProjectFilter::handle(string $raw, bool $log = true): ProjectFilter`.
- `Overview`: URL property `projects` (`?projects=a,b`); actions `toggleProject(string $name)`, `clearProjects()`.
- `ListNeedsMe::handle(?ProjectFilter $filter = null): array{rows: list<{kind, story}>, counts: {pick, draft, ready}}`; constants `PICK`,
  `DRAFT`, `READY`; static `awaitingPick()`, `toApprove()`, `readyToBuild()` (each `Builder → Builder`).
- `ReadOverviewFigures::handle(?ProjectFilter $filter = null): array{preflight: {runs, wall}, in_flight}`; `PREFLIGHT_DAYS = 7`.
- `ListLiveSessions::overview(?ProjectFilter $filter = null): array{status, sessions: list<{key, project, branch, stories, started_at,
  active_at, doing, tokens, window}>}`; `CONTEXT_WINDOW`, `LONG_CONTEXT_WINDOW`.
- `SessionReader::live()` sessions gain `started`, `doing`, `tokens`, `model`; constants `HEAD_BYTES`, `DOING_MAX`.
- `Overview::duration(int|float $value, bool $seconds = true): string`; `Overview::ago(CarbonInterface): string`
  (`just now`, `12m ago`, `6h ago`, `3d ago`).
- `ListProjectTiles::handle(?ProjectFilter $filter = null): list<{name, state, stories: {built, approved, draft},
  offmain, preflight: {at, wall, mode, ran_on}|null, prod: {label, value, text, series}|null}>`; `PROD_DAYS = 7`.
- `ListShippedThisWeek::handle(?ProjectFilter $filter = null): array{rows: list<{at, story_id, title, project,
  linked}>, failed: list<string>}`; `DAYS = 7`, `CACHE_SECONDS = 300`.
- `GitReader::log(string $path, string $ref, DateTimeInterface $since): list<{sha, at, subject}>`;
  `GitReader::LOG_FORMAT`.
- Blade `<x-board.sparkline decorative>`: a bare `aria-hidden` SVG line, no label, hover or end dot; `label` is
  optional with it.
- Blade `<x-shell.figure key label value accent tone>`; `tone` is `needs | live | null`.
- Test hooks: `data-overview-empty`, `data-figure="live|needs|preflight|in-flight"` with `data-figure-value`,
  `data-needs-panel="ok|empty|error"`, `data-needs-row="<kind>"`, `data-needs-story="<project>/<ID>"`,
  `data-live-now="<state>"`, `data-live-card`, `data-live-story`, `data-doing`, `data-context`,
  `data-context-pct`, `data-context-label`; the filter has `data-filter-button`, `data-filter-label`,
  `data-filter-panel`, `data-filter-option`/`data-filter-toggle="<name>"`, `data-filter-clear`, `data-filter-active`,
  `data-filter-chip="<name>"` and `data-filter-clear-chips`; RD-7 adds `data-tiles-panel="ok|empty|error"`,
  `data-tile="<name>"`, `data-tile-live`, `data-tile-counts`, `data-tile-offmain="<n>"`, `data-tile-prod`,
  `data-tile-preflight`, `data-tile-ran-on`, `data-sparkline`, `data-shipped-panel="ok|empty|error"`,
  `data-shipped-failed="<names>"` and `data-shipped-row="<project>/<ID>"`.

## Configuration
None new. `board.sessions_path` (`BOARD_SESSIONS_PATH`) as for [Live sessions](live-sessions.md); the preflight CSVs
as for [Preflight history](preflight-history.md).

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.overview_viewed` | info | `Overview::logViewed()` | `projects`, `needs`, `picks`, `drafts`, `ready`, `sessions`, `preflight_runs`, `in_flight`, `tiles`, `shipped` (null for a failed panel). Full page loads only, not the 30 s polls |
| `board.overview_panel_failed` | error | `Overview::attempt()` | `panel` (`needs` \| `live` \| `figures` \| `tiles` \| `shipped`), `error` (exception class), `message` |
| `board.shipped_read` | info | `ListShippedThisWeek::read()` | `project`, `count` (build commits). Once per real git read, not per poll (5 min cache) |
| `board.shipped_unreadable` | warning | `ListShippedThisWeek::handle()` | `project`, `error`. That project is named in the panel; the others still list |
| `board.git_refused` | warning | `GitReader::refuse()` | `reason`, `subcommand`. For `log`, any form but the fixed one |
| `board.overview_filtered` | info | `Overview::logFiltered()` | `projects` (resolved names; `[]` after a clear). On a page load that names projects, and on every change (tick, chip, Clear, Back/Forward); never on a poll |
| `board.overview_filter_ignored` | warning | `ResolveProjectFilter::logIgnored()` | `reason` (`unknown` \| `disabled`), `names` (≤ 20, each ≤ 60 chars). One per reason, on load or change only |
| `board.live_session_unparsed` | warning | `SessionReader::unparsed()` | `file` (basename), `reason` (`usage` \| `tool`); once per file version |

Plus the Live sessions events (`board.sessions_read`, `board.session_unreadable`, `board.session_ignored`) and
`board.preflight_history_unreadable` from the CSV reader and `board.preflight_run_record_skipped` (RD-8) from the
run-record reader, once per skipped file per load. Every line carries `request_id`.
- **Healthy:** one `board.overview_viewed` per page load, with no `board.overview_panel_failed` after it.
- **The page shows every project although the URL names one:** look for `board.overview_filter_ignored`. The name
  is unknown (check its exact case against `/projects`) or the project is switched off.
- **A panel shows "could not be read":** find `board.overview_panel_failed` with that `panel`. The class and
  message name the cause (a query error for `needs`/`figures`, the reader for `live`).
- **Shipped this week names a project it could not read:** `board.shipped_unreadable` carries the git error (a
  missing checkout or a bad ref). A `board.git_refused` with `subcommand: log` means code built a `log` call other
  than `GitReader::log()`'s form.
- **A story built this week is missing from Shipped:** check the commit subject is `feat|fix|test(<ID>):` on the
  project's ref, and that its committer date is in the window. Up to 5 minutes of cache can hide a brand-new commit
  if the sha has not moved (no refresh yet).
- **A card shows "—" or "No tool calls yet" while the session is clearly working:** look for
  `board.live_session_unparsed`. Repeated `usage` or `tool` reasons on every file mean Claude Code changed its
  transcript shape (see ADR-023, ADR-048).

## Testing & verification
- `tests/Feature/Board/HomeOverviewTest.php`: one `it()` per acceptance criterion, plus extras: the route and
  `?project=` redirect, a single `wire:poll.30s`, empty and error states for both panels, `board.overview_viewed`
  on load only, `board.live_session_unparsed` once per version, the doing-line privacy check, a malformed-ID row
  without a link, the head read for a long session, sidechain lines ignored, and the long-window rule.
- `tests/Browser/HomeOverviewTest.php`: a live card with branch, doing line and context bar stays steady through a
  poll, a Needs me row opens its story page, and there is no sideways scroll at 375 px.
- Fixtures: `tests/Support/SessionFixture.php` gained `assistant()` (an assistant line with content blocks, an optional usage record and a sentinel text block
  the page must never show) and `tool()` (a `tool_use` block).
- The once-per-version warning test does `Cache::forget('board.sessions')` between its two reads. Without that,
  the 20 s scan cache hides the second read and the test stays green with the guard deleted (RUNBOOK 2026-10-11).
- `tests/Feature/Board/HomeProjectFilterTest.php` (RD-6): one `it()` per acceptance criterion, plus extras: the
  dropdown lists only enabled projects, `board.overview_filtered` on load and change but not on a poll, no filter
  log on an unfiltered load, an ignored name dropping at the next change, and blank, repeated or padded lists.
- `tests/Browser/HomeProjectFilterTest.php`: choosing a project narrows every panel and the URL without a reload,
  and Back returns all projects. A chip click widens the panels again. There is no sideways scroll at 375 px with
  the dropdown open and a chip shown.
- `tests/Feature/Board/HomeTilesTest.php` (RD-7): one `it()` per acceptance criterion, plus extras: production hidden
  without snapshots or without a connection, fix/test counted and chore/refactor/malformed IDs ignored, a dataset of
  refused `log` forms (each logged), `GitReader::log()` reading a ref since a time, `board.shipped_read`, one git read
  per unchanged snapshot, an unreadable project named while the others list, the empty state, each new panel failing
  alone, the live dot, and no `ran_on` when no run record matches (RD-8).
- `tests/Feature/Board/PreflightRanOnTest.php` (RD-8) covers the tile's "on brain", "on mac" and nothing;
  `tests/Browser/PreflightRanOnTest.php` checks "on brain" in a real browser.
- `tests/Browser/HomeTilesTest.php`: a tile's counts match its Stories page and Shipped lists the build commits; the
  filter narrows tiles and shipped rows; no sideways scroll at 375 px.
- `tests/Support/GitFixture.php` gained `commitAt($message, $at)`, which sets author and committer dates. Write
  fixture commits in date order: `git log --since` stops at the first older commit (RUNBOOK 2026-10-11).
- `log --output=x` is refused by both the `log` form guard and the older `--output` guard; the refused-forms dataset
  is what proves each guard on its own.
- Browser check (RD-7): open `/`; story-board's tile matches its Stories page counts; Shipped this week lists any
  story built in the last 7 days.
- Browser check (RD-6): choose one project and confirm every panel narrows and the URL changes. Press Back and
  confirm all projects return.
- Browser check (story): with a session running, `/` shows its card with branch, doing line and context bar.

## Key decisions & tradeoffs
- One row per story; a pick outranks draft/ready. In flight keeps the owner's "approved, not built" definition even
  though it overlaps Needs me → [ADR-047](../decisions/ADR-047-needs-me-lists-each-story-once.md).
- Live cards read the newest tool call and usage record from the transcript tail, cut down at once; the window is
  inferred → [ADR-048](../decisions/ADR-048-live-cards-summarise-the-latest-tool-call-and-usage.md).
- Every panel fails alone; `board.overview_viewed` logs per page load, not per poll →
  [ADR-049](../decisions/ADR-049-overview-panels-fail-alone-and-log-once-per-load.md).
- One `ProjectFilter` value, resolved once per render, is handed to every panel. Names are matched exactly in PHP.
  Ignored names are logged once per load or change, and left in the URL until the next change →
  [ADR-051](../decisions/ADR-051-one-project-filter-value-for-every-home-panel.md).
- `git log` is admitted in one fixed form: fixed format, `--since=@<unix>`, a ref, `--`, signatures off →
  [ADR-052](../decisions/ADR-052-git-log-is-admitted-in-one-fixed-form.md).
- Shipped is one row per build commit, titled from the snapshot, cached per project and sha, failing per project →
  [ADR-053](../decisions/ADR-053-shipped-this-week-is-build-commits-cached-per-snapshot.md).
- Tiles reuse the Stories page's kind rule, stored off-main rows, stored production snapshots and Live now's
  sessions; the sparkline gained a `decorative` prop →
  [ADR-054](../decisions/ADR-054-project-tiles-reuse-existing-rules-and-stored-data.md).
- The old Home was unrouted and kept under test at a test-only route until RD-9 deleted it →
  [ADR-050](../decisions/ADR-050-old-home-is-tested-at-a-test-only-route-until-rd-9.md) (superseded).

## Known limitations & gotchas
- **A command line shows on the card.** Whatever a session types on a Bash command line (first line, up to 120
  chars) is rendered, so a secret passed inline on a command would show. This is accepted for an owner-only localhost
  app (ADR-048).
- The context window is inferred: a 1M-context session that has not yet passed 200k and whose model id lacks
  `[1m]` reads against 200k.
- Ages are whole days from the `Source:` date, not time waiting since approval; a story with no date reads
  `undated` and sorts last.
- Polling re-renders the whole page, so Needs me and the figures re-query every 30 s.
- The shell sidebar has no Manage projects link; from `/` it is reachable only through the no-projects empty state
  (RD-9 deleted the old sidebar but added no link).
- **An ignored name stays in the URL** (e.g. `?projects=nope,coins` keeps `nope`) until the next change rebuilds the
  list from the resolved names. A Back to such an entry warns again, because it is a change.
- `board.overview_viewed`'s `projects` is the count of every enabled project, not of the filter.
- The filter is project-only, and it is not saved: a bare `/` is always every project. The sidebar's project
  links are unchanged.
- Out of scope: spend per project and the spend card (Money), the Full timeline link (F-2), and any tile
  customization or metric picker.
- **Shipped can lag 5 minutes** behind a new commit until the next refresh moves the stored sha, and its window can
  reach that far past 7 days. A non-conventional subject never appears.
- **Off main counts story rows, not branches**: a branch touching three stories adds three.
- The production row shows only the first enabled metric, from the nightly stored snapshot, and vanishes after 7
  days with none. It is not a live read. Since RD-9 deleted `/prod`, `board:prod-snapshot` is the only production
  read, so without `schedule:work` running the figure never updates (RUNBOOK "Project tiles show no new production
  figure").
- **Live dots depend on Live now.** If that panel fails, no tile is live.
- Tiles fail as one panel; there is no per-field error state as in the mockup.
- Wall time reads "4m 5s" (`Overview::duration()`), not the mockup's "4m 05s"; the mode tag shows the CSV value
  (`scoped`/`full`) as written. "on brain" / "on mac" needs run records, which nothing writes until RD-10.

## Change history
2026-10-11 — `/` becomes the overview: header sentence, four figures, Needs me, Live now with doing line and context bar; `ListNeedsMe`, `ReadOverviewFigures`, `ListLiveSessions::overview()`, `SessionReader` activity fields (RD-5, `6d6f3de`)
2026-10-11 — Projects filter in the header: `?projects=` narrows Needs me, Live now and the figures through one `ProjectFilter`; chips, Clear, ignored-name warnings (RD-6, `a65ff4e`)
2026-10-11 — Projects grid (progress, off main, last preflight, stored production headline, live dot) and Shipped this week (build commits via `GitReader::log()`, cached per snapshot); sparkline `decorative` (RD-7, `4d12af1`)
2026-10-11 — The tile's last preflight reads "on brain" / "on mac" from the matched run record (RD-8, `5811fe3`)
2026-10-11 — `RedirectProjectFilter` removed, `/?project=` ignored; production figures formatted by `MetricNumber`; the old Home deleted (RD-9, `2669db7`)
