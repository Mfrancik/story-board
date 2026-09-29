# Project brief — story-board
Last refreshed: 2026-09-29 (SB-12)

## What it is, and for whom
A localhost Laravel app for one owner. It reads the `stories/` folder of every project that uses
the dev-standards kit and shows, in one place, what state each project's stories are in. It reads
from git only, at each project's ref (default `origin/main`), and never writes to a project. That is
an owner ruling. Work that is not on the ref yet (unmerged branches, worktrees, untracked files) is
shown beside it, labelled with where it lives, and never counts as status.

## Domain model
- **Project**: a registered local checkout (`name`, `path`, `ref`, `is_enabled`; off hides it everywhere
  and stops its refreshes, keeping its rows). It has a refresh
  `state` (`pending | ok | stale | unreachable`), the `sha`/`indexed_at` of its last good snapshot,
  and `refresh_attempted_at` (last try, any outcome), which drives page-load staleness.
- **Story**: one story file, as reported by the kit's `bin/story-index`. It holds
  ID, title, status, initiative, journey, path, source, depends_on, mockups and parse_errors, plus
  two facts computed at refresh: `is_parked` (initiative README says `Status: draft group`) and
  `dated_on` (first date in `Source:`). Story text and mockup files are not stored; they are read
  from git at the row's SHA on demand. `location_kind` is null for the ref's row. It is `branch`,
  `worktree` or `untracked` for an off-main version, with a `location` label and `branch`. A story
  can have several rows, one per version.
- **ProjectLocation**: another checkout of a project. An `alias` (a sibling clone, registered by
  hand) or a `worktree` (found by `git worktree list` on each refresh).
- **Snapshot**: a project's on-ref Story rows, replaced wholesale on each successful refresh and kept
  as-is when a refresh fails. Off-main rows are a second snapshot, replaced by each off-main scan.
- **GitReader**: the only code that runs git. It allows read-only subcommands only, plus
  `worktree list` and `status --porcelain` in those forms only.

## Features
| Feature | Status | Doc |
|---|---|---|
| Project registry and story reader: `board:project`, `board:refresh`, queued page-load refresh, `GitReader` | active (SB-2, SB-3) | [doc](features/project-registry-and-reader.md) |
| All-projects dashboard: `/` leads with What needs me cards, then In flight, a tile per project and the collapsible sections; rows open the story modal | active (SB-3, SB-4, SB-5, SB-8, SB-9) | [doc](features/what-needs-me-home.md) |
| Story page and mockups: `/p/{project}/s/{id}`, story above sandboxed mockup frames, 375/768/1280, full-screen compare; raw mockup route; `?v=` versions banner | active (SB-4, SB-5) | [doc](features/story-page-and-mockups.md) |
| Not on main: branches, worktrees and untracked stories/mockups, indexed at refresh; home section and story-page banner; `board:project alias` | active (SB-5) | [doc](features/not-on-main.md) |
| App shell and project switcher: sidebar on every page, filterable project list, drawer below 768px, `/p/{project}` refusal, `/?project=` redirect | active (SB-7, SB-10) | [doc](features/app-shell-and-project-switcher.md) |
| Story modal: `?story=<project>/<ID>` on `/` and `/p/{project}`, text, details, dependency chips, mockups, versions off main; Back closes | active (SB-8) | [doc](features/story-modal.md) |
| Single-project dashboard: `/p/{project}` header with one-project refresh, scoped What needs me cards, Progress by initiative, Not on main by kind | active (SB-10) | [doc](features/single-project-dashboard.md) |
| Manage projects: `/projects` switch on/off, add by folder, remove behind a confirmation; `board:project enable` | active (SB-12) | [doc](features/manage-projects.md) |

## Journeys
None yet. Every SB story so far is `Journey: none`.

## Stories
- **built**: SB-2 registry and reader · SB-3 what needs me · SB-4 story and mockups · SB-5 not on main ·
  SB-7 app shell · SB-8 story modal · SB-9 all-projects dashboard · SB-10 single-project dashboard ·
  SB-12 manage projects
- **approved**: SB-11 live sessions · SB-13 pick a mockup · SB-14 project handbook
- **draft (parked)**: SB-6 the board on an always-live domain

SB-1 (the `bin/story-index` parser) lives in the dev-standards kit, not in this repo.

## Key decisions
- All git goes through `GitReader`: a subcommand allow-list plus argument and ref guards →
  [ADR-004](decisions/ADR-004-gitreader-read-only-git-gateway.md)
- Snapshots go stale on fetch failure and are replaced wholesale →
  [ADR-005](decisions/ADR-005-snapshot-refresh-model.md)
- Refresh runs on the queue, unique per project, carrying the request ID (replaces ADR-005's
  after-response dispatch) → [ADR-006](decisions/ADR-006-refresh-runs-on-the-queue.md)
- Story text is read from git on demand (now when the story modal opens), not stored →
  [ADR-007](decisions/ADR-007-story-text-read-on-expand.md)
