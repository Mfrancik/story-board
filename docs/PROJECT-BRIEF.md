# Project brief — story-board
Last refreshed: 2026-10-11 (RD-4)

## What it is, and for whom
A localhost Laravel app for one owner. It reads the `stories/` folder of every project that uses
the dev-standards kit and shows, in one place, what state each project's stories are in. It reads
from git, at each project's ref (default `origin/main`). Its one write into a project is a mockup pick
(from the gallery or the story page): two lines of one story file, one local commit, never pushed (ADR-032).

It also shows:
- Each project's production numbers, from its production MySQL database read-only, side by side on
  `/prod` with daily history.
- Work not on the ref yet (unmerged branches, worktrees, untracked files), labelled with where it
  lives. It never counts as status.
- Live Claude Code sessions, from their transcript metadata (never their messages).
- Each project's handbook, and how it has drifted from the kit.
- Each project's preflight runs, from the kit meter's cost CSVs under `~/.claude/projects`.
- A clickable map of each project's journeys, drawn from its journey docs, stories and mockups, with
  screenshots from the project's own journey tests (`journeyStep()`).

The app has no auth. The starter kit's auth was removed in RD-1 (ADR-037).

## Domain model
- **Project**: a registered checkout (`name`, `path`, `ref`, `is_enabled`). Off hides it everywhere and stops its
  refreshes but keeps its rows. It also holds refresh `state` (`pending | ok | stale | unreachable`), `sha` /
  `indexed_at`, and `refresh_attempted_at`.
- **Story**: one story file as reported by the kit's `bin/story-index`, plus `is_parked` and `dated_on`. Text and
  mockups are read from git at the row's SHA on demand. `location_kind` is null on the ref, else
  `branch | worktree | untracked`.
- **ProjectLocation**: another checkout of a project, either an `alias` (added by hand) or a `worktree` (found on refresh).
- **Snapshot**: a project's on-ref Story rows, replaced wholesale on each good refresh and kept when one fails.
  Off-main rows are a second snapshot.
- **Not stored, read on demand**: live sessions (transcripts changed in the last 10 min), preflight runs (cost-CSV
  rows), the kit (`board.kit_path`), mockup sets (`ReadMockupSets`), journey maps (`ReadJourneyMap`), journey
  shots (manifest lookup, `ReadJourneyShots`), and current version (`ReadCurrentVersion`).
- **ProdConnection / ProdMetric / ProdSnapshot**: one read-only production connection per project. A metric is
  a preset or a custom `SELECT`. A snapshot is one value per metric per local day.
- **Gateways**:
  - `GitReader` runs every git read (an allow-list), including `remotes()`.
  - `ProductionReader` opens every production connection.
  - `StoryPickWriter` makes every write to a project, and only writes. Both pick surfaces reach it through
    `PickMockup` (RD-3).
- **Shared rules** (pure, RD-1): `StoryStatusOrder` holds the status order, the open statuses and the
  initiative rollup. `MockupGateParser` reads a story's mockup gate.

