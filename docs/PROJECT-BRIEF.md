# Project brief — story-board
Last refreshed: 2026-09-29 (SB-2)

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
  ID, title, status, initiative, journey, path, source, depends_on, mockups and parse_errors.
- **Snapshot**: a project's full set of Story rows. It is replaced wholesale on each successful
  refresh and kept as-is when a refresh fails.
- **GitReader**: the only code that runs git. It allows read-only subcommands only.

## Features
| Feature | Status | Doc |
|---|---|---|
| Project registry and story reader: `board:project`, `board:refresh`, page-load refresh, bare `/` list | active (SB-2) | [doc](features/project-registry-and-reader.md) |

## Journeys
None yet. Every SB story so far is `Journey: none`.

## Stories
| ID | Title | Status |
|---|---|---|
| SB-2 | Register projects and read their stories from git | built |
| SB-3 | The home page shows what needs me | approved |
| SB-4 | Read a story and see its mockups side by side | approved |
| SB-5 | Show work that isn't on main yet | approved |
| SB-6 | The board on an always-live domain (parked) | draft |

SB-1 (the `bin/story-index` parser) lives in the dev-standards kit, not in this repo.

## Key decisions
- All git goes through `GitReader`: a subcommand allow-list plus argument and ref guards →
  [ADR-004](decisions/ADR-004-gitreader-read-only-git-gateway.md)
- Snapshots refresh after the response, go stale on fetch failure, and are replaced wholesale →
  [ADR-005](decisions/ADR-005-snapshot-refresh-model.md)
- Preflight audit scope and isolation, audit cost record, build-artifact disposal (kit) →
  ADR-001 to ADR-003

## Current phase and what's next
Localhost first. The data layer (SB-2) is built. Next is SB-3: the "What needs me" home, which
replaces the bare `/` list and adds the Refresh button. After it come SB-4 (story + mockup view) and
SB-5 (branches and unmerged work). SB-6 (hosted) is parked.

## Known limitations
- Laravel 13 / Livewire 4 are installed (starter kit), while `CLAUDE.md` says 12 / 3. Flagged to the
  owner and unchanged.
- There is no request-ID middleware yet, so logs trace by `project` and timestamp only.
- A failing project is retried at most every 5 minutes (staleness keys on the last attempt), so a
  fixed remote can take that long to show as `ok` without a manual `board:refresh`.
- Dev server runs on port 8010 (coins holds 8000/8001). There is no git remote for this repo, by
  owner ruling.
