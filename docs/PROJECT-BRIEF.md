# Project brief — story-board
Last refreshed: 2026-09-29 (SB-5)

## What it is, and for whom
A localhost Laravel app for one owner. It reads the `stories/` folder of every project that uses
the dev-standards kit and shows, in one place, what state each project's stories are in. It reads
from git only, at each project's ref (default `origin/main`), and never writes to a project. That is
an owner ruling. Work that is not on the ref yet (unmerged branches, worktrees, untracked files) is
shown beside it, labelled with where it lives, and never counts as status.

## Domain model
- **Project**: a registered local checkout (`name`, `path`, `ref`, `is_enabled`). It has a refresh
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
| What needs me: `/` lists drafts to approve, mockups to pick and work to build; rows expand in place; project cards; URL filters | active (SB-3, SB-4, SB-5) | [doc](features/what-needs-me-home.md) |
| Story page and mockups: `/p/{project}/s/{id}`, story above sandboxed mockup frames, 375/768/1280, full-screen compare; raw mockup route; `?v=` versions banner | active (SB-4, SB-5) | [doc](features/story-page-and-mockups.md) |
| Not on main: branches, worktrees and untracked stories/mockups, indexed at refresh; home section and story-page banner; `board:project alias` | active (SB-5) | [doc](features/not-on-main.md) |

## Journeys
None yet. Every SB story so far is `Journey: none`.

## Stories
| ID | Title | Status |
|---|---|---|
| SB-2 | Register projects and read their stories from git | built |
| SB-3 | The home page shows what needs me | built |
| SB-4 | Read a story and see its mockups side by side | built |
| SB-5 | Show work that isn't on main yet | built |
| SB-6 | The board on an always-live domain (parked) | draft |

SB-1 (the `bin/story-index` parser) lives in the dev-standards kit, not in this repo.

## Key decisions
- All git goes through `GitReader`: a subcommand allow-list plus argument and ref guards →
  [ADR-004](decisions/ADR-004-gitreader-read-only-git-gateway.md)
- Snapshots go stale on fetch failure and are replaced wholesale →
  [ADR-005](decisions/ADR-005-snapshot-refresh-model.md)
- Refresh runs on the queue, unique per project, carrying the request ID (replaces ADR-005's
  after-response dispatch) → [ADR-006](decisions/ADR-006-refresh-runs-on-the-queue.md)
- Story text is read from git when a row is expanded, not stored →
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
- The board is read-only: no approve, pick or cancel actions (owner ruling). Compare is Alpine-only.
- Preflight audit scope and isolation, audit cost record, build-artifact disposal (kit) →
  ADR-001 to ADR-003

## Current phase and what's next
Localhost first. The data layer (SB-2), the "What needs me" home (SB-3), the story page with mockups
(SB-4) and Not on main (SB-5) are built. SB-5 was the last approved story, so the phase is at its
end: `/lesson retro` and a `full` preflight sweep are due. SB-6 (hosted) stays parked. F-2
(timeline of what was built) is in the backlog.

## Known limitations
- Laravel 13 / Livewire 4 are installed (starter kit), while `CLAUDE.md` says 12 / 3. Flagged to the
  owner and unchanged.
- Refresh needs a queue worker; `composer run dev` starts one, a bare `artisan serve` does not.
- Until the kit parser reads `Chosen option: **B**` (F-1), "Awaiting a mockup pick" over-reports and
  the story page quotes such a choice without marking a frame.
- Untracked stories and mockups are listed but cannot be opened on the board (not in git). `?v=`
  links break at the next refresh. Uncommitted edits to tracked files are not shown.
- Layout classes still use the raw `zinc-*` palette; only status colours are theme tokens.
- A failing project is retried at most every 5 minutes (staleness keys on the last attempt), so a
  fixed remote can take that long to show as `ok` without a manual `board:refresh`.
- Dev server runs on port 8010 (coins holds 8000/8001). There is no git remote for this repo, by
  owner ruling.
