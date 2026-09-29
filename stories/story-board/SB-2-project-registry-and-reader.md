# SB-2 — Register projects and read their stories from git
Status: approved         Journey: none
Source: owner 2026-09-29 (coins /story) — see SB-1 for the verbatim ask. Owner rulings: new repo +
kit contract; localhost first; read-only.

## Story
As the owner, I want the board to know which projects I have and to read each one's stories from
git, so that every later screen shows the real state of every project from one place.

## Why
This is the base the screens sit on. It also scaffolds the repo: `~/Code/story-board` exists, but it
has no app and no kit yet.

## In scope
- Scaffold `~/Code/story-board` on the fixed stack (Laravel, MySQL, Livewire, Tailwind, Pest, Pint)
  and install the dev-standards kit into it, the same way as any project.
- `projects` table: `name, path, ref (default origin/main), enabled`. Registered through an artisan
  command (`board:project add <path>`), plus a seeder for the four current kit projects: coins,
  client-dashboard, asset-track, rent-track.
- `board:refresh` runs `git fetch` for each project, then SB-1's `bin/story-index` at the project's
  ref, and stores the result in a `stories` table (one row per story per project) along with
  `indexed_at` and the ref's commit SHA.
- Refresh on page load when a project's snapshot is older than 5 minutes, plus a manual Refresh
  button (SB-3 places it).
- Read-only toward projects: only `git fetch`, `git ls-tree` and `git show`. Never checkout, pull,
  commit, or write a file in a project.

## Out of scope (do NOT build)
- Any screen other than a bare list proving the data (SB-3 is the home).
- Branches and untracked files (SB-5).
- GitHub API or hosting (SB-6).

## Acceptance criteria
- Given a registered fixture repo with 3 stories on `origin/main`, when `board:refresh` runs, then 3
  story rows exist with statuses and the stored SHA equals the fixture's `origin/main` SHA.
- Given a project whose working tree is behind origin/main, when refreshed, then the stored statuses
  match origin/main, not the working tree.
- Given a registered path that is no longer a git repo, when refreshed, then that project is marked
  `unreachable`, the other projects still refresh, and `board.project_unreachable` is logged.
- Given `git fetch` fails (offline), when refreshed, then the last snapshot is kept, marked stale, and
  `board.fetch_failed` is logged.
- Given a story with parse errors, when refreshed, then it is stored with its errors, not dropped.
- Given a refresh runs, then `git status --porcelain` in each project is byte-identical before and after.

## Applicable standards
- Design: n/a. The screens are SB-3/4.
- Codebase/DB: migrations for `projects` and `stories`; git is called through one `GitReader` class,
  the only code allowed to run git; MySQL only.
- Logging: `board.refresh_started`, `board.refresh_finished` (per project: count, sha, ms),
  `board.fetch_failed`, `board.project_unreachable`.

## Design mockup gate
n/a — non-visual

## Do NOT touch
- Any registered project's files.
- dev-standards. SB-1's parser is consumed as-is; if it needs changing, that's an SB-1 follow-up in
  the kit.

## Data & interfaces
- Migrations: `projects`, `stories` (`project_id, story_id, title, status, initiative, journey, path,
  source, depends_on json, mockups json, parse_errors json, sha`).
- Commands: `board:project add|list|disable`, `board:refresh [project]`.

## Test plan
- Pest, tests first. The fixture repos are built in the test (git init, commit, set up a bare
  "origin").
- Journey test: none.
- Browser check: a bare `/` list shows coins' story count equal to
  `git grep -h '^Status:' origin/main -- 'stories/**/*.md' | wc -l` in coins.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Logging events in place
- [ ] Status flipped to `built` in the build commit
- [ ] /preflight GO
- [ ] /document

## Links
Journey: none · Depends on: SB-1