## Features
| Feature | Status | Doc |
|---|---|---|
| Project registry and story reader: `board:project`, `board:refresh`, queued refresh, `GitReader` | active (SB-2, SB-3) | [doc](features/project-registry-and-reader.md) |
| All-projects dashboard: `/` What needs me, In flight, project tiles, collapsible sections | active (SB-3–5, SB-8, SB-9) | [doc](features/what-needs-me-home.md) |
| Story page and mockups: `/p/{project}/s/{id}` short first in the shell: summary, Pick, one frame with Today, folded details, `?v=` | active (SB-4, SB-5, RD-3) | [doc](features/story-page-and-mockups.md) |
| Not on main: branches, worktrees and untracked stories/mockups, indexed at refresh | active (SB-5) | [doc](features/not-on-main.md) |
| App shell and project switcher: the old sidebar on `/`, `/p/{project}`, `/prod` until RD-9 | active (SB-7, SB-10) | [doc](features/app-shell-and-project-switcher.md) |
| Story modal: `?story=<project>/<ID>` on `/` and `/p/{project}` until RD-9 | active (SB-8) | [doc](features/story-modal.md) |
| Single-project dashboard: `/p/{project}` header, refresh, scoped cards, Progress by initiative | active (SB-10) | [doc](features/single-project-dashboard.md) |
| Manage projects: `/projects` on/off, add by folder, remove behind a confirmation | active (SB-12) | [doc](features/manage-projects.md) |
| Live sessions: Live now panel (30 s poll) and sidebar badge | active (SB-11, SB-25) | [doc](features/live-sessions.md) |
| Project handbook: `/p/{project}/handbook` with kit badges | active (SB-14) | [doc](features/project-handbook.md) |
| Stories by initiative: `/p/{project}/stories` two panes, tags, filter chips | active (SB-15) | [doc](features/stories-by-initiative.md) |
| Preflight history: `/p/{project}/preflight` ledger, trends, charts, filters, columns picker | active (SB-16, SB-19) | [doc](features/preflight-history.md) |
| Production connection and metrics: read-only prod MySQL per project on `/projects` | active (SB-17) | [doc](features/production-connection.md) |
| Production dashboard: `/prod` projects × metrics, queued reads, nightly snapshot | active (SB-18) | [doc](features/production-dashboard.md) |
| Mockup gallery: `/mockups`, full-screen viewer, Current pane, Pick commits the story file | active (SB-21, SB-23) | [doc](features/mockup-gallery.md) |
| App map: `/p/{project}/map` journeys as screen flows | active (SB-24) | [doc](features/app-map.md) |
| Journey shots: kit Pest helper `journeyStep()` | active (SB-22) | [doc](features/journey-shots.md) |
| Board foundation: `StoryStatusOrder`, `MockupGateParser`, `GuardsProjectPage`; starter kit removed | active (RD-1) | [doc](features/board-foundation.md) |
| Dark shell and sidebar: `layouts/shell` for every kept page, Home A tokens, Geist, toasts, page-load refresh | active (RD-2, RD-4) | [doc](features/dark-shell.md) |

## Journeys
None yet. Every SB and RD story so far is `Journey: none`.

## Stories
- **built**:
  - SB-2–5, SB-7–12, SB-14–19, SB-21–25.
  - RD-1 clean foundation · RD-2 dark shell and sidebar · RD-3 short story page · RD-4 re-skin kept pages.
- **approved**: RD-5 home overview ·
  RD-6 home project filter · RD-7 project tiles and Shipped · RD-8 preflight "Ran on" · RD-9 retire old pages
