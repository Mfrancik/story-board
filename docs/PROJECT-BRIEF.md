# Project brief — story-board
Last refreshed: 2026-09-29 (SB-17)

## What it is, and for whom
A localhost Laravel app for one owner. It reads the `stories/` folder of every project that uses
the dev-standards kit and shows, in one place, what state each project's stories are in. It reads
from git, at each project's ref (default `origin/main`), and never writes to a project (owner
ruling). It can also read a project's production MySQL database, read-only, for headline numbers. Work not on the ref yet (unmerged branches, worktrees, untracked files) is shown beside it,
labelled with where it lives, and never counts as status. Live Claude Code sessions are shown from
their transcript metadata (never their messages). Each project's handbook shows how it has drifted
from the kit.

## Domain model
- **Project**: a registered local checkout (`name`, `path`, `ref`, `is_enabled`; off hides it everywhere
  and stops its refreshes, keeping its rows). Refresh `state` (`pending | ok | stale | unreachable`),
  `sha`/`indexed_at` of its last good snapshot, and `refresh_attempted_at` (drives page-load staleness).
- **Story**: one story file, as reported by the kit's `bin/story-index` (ID, title, status, initiative,
  journey, path, source, depends_on, mockups, parse_errors), plus `is_parked` and `dated_on` computed at
  refresh. Text and mockups are read from git at the row's SHA on demand. `location_kind` is null for
  the ref's row, else `branch | worktree | untracked` with a `location` label; one row per version.
- **ProjectLocation**: another checkout of a project: an `alias` (by hand) or a `worktree` (found on refresh).
- **Snapshot**: a project's on-ref Story rows, replaced wholesale on each successful refresh and kept
  as-is when one fails. Off-main rows are a second snapshot, replaced by each off-main scan.
- **Live session** (not stored): a transcript changed in the last 10 min, reduced to `cwd`, branch and
  times, matched to a project by path and to stories by branch name.
- **Kit** (not stored, not a project): the dev-standards checkout at `board.kit_path` / `kit_ref`, read
  through `GitReader` to badge each project's standards and skills.
- **ProdConnection**: a project's one read-only production MySQL connection (credentials encrypted with
  APP_KEY, `use_ssl`, `verified_at`). **ProdMetric**: a preset (table/column in `config`) or custom single
  `SELECT`, `key` stable across saves, `position`, `is_enabled`.
- **ProductionReader**: the only code that opens a production connection. It uses runtime PDO, a
  read-only 5 s session and a `SHOW GRANTS` proof on every open.
- **GitReader**: the only code that runs git. Read-only subcommands only, plus `worktree list` and
  `status --porcelain` in those forms only.

## Features
| Feature | Status | Doc |
|---|---|---|
| Project registry and story reader: `board:project`, `board:refresh`, queued page-load refresh, `GitReader` | active (SB-2, SB-3) | [doc](features/project-registry-and-reader.md) |
| All-projects dashboard: `/` leads with What needs me, then In flight, project tiles and collapsible sections | active (SB-3, SB-4, SB-5, SB-8, SB-9) | [doc](features/what-needs-me-home.md) |
| Story page and mockups: `/p/{project}/s/{id}`, sandboxed mockup frames, full-screen compare, `?v=` versions | active (SB-4, SB-5) | [doc](features/story-page-and-mockups.md) |
| Not on main: branches, worktrees and untracked stories/mockups, indexed at refresh; `board:project alias` | active (SB-5) | [doc](features/not-on-main.md) |
| App shell and project switcher: sidebar, filterable project list, drawer below 768px, `/p/{project}` refusal | active (SB-7, SB-10) | [doc](features/app-shell-and-project-switcher.md) |
| Story modal: `?story=<project>/<ID>` on `/` and `/p/{project}`; Back closes | active (SB-8) | [doc](features/story-modal.md) |
| Single-project dashboard: `/p/{project}` header and refresh, scoped cards, Progress by initiative | active (SB-10) | [doc](features/single-project-dashboard.md) |
| Manage projects: `/projects` switch on/off, add by folder, remove behind a confirmation | active (SB-12) | [doc](features/manage-projects.md) |
| Live sessions: Live now panel on both dashboards (30 s poll) and sidebar live badge | active (SB-11) | [doc](features/live-sessions.md) |
| Project handbook: `/p/{project}/handbook` rules, lessons, standards, runbook, decisions, skills; kit badges | active (SB-14) | [doc](features/project-handbook.md) |
| Production connection and metrics: read-only prod MySQL per project on `/projects`, presets and custom SQL | active (SB-17) | [doc](features/production-connection.md) |

## Journeys
None yet. Every SB story so far is `Journey: none`.

## Stories
- **built**: SB-2 registry and reader · SB-3 what needs me · SB-4 story and mockups · SB-5 not on main ·
  SB-7 app shell · SB-8 story modal · SB-9 all-projects dashboard · SB-10 single-project dashboard ·
  SB-11 live sessions · SB-12 manage projects · SB-14 project handbook · SB-17 production connection
- **approved**: SB-13 pick a mockup · SB-15 stories by initiative · SB-16 preflight history ·
  SB-18 production dashboard
- **draft (parked)**: SB-6 the board on an always-live domain

SB-1 (the `bin/story-index` parser) lives in the dev-standards kit, not in this repo.

