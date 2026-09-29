# Project brief — story-board
Last refreshed: 2026-09-29 (SB-4)

## What it is, and for whom
A localhost Laravel app for one owner. It reads the `stories/` folder of every project that uses
the dev-standards kit and shows, in one place, what state each project's stories are in. It reads
from git only, at each project's ref (default `origin/main`), and never writes to a project. That is
an owner ruling.

## Domain model
- **Project**: a registered local checkout (`name`, `path`, `ref`, `is_enabled`). It has a refresh
  `state` (`pending | ok | stale | unreachable`), the `sha`/`indexed_at` of its last good snapshot,
  and `refresh_attempted_at` (last try, any outcome), which drives page-load staleness.
- **Story**: one story file at a project's ref, as reported by the kit's `bin/story-index`. It holds
  ID, title, status, initiative, journey, path, source, depends_on, mockups and parse_errors, plus
  two facts computed at refresh: `is_parked` (initiative README says `Status: draft group`) and
  `dated_on` (first date in `Source:`). Story text and mockup files are not stored; they are read
  from git at the row's SHA on demand.
- **Snapshot**: a project's full set of Story rows. It is replaced wholesale on each successful
  refresh and kept as-is when a refresh fails.
- **GitReader**: the only code that runs git. It allows read-only subcommands only.

## Features
| Feature | Status | Doc |
|---|---|---|
| Project registry and story reader: `board:project`, `board:refresh`, queued page-load refresh, `GitReader` | active (SB-2, SB-3) | [doc](features/project-registry-and-reader.md) |
| What needs me: `/` lists drafts to approve, mockups to pick and work to build; rows expand in place; project cards; URL filters | active (SB-3, SB-4) | [doc](features/what-needs-me-home.md) |
| Story page and mockups: `/p/{project}/s/{id}`, story above sandboxed mockup frames, 375/768/1280, full-screen compare; raw mockup route | active (SB-4) | [doc](features/story-page-and-mockups.md) |

## Journeys
None yet. Every SB story so far is `Journey: none`.

## Stories
| ID | Title | Status |
|---|---|---|
| SB-2 | Register projects and read their stories from git | built |
| SB-3 | The home page shows what needs me | built |
| SB-4 | Read a story and see its mockups side by side | built |
| SB-5 | Show work that isn't on main yet | approved |
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
- The board is read-only: no approve, pick or cancel actions (owner ruling). Compare is Alpine-only.
- Preflight audit scope and isolation, audit cost record, build-artifact disposal (kit) →
  ADR-001 to ADR-003

## Current phase and what's next
Localhost first. The data layer (SB-2), the "What needs me" home (SB-3) and the story page with
mockups (SB-4) are built. Next is SB-5 (branches and unmerged work in the UI, including mockups not
yet on the ref). SB-6 (hosted) is parked. F-2 (timeline of what was built) is in the backlog.

## Known limitations
- Laravel 13 / Livewire 4 are installed (starter kit), while `CLAUDE.md` says 12 / 3. Flagged to the
  owner and unchanged.
- Refresh needs a queue worker; `composer run dev` starts one, a bare `artisan serve` does not.
- Until the kit parser reads `Chosen option: **B**` (F-1), "Awaiting a mockup pick" over-reports and
  the story page quotes such a choice without marking a frame.
- Layout classes still use the raw `zinc-*` palette; only status colours are theme tokens.
- A failing project is retried at most every 5 minutes (staleness keys on the last attempt), so a
  fixed remote can take that long to show as `ok` without a manual `board:refresh`.
- Dev server runs on port 8010 (coins holds 8000/8001). There is no git remote for this repo, by
  owner ruling.