- **draft**: SB-20 preflight run errors · RD-10 preflight run record (parked)
- **draft (parked)**: SB-6 the board on an always-live domain
- **cut**: SB-13 pick a mockup (SB-21's pick covers it)

SB-1 (the `bin/story-index` parser) lives in the dev-standards kit, not in this repo.

## Key decisions
- All git goes through `GitReader`, an allow-list with guards; untracked files are read only at refresh → ADR-004, ADR-012
- Snapshots are replaced wholesale and go stale on fetch failure; refresh runs on the queue → ADR-005, ADR-006
- Story text is read from git on demand → ADR-007. Mockups are served from git in a CSP sandbox, and an unparsed choice is quoted → ADR-008, ADR-009
- Off-main work is what a branch changed since its merge-base, parsed by the kit's parser → ADR-010, ADR-011
- Unknown or disabled projects are refused before route binding, and pages re-check on update → ADR-013, ADR-019
- `/p/{project}` is its own component, and its rollup is one grouped query → ADR-019, ADR-020
- `/` leads with What needs me. Its portfolio figures ignore filters → ADR-017, ADR-018
- The story modal's history is driven by Alpine, through one `?story` parser → ADR-015, ADR-016
- Manage projects re-navigates after writes, and Add re-runs `RegisterProject`'s checks → ADR-021, ADR-022
- Live sessions read transcripts defensively, and cards link untracked stories only from their own checkout → ADR-023, ADR-034
- Handbook sections load lazily and stay client-side → ADR-024
- The Stories page is one query over the shared rollup, and it filters with CSS → ADR-025, ADR-026
- Preflight history reads the cost CSVs by folder name, renders on the server and recomputes in Alpine → ADR-027, ADR-028
- Production is read only through `ProductionReader`, and the read outcome is cached. The snapshot runs in-process → ADR-029–031
- The board writes narrowly: one pick, one file, one local commit → [ADR-032](decisions/ADR-032-the-board-writes-narrowly-one-pick-one-file-one-commit.md)
- The app map is generated server-side, and journey shots are a test helper with a pinned manifest → ADR-033, ADR-035
- "New page" is read statically from route files → ADR-036
- The auth starter kit and the `laravel/fortify` package are removed, not disabled → [ADR-037](decisions/ADR-037-starter-kit-and-fortify-package-removed.md)
- Kept logic lives in pure shared classes: `StoryStatusOrder`, `MockupGateParser`, `pick()` returning its branch, and a guard trait with hooks → [ADR-038](decisions/ADR-038-kept-board-logic-moves-into-pure-shared-classes.md)
- The dark shell: Geist from fontsource, styling scoped by `.shell` and a `shell:` variant → ADR-039, ADR-040. Kept pages moved in, sharing primitives by variant, Flux toasts behind an appearance stub, no links to the old dashboard → ADR-044–046
- Refresh on load is one middleware on the page-route group, not per-page `mount()` → [ADR-041](decisions/ADR-041-refresh-on-load-is-one-middleware-on-the-page-routes.md)
- One pick action, `PickMockup`, serves the viewer and the story page; the short story page replaced the long one in place, trimmed to the approved mockup → [ADR-042](decisions/ADR-042-one-pick-action-shared-by-story-page-and-viewer.md), [ADR-043](decisions/ADR-043-short-story-page-replaces-the-long-one-in-place.md)
- Preflight audit scope, audit cost record, build-artifact disposal (kit) → ADR-001 to ADR-003

## Current phase and what's next
Phase 3, the redesign (RD). RD-1 to RD-4 are built: foundation, dark shell, short story page, and every kept page
in the shell. Next are RD-5 to RD-8 (home, filter, tiles, preflight "Ran on"); then RD-9 retires `/`'s old form, the
old dashboard, the story modal and `layouts/board`. Page-load refresh is tested at its middleware, so that costs no coverage.

Still open from phase 2:
- The owner confirms SB-19's checkbox look.
- `journeyStep()` is adopted in coins, followed by the real-data check of the app map and Current pane.
- The `--text-thumb` token decision.

Backlog: F-2 (build timeline), F-3 (meter tier labels), F-5 (preflight run record), F-6 (/story writes a
`Summary:` line), F-7 (Production item in the shell sidebar).

## Known limitations
- Laravel 13 / Livewire 4 are installed, while `CLAUDE.md` says 12 / 3. Flagged, unchanged.
- Starter-kit leftovers are still unused: `resources/js/passkeys.js` and its Vite input, the
  `@laravel/passkeys` npm dependency, `UserFactory::withTwoFactor()`, and the `users` / `passkeys` tables and
  `two_factor_*` columns. These are left for the owner (RD-1).
- Refresh and `/prod` reads need a queue worker (`composer run dev` starts one). The nightly snapshot also needs
  `schedule:work`, which `composer run dev` does not start. A failing project is retried at most every 5 min.
- Until the kit parser reads `Chosen option: **B**` (F-1), "Awaiting a pick" over-reports on the ref.
- Untracked stories and mockups are listed but cannot be shown. Off-main rows with malformed IDs cannot open the
  modal. `?v=` links break at the next refresh.
- Story summaries mostly fall back to the first sentence of `## Story` until the kit writes `Summary:` (F-6). The
  shell sidebar has no Production item (F-7). The story modal keeps the long form until RD-9.
- Two layouts coexist until RD-9: `layouts/board` keeps `/`, `/p/{project}` and `/prod`; status-chip, status-bar and
  project-tabs carry `dark:` and `shell:` tones side by side. The shell sidebar has no live badge.
- `/projects` and `/prod` have no auth, hold production credentials, and are safe only while localhost-only.
  SB-6 must add auth first. A project's path and ref cannot be edited.
- Live sessions rely on an undocumented transcript format and a 10-minute mtime, and the sidebar badge does not
  poll.
- The handbook compares against the kit checkout's last-fetched ref. Its real-data check is pending.
- Production SSL is not certificate-verified, and rotating APP_KEY breaks stored credentials. The coins
  production check awaits the owner's read-only user.
- Preflight history has no test counts or verdict (F-5). Its median and number formats exist in both PHP and
  JS. Its hidden columns are saved per browser.
- Flux reads `localStorage` unguarded, so a browser that blocks site data errors on every page (RUNBOOK).
- A board pick shows at once in the gallery and on the story page, but elsewhere only after a push and refresh. Its `--no-verify`
  awaits owner confirmation. "New page" can be wrong for routes not in `routes/*.php`.
- The app map's page weight is its cost driver (coins: about 3.1 MB). Shots are read from the working tree,
  not the ref. Journey shots must not run under `--parallel`.
- The dev server runs on port 8010. By owner ruling, this repo has no git remote.