## Key decisions
- All git goes through `GitReader`, an allow-list with argument and ref guards →
  [ADR-004](decisions/ADR-004-gitreader-read-only-git-gateway.md); four more read-only forms, untracked
  files read only at refresh → [ADR-012](decisions/ADR-012-off-main-reads-stay-read-only-and-in-git.md)
- Snapshots go stale on fetch failure and are replaced wholesale; refresh runs on the queue, unique per
  project → [ADR-005](decisions/ADR-005-snapshot-refresh-model.md), [ADR-006](decisions/ADR-006-refresh-runs-on-the-queue.md)
- Story text is read from git on demand, not stored → [ADR-007](decisions/ADR-007-story-text-read-on-expand.md)
- Mockups are served from `git show` through one CSP-sandboxed route; an unparsed choice is quoted,
  never guessed → [ADR-008](decisions/ADR-008-mockups-served-from-git-in-a-sandbox.md), [ADR-009](decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md)
- Off-main work is what a branch changed since its merge-base, parsed by the kit's own `index_story()`
  → [ADR-010](decisions/ADR-010-off-main-is-what-a-branch-changed-since-its-merge-base.md), [ADR-011](decisions/ADR-011-off-main-text-parsed-by-the-kits-own-parser.md)
- An unknown or disabled project is refused by middleware before route binding; Livewire pages
  re-check on every request → [ADR-013](decisions/ADR-013-project-refusal-runs-before-route-binding.md), ADR-019 amendment
- `/p/{project}` is its own `ProjectPage` (supersedes ADR-014); the rollup is one grouped query →
  [ADR-019](decisions/ADR-019-project-page-is-its-own-component.md), [ADR-020](decisions/ADR-020-initiative-rollup-is-one-grouped-query.md)
- `/` leads with What needs me, drops the project filter; tiles and In flight ignore filters and add no
  query → [ADR-017](decisions/ADR-017-dashboard-leads-with-what-needs-me-and-drops-the-project-filter.md), [ADR-018](decisions/ADR-018-portfolio-figures-ignore-filters-and-add-no-query.md)
- The story modal drives history from Alpine; `?story` has one parser and names a story, not a version
  → [ADR-015](decisions/ADR-015-story-modal-history-is-driven-by-alpine.md), [ADR-016](decisions/ADR-016-one-story-link-parser-and-one-version-picker.md)
- Manage projects re-navigates after every write; Add reuses `RegisterProject` and re-runs its checks
  → [ADR-021](decisions/ADR-021-manage-projects-re-navigates-so-the-sidebar-follows.md), [ADR-022](decisions/ADR-022-add-project-classifies-refusals-by-re-running-checks.md)
- Live sessions read Claude Code's undocumented transcripts defensively, not via a kit hook →
  [ADR-023](decisions/ADR-023-live-sessions-read-transcript-metadata-defensively.md)
- Handbook sections load on first open via renderless calls and stay in Alpine; no re-render →
  [ADR-024](decisions/ADR-024-handbook-sections-load-lazily-and-stay-client-side.md)
- Production is read only through `ProductionReader`: raw PDO outside `config/`, a read-only session,
  `SHOW GRANTS` proof on every open and an SQL guard. This amends "git only" →
  [ADR-029](decisions/ADR-029-production-reads-go-through-one-read-only-gateway.md)
- The board never writes to a project (owner ruling); its only writes are its own database.
- Preflight audit scope, audit cost record, build-artifact disposal (kit) → ADR-001 to ADR-003

## Current phase and what's next
Phase 2: UI organisation (SB-7 to SB-14). Phase 1 (SB-2 to SB-5) is built and retro'd. SB-7 to SB-12
and SB-14 have shipped, and SB-17 (production connection) with them. Next: SB-18 (production dashboard
and daily snapshots, on `ProductionReader::readEnabledMetrics()`), SB-13, SB-15, SB-16, then the phase-2
retro and full preflight sweep. SB-6 (hosted) stays parked. F-2 (a timeline of what was built) is in
the backlog.

## Known limitations
- Laravel 13 / Livewire 4 are installed (starter kit), while `CLAUDE.md` says 12 / 3. Flagged, unchanged.
- Refresh needs a queue worker; `composer run dev` starts one, a bare `artisan serve` does not. A
  failing project is retried at most every 5 minutes.
- Until the kit parser reads `Chosen option: **B**` (F-1), "Awaiting a pick" over-reports and the story
  page quotes such a choice without marking a frame.
- Untracked stories and mockups are listed but cannot be shown (not in git). Off-main rows with
  malformed IDs cannot open the modal (owner decision pending). `?v=` links break at the next refresh.
- Layout classes still use the raw `zinc-*` palette; only status and state colours are theme tokens.
- Initiative rows on the project page open nothing; the project page has no initiative or search filter.
- `/projects` has no auth, can add and delete projects and now holds production credentials. It is safe
  only while localhost-only, and SB-6 must add auth first. A project's
  path and ref cannot be edited (remove and re-add).
- Live sessions rely on an undocumented format and file mtime (10 min); the sidebar badge does not poll.
- The handbook has no tab counts or drift summary, renders the runbook whole, and compares against the
  kit checkout's last-fetched ref. Its real-data browser check is pending on the owner's side.
- Production SSL is encrypted but not certificate-verified. Rotating APP_KEY breaks stored credentials.
  The real coins production check awaits the owner's read-only user.
- Dev server runs on port 8010 (coins holds 8000/8001). No git remote for this repo, by owner ruling.