- Mockups are served from `git show` at the snapshot SHA through one CSP-sandboxed route →
  [ADR-008](decisions/ADR-008-mockups-served-from-git-in-a-sandbox.md)
- A mockup choice the kit parser cannot read is quoted, never guessed →
  [ADR-009](decisions/ADR-009-unparsed-choice-is-quoted-not-guessed.md)
- Off-main work is what a branch changed since its merge-base and still differs from the ref, with
  stacked branches deduplicated →
  [ADR-010](decisions/ADR-010-off-main-is-what-a-branch-changed-since-its-merge-base.md)
- Off-main text is parsed by the kit's own `index_story()` through a Python shim →
  [ADR-011](decisions/ADR-011-off-main-text-parsed-by-the-kits-own-parser.md)
- Four more read-only git forms are allowed. Untracked files are read only at refresh and never
  served → [ADR-012](decisions/ADR-012-off-main-reads-stay-read-only-and-in-git.md)
- An unknown or disabled project is refused by one middleware that runs before route binding, so it
  always logs → [ADR-013](decisions/ADR-013-project-refusal-runs-before-route-binding.md)
- `/p/{project}` is its own `ProjectPage`, sharing the What needs me cards with `/` (supersedes
  ADR-014) → [ADR-019](decisions/ADR-019-project-page-is-its-own-component.md)
- The initiative rollup is one grouped query, and off-main rows load only when a kind opens →
  [ADR-020](decisions/ADR-020-initiative-rollup-is-one-grouped-query.md)
- `/` leads with What needs me; the project filter was removed, not hidden (the sidebar switches) →
  [ADR-017](decisions/ADR-017-dashboard-leads-with-what-needs-me-and-drops-the-project-filter.md)
- Tiles and In flight ignore initiative/search, add no query (pinned by a query-count test), and the
  header shows the stalest snapshot → [ADR-018](decisions/ADR-018-portfolio-figures-ignore-filters-and-add-no-query.md)
- The story modal pushes its own history from Alpine before the server call →
  [ADR-015](decisions/ADR-015-story-modal-history-is-driven-by-alpine.md)
- `?story` has one parser, the version rule is shared with the story page, and a link names a story,
  not a version → [ADR-016](decisions/ADR-016-one-story-link-parser-and-one-version-picker.md)
- Manage projects re-navigates after every write so the layout's sidebar follows; toasts persist →
  [ADR-021](decisions/ADR-021-manage-projects-re-navigates-so-the-sidebar-follows.md)
- Add project reuses `RegisterProject` unchanged and names refusals by re-running its checks →
  [ADR-022](decisions/ADR-022-add-project-classifies-refusals-by-re-running-checks.md)
- The board never writes to a project: no approve, pick or cancel actions (owner ruling). Its only
  writes are its own database (Manage projects). Compare is Alpine-only.
- Preflight audit scope and isolation, audit cost record, build-artifact disposal (kit) →
  ADR-001 to ADR-003

## Current phase and what's next
Phase 2: UI organisation (SB-7 to SB-14). Phase 1 (SB-2 to SB-5) is built and
retro'd. SB-7 (sidebar shell), SB-8 (story modal), SB-9 (all-projects dashboard), SB-10 (single-project
dashboard) and SB-12 (Manage projects) have shipped. Next: SB-11 (live sessions, fills both dashboards'
Live now slot), SB-13 (pick a mockup, reuses `board/confirm-modal`) and SB-14 (handbook). SB-6 (hosted) stays parked. F-2 (a timeline of what was built) is in the backlog.

## Known limitations
- Laravel 13 / Livewire 4 are installed (starter kit), while `CLAUDE.md` says 12 / 3. Flagged to the
  owner and unchanged.
- Refresh needs a queue worker; `composer run dev` starts one, a bare `artisan serve` does not.
- Until the kit parser reads `Chosen option: **B**` (F-1), "Awaiting a pick" over-reports (9 on
  2026-09-29, 6 of them already picked) and the story page quotes such a choice without marking a frame.
- Untracked stories and mockups are listed but their text and mockups cannot be shown (not in git).
  Off-main rows whose IDs are not well formed (odd mockup folder names) cannot open the modal at all;
  owner decision pending. `?v=`
  links break at the next refresh. Uncommitted edits to tracked files are not shown.
- Layout classes still use the raw `zinc-*` palette; only status and project-state colours are theme tokens.
- Initiative rows on the project page are aggregates and open nothing; there is no drill-down from an
  initiative to its stories yet. The project page has no initiative or search filter.
- The initiative filter and search narrow only the What needs me cards and the sections; tiles and
  In flight always show the whole portfolio (ADR-018).
- A failing project is retried at most every 5 minutes (staleness keys on the last attempt), so a
  fixed remote can take that long to show as `ok` without a manual `board:refresh`.
- `/projects` has no auth and can add and delete projects; safe only while the board is localhost-only.
  SB-6 (hosted) would need auth first. A project's path and ref cannot be edited (remove and re-add).
- Dev server runs on port 8010 (coins holds 8000/8001). There is no git remote for this repo, by
  owner ruling.
