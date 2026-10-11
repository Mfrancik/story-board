# Home overview
Status: active   ·   Last updated: 2026-10-11   ·   Stories: RD-5, RD-6

## Overview
`/` is the redesign's home, Home A "command center" ([`docs/mockups/RD-5/option-a.html`](../mockups/RD-5/option-a.html))
in the [dark shell](dark-shell.md). It answers three questions at a glance: how many things need the owner, what
is being built right now, and how preflight is doing. A header sentence ("7 things need you. 3 sessions are
building.") sits over four headline figures, then two panels: **Needs me** (oldest first) and **Live now** (one card
per running Claude Code session, with what it is doing and how full its context is). It replaces the SB-9
[all-projects dashboard](what-needs-me-home.md), which stays in the code, unrouted, until RD-9. A **Projects** filter
in the header (RD-6) narrows every panel to chosen projects; by default it covers them all.

## How it works
**Route.** `routes/web.php` maps `/` (name `home`) to `app/Livewire/Board/Overview.php`, inside the
`RefreshStaleProjects` group, still carrying `RedirectProjectFilter`, so an old `/?project=x` URL lands on `/p/x`.
That middleware stays on purpose until RD-9 (RD-6's Do-NOT-touch). The filter uses `?projects=` (plural), which the
middleware does not touch.

**Render.** `Overview::render()` first counts enabled projects. With none, the page renders one empty state linking
to `/projects` (Manage projects) and no panels. Otherwise it resolves the project filter once (below), then fetches
three sources, each through `Overview::attempt()`, a per-panel `try/catch`, and each handed the same filter:
- `needs`: `app/Actions/Board/ListNeedsMe.php:handle($filter)`
- `live`: `app/Actions/Board/ListLiveSessions.php:overview($filter)`
- `figures`: `app/Actions/Board/ReadOverviewFigures.php:handle($filter)`

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
must take the same value ([ADR-051](../decisions/ADR-051-one-project-filter-value-for-every-home-panel.md)).

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
narrowers, and the old `ListWhatNeedsMe` now calls them too, so both pages count the same way. `handle()` runs them
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

**Polling.** `wire:poll.30s` sits on the Live now `<section>` only, but it re-renders the whole component, so the
figures and the header sentence follow the cards. The session scan itself is still cached 20 s
(`board.sessions`).

## Data model
None. No migration. Reads `stories` (`status`, `is_parked`, `mockups`, `dated_on`, `location_kind`), `projects`
(`is_enabled`), the preflight cost CSVs and the session transcripts. Cache keys added:
`board.live_session_unparsed:<sha1(file|mtime|reason)>` (the once-per-version marker, `LIVE_MINUTES`).

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
- `Overview::duration(int|float $value, bool $seconds = true): string`.
- Blade `<x-shell.figure key label value accent tone>`; `tone` is `needs | live | null`.
- Test hooks: `data-overview-empty`, `data-figure="live|needs|preflight|in-flight"` with `data-figure-value`,
  `data-needs-panel="ok|empty|error"`, `data-needs-row="<kind>"`, `data-needs-story="<project>/<ID>"`,
  `data-live-now="<state>"`, `data-live-card`, `data-live-story`, `data-doing`, `data-context`,
  `data-context-pct`, `data-context-label`; the filter has `data-filter-button`, `data-filter-label`,
  `data-filter-panel`, `data-filter-option`/`data-filter-toggle="<name>"`, `data-filter-clear`, `data-filter-active`,
  `data-filter-chip="<name>"` and `data-filter-clear-chips`.

## Configuration
None new. `board.sessions_path` (`BOARD_SESSIONS_PATH`) as for [Live sessions](live-sessions.md); the preflight CSVs
as for [Preflight history](preflight-history.md).

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.overview_viewed` | info | `Overview::logViewed()` | `projects`, `needs`, `picks`, `drafts`, `ready`, `sessions`, `preflight_runs`, `in_flight` (null for a failed panel). Full page loads only, not the 30 s polls |
| `board.overview_panel_failed` | error | `Overview::attempt()` | `panel` (`needs` \| `live` \| `figures`), `error` (exception class), `message` |
| `board.overview_filtered` | info | `Overview::logFiltered()` | `projects` (resolved names; `[]` after a clear). On a page load that names projects, and on every change (tick, chip, Clear, Back/Forward); never on a poll |
| `board.overview_filter_ignored` | warning | `ResolveProjectFilter::logIgnored()` | `reason` (`unknown` \| `disabled`), `names` (≤ 20, each ≤ 60 chars). One per reason, on load or change only |
| `board.live_session_unparsed` | warning | `SessionReader::unparsed()` | `file` (basename), `reason` (`usage` \| `tool`); once per file version |

Plus the Live sessions events (`board.sessions_read`, `board.session_unreadable`, `board.session_ignored`) and
`board.preflight_history_unreadable` from the CSV reader. Every line carries `request_id`.
- **Healthy:** one `board.overview_viewed` per page load, with no `board.overview_panel_failed` after it.
- **The page shows every project although the URL names one:** look for `board.overview_filter_ignored`. The name
  is unknown (check its exact case against `/projects`) or the project is switched off.
- **A panel shows "could not be read":** find `board.overview_panel_failed` with that `panel`. The class and
  message name the cause (a query error for `needs`/`figures`, the reader for `live`).
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
- The old Home is unrouted and kept under test at a test-only route until RD-9 →
  [ADR-050](../decisions/ADR-050-old-home-is-tested-at-a-test-only-route-until-rd-9.md).

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
  (RD-9's sidebar work).
- **An ignored name stays in the URL** (e.g. `?projects=nope,coins` keeps `nope`) until the next change rebuilds the
  list from the resolved names. A Back to such an entry warns again, because it is a change.
- `board.overview_viewed`'s `projects` is the count of every enabled project, not of the filter.
- The filter is project-only, and it is not saved: a bare `/` is always every project. The sidebar's project
  links are unchanged.
- RD-7's tiles and Shipped this week must accept the `ProjectFilter` too. Out of scope here: those panels (RD-7)
  and any metric picker.
- `docs/mockups/RD-7/` landed on this branch too; it belongs to RD-7.

## Change history
2026-10-11 — `/` becomes the overview: header sentence, four figures, Needs me, Live now with doing line and context bar; `ListNeedsMe`, `ReadOverviewFigures`, `ListLiveSessions::overview()`, `SessionReader` activity fields (RD-5, `6d6f3de`)
2026-10-11 — Projects filter in the header: `?projects=` narrows Needs me, Live now and the figures through one `ProjectFilter`; chips, Clear, ignored-name warnings (RD-6, `a65ff4e`)
