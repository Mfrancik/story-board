# Project registry and story reader
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-2, SB-3, SB-5

## Overview
The board keeps a list of local project checkouts and a snapshot of every story file at each
project's ref (default `origin/main`), read through git and never through the working tree. Every
later screen (SB-3 onwards) reads from this snapshot, so the board shows what is merged, not what
happens to be checked out. The board never writes to a project; that is an owner ruling, and it is
enforced in code (see [ADR-004](../decisions/ADR-004-gitreader-read-only-git-gateway.md)).

## How it works
**Registration.** `board:project add <path>` → `app/Actions/Board/RegisterProject.php:handle()`
validates the ref, `realpath`s the path, confirms it is a repo via `GitReader::isRepository()`, and
inserts a `projects` row in state `pending`. Registration reads no stories; the first refresh does.
`database/seeders/ProjectSeeder.php` registers coins, client-dashboard, asset-track and rent-track
under `$HOME/Code/` (idempotent `firstOrCreate`).

**Refresh.** `app/Actions/Board/RefreshProject.php:handle()` runs per project. It first takes
`Cache::lock("board:refresh:{id}", RefreshProject::LOCK_SECONDS)`; if another refresh of the same project holds it, this one
logs `board.refresh_skipped` and returns, so two quick page loads never swap one snapshot twice. The
lock is released in a `finally`. Under the lock, `refresh()` stamps `refresh_attempted_at = now()`
before touching git, then:
1. `GitReader::isRepository()` fails → state `unreachable`, log `board.project_unreachable`, stop.
2. `GitReader::fetch()` fetches the remote named by the ref's first segment (`origin` for
   `origin/main`). Fails → state `stale`, last snapshot kept, log `board.fetch_failed`, stop. It
   does *not* index the local copy of the ref: that would stamp an old ref as fresh.
3. `GitReader::resolve()` gets the ref's commit SHA; `GitReader::storyIndex()` runs the kit's
   `bin/story-index <path> <ref>` (contract: `docs/KIT-REFERENCE.md` §story-index) and decodes its
   JSON. Either fails → state `stale`, log `board.index_failed`, stop.
4. `replaceSnapshot()` deletes the project's on-ref `stories` rows (`Story::onRef()`) and bulk-inserts the new ones in
   500-row chunks, then sets `state=ok`, `sha`, `indexed_at`, clears `last_error` — all in one
   transaction, so a reader never sees half a snapshot.

5. `IndexOffMain` then scans branches, worktrees and untracked files
   ([Not on main](not-on-main.md)). Its failure logs `board.offmain_failed` and never marks the
   project stale.

A failure in one project never stops the others: `board:refresh`
(`app/Console/Commands/BoardRefresh.php`) loops enabled projects and prints one line each.

**Page-load refresh.** The home page (`app/Livewire/Board/Home.php:mount()`, see
[What needs me](what-needs-me-home.md)) queues `RefreshProjectJob` for each enabled project where
`Project::needsRefresh()` is true (`refresh_attempted_at ?? indexed_at` null or older than
`Project::STALE_AFTER_MINUTES`). The page renders from the existing snapshot; a queue worker runs the
refresh. The job is `ShouldBeUnique` per project, so repeated loads queue one refresh
([ADR-006](../decisions/ADR-006-refresh-runs-on-the-queue.md), superseding ADR-005's after-response
dispatch). Staleness keys on the last *attempt*, not on `indexed_at` (which only moves on success), so a
`stale` or `unreachable` project is retried every 5 minutes rather than on every page load.

**The git gateway.** `app/Services/GitReader.php` is the only app code that runs git. `run()`
refuses any subcommand not in `ALLOWED` (`fetch`, `ls-tree`, `show`, `rev-parse`, `cat-file`,
`remote`, and since SB-5 `for-each-ref`, `merge-base`, `worktree`, `status`), refuses `remote` with
arguments, allows `worktree` only as `worktree list` and `status` only with `--porcelain`
([ADR-012](../decisions/ADR-012-off-main-reads-stay-read-only-and-in-git.md)), and refuses any `--output`/`-o` argument. Every ref goes
through `assertRef()` (`REF_PATTERN`: no leading `-`, no `..`; `D` flag so `$` cannot match
before a trailing newline). git runs with
`GIT_TERMINAL_PROMPT=0`, ssh `BatchMode=yes`, `GIT_OPTIONAL_LOCKS=0`, and fetch adds
`-c gc.auto=0 -c maintenance.auto=false`, so the board never prompts, never takes an index lock and
never repacks a project's `.git`. `bin/story-index` is spawned only from `GitReader::storyIndex()`
with the same env.

**The page.** SB-2 shipped a bare Blade list at `/`; SB-3 replaced it with the Livewire home
([What needs me](what-needs-me-home.md)), which also adds `is_parked` and `dated_on` to each snapshot.

## Data model
- `projects` (`database/migrations/2026_09_29_000001_create_projects_table.php`, `app/Models/Project.php`):
  `name` (unique), `path`, `ref` (default `origin/main`), `is_enabled`, `state`
  (`pending|ok|stale|unreachable`, constants `Project::STATE_*`), `sha`, `indexed_at`,
  `refresh_attempted_at`, `last_error`. `indexed_at` is the last *successful* snapshot;
  `refresh_attempted_at` (`database/migrations/2026_09_29_000003_add_refresh_attempted_at_to_projects_table.php`)
  is the last try of any outcome and is what `needsRefresh()` reads.
  `state` and `last_error` go beyond the story's schema; they carry the stale/unreachable outcomes.
  The story's `enabled` is `is_enabled` per boolean naming.
- `stories` (`database/migrations/2026_09_29_000002_create_stories_table.php`, `app/Models/Story.php`):
  one row per story file at the ref. `story_id`, `title`, `status` are nullable, so a file that fails
  to parse is still stored with its `parse_errors`. `status` holds the raw first word, even outside
  the vocabulary. `depends_on`, `mockups`, `parse_errors` are JSON (cast to arrays). Indexed on
  `(project_id, path)` (SB-2's unique key was dropped by SB-5, which stores off-main versions of the
  same path with `location_kind` set); cascades on project delete. The rows are a snapshot, replaced wholesale on
  each successful refresh; they have no identity across refreshes.

## Interfaces
- `php artisan board:project add <path> [--name=] [--ref=origin/main]` — register; fails on bad
  ref, non-repo path, or a duplicate name/path.
- `php artisan board:project alias <path> --name=<project>` registers a sibling clone as another
  checkout of the project (SB-5, [Not on main](not-on-main.md)).
- `php artisan board:project list` — table of every project and its last refresh.
- `php artisan board:project disable <name>` — sets `is_enabled=false`; the row and snapshot stay.
  `enable <name>` undoes it and queues one refresh. Both go through `SwitchProject` (SB-12,
  [Manage projects](manage-projects.md)), which also switches, adds and removes projects from `/projects`.
- `php artisan board:refresh [project]` — refresh all enabled projects, or one by name. Exits
  non-zero only when the named project does not exist or is disabled.
- `GET /` — the home page ([What needs me](what-needs-me-home.md)).
- `GitReader` public methods: `isRepository()`, `fetch()`, `resolve()`, `show()`, `listFiles()`, `storyIndex()`,
  `storyBlobs()`, `mergeBase()`, `unmergedBranches()`, `worktrees()`, `untrackedStoryFiles()` (SB-5),
  `run()`, static `isValidRef()`. All failures throw `App\Exceptions\GitReaderException`.

## Configuration
- No feature-specific env vars. `Project::STALE_AFTER_MINUTES` (5) is a constant.
- Dev server listens on 8010 (`SERVER_PORT` in `.env`) because coins uses 8000/8001.
- Git timeouts: 30 s per command, 60 s for fetch and for `bin/story-index`.

## Observability
All events go to the default log channel with a `project` context key:

| Event | Level | Where | Context |
|---|---|---|---|
| `board.refresh_started` | info | `RefreshProject` | `project`, `ref` |
| `board.refresh_finished` | info | `RefreshProject` | `project`, `count`, `sha`, `ms` |
| `board.fetch_failed` | warning | `RefreshProject` | `project`, `error` |
| `board.index_failed` | warning | `RefreshProject` | `project`, `ref`, `error` |
| `board.project_unreachable` | warning | `RefreshProject` | `project`, `path` |
| `board.refresh_skipped` | info | `RefreshProject` | `project`, `reason` (`already running`) |
| `board.refresh_crashed` | error | `RefreshProjectJob` | `project`, `exception` (rethrown) |
| `board.project_registered` | info | `RegisterProject` | `project`, `path`, `ref` |
| `board.project_disabled` / `board.project_enabled` | info | `SwitchProject` | `project`, `source` (`ui` / `cli`) (SB-12) |

A healthy refresh is `refresh_started` then `refresh_finished` for the same `project`. A `started`
with no `finished` means one of the warnings fired (or a crash). A `refresh_skipped` is benign: a
refresh of that project was already in flight. If `refresh_skipped` repeats for minutes with no
`started`, a crashed process may be holding the lock; it expires after `RefreshProject::LOCK_SECONDS`. Every line carries `request_id` (SB-3's `AssignRequestId`
middleware, restored inside the queued job), so a refresh traces back to the page load that queued it. The quickest check
without logs: `php artisan board:project list` shows each `state`, and `projects.last_error` holds
the first line of git's stderr.

## Testing & verification
- `tests/Feature/Board/RefreshProjectsTest.php` has one `it()` per acceptance criterion: 3 stories on
  `origin/main` with the right SHA; statuses from `origin/main`, not a lagging working tree; non-repo
  path `unreachable` while others refresh; failed fetch keeps the snapshot as `stale`; parse-error
  stories stored; `git status --porcelain` byte-identical. It also covers logging, single-project
  refresh, disabled projects, snapshot replacement, the lock stand-down (`board.refresh_skipped`), a
  bad `projects.ref` already stored in the DB (snapshot kept, state `stale`, git writes no file) and
  the `board.refresh_crashed` log-and-rethrow.
- `tests/Feature/Board/ProjectRegistryTest.php` covers registration, list/disable, the seeder, the
  page and the page-load refresh, including that a failing project attempted under 5 minutes ago is
  not re-dispatched.
- `tests/Unit/GitReaderTest.php` covers the allow-list, the argument guards and ref validation.
- `tests/Support/GitFixture.php` builds real fixture repos with a bare "origin" in the test. It runs
  git directly on purpose: it is test code building fixtures. The GitReader-only rule is for `app/`.
- Browser: `tests/Browser/BoardListTest.php` (Pest browser plugin, `Browser` testsuite in
  `phpunit.xml`). Manual check, 2026-09-29: coins shows 916 stories (see Gotchas for why the story's
  `git grep` oracle says 918).

## Key decisions & tradeoffs
- One git gateway with an allow-list plus argument guards, not care at call sites →
  [ADR-004](../decisions/ADR-004-gitreader-read-only-git-gateway.md).
- Stale-on-fetch-failure, wholesale snapshot replace →
  [ADR-005](../decisions/ADR-005-snapshot-refresh-model.md); the refresh is queued, unique per
  project → [ADR-006](../decisions/ADR-006-refresh-runs-on-the-queue.md).
- Staleness keys on the last attempt (`refresh_attempted_at`), not the last success, and a
  per-project cache lock dedupes overlapping refreshes → ADR-005, Amendment.
- Log events are `board.<event>`, exactly as the story named them, not
  `<feature>.<action>.<result>`.

## Known limitations & gotchas
- **918 vs 916 for coins.** The story's oracle `git grep -h '^Status:' origin/main -- 'stories/**/*.md'`
  also counts `stories/app-store-launch/README.md` and `stories/import/README.md`, which
  `bin/story-index` excludes by contract. 916 is correct.
- **rent-track rows all carry a parse error** because its `stories/README.md` has no §Status list.
  That is the project's problem, not the reader's.
- **A failing project retries every 5 minutes**, not sooner. A fixed remote can take that long to
  show `ok` on `/`; run `board:refresh <name>` to force it.
- **The lock needs a shared cache store.** `Cache::lock` uses the default store, `database`
  (`CACHE_STORE`), which every PHP process shares; switching to `array` silently disables the
  dedupe. `RefreshProject::LOCK_SECONDS` is deliberately longer than the sum of every git timeout in
  one refresh, so the lock cannot expire under a refresh that is still running. Raise it if a
  `GitReader` timeout grows.
- No test proves the lock is released after an exception inside `refresh()`; it is correct by the
  `try/finally` in `handle()`.
- Refresh needs a running queue worker (`composer run dev` starts one); under a bare
  `artisan serve`, queued refreshes never run.
- `storyIndex()` does not guard a `-`-leading path; paths come from `realpath()`, so they are
  absolute today.
- The seeder assumes projects live under `$HOME/Code/`.
- Laravel 13 / Livewire 4 are installed (starter kit) while `CLAUDE.md` says 12 / 3. Not changed;
  flagged to the owner.
- Starter-kit auth/settings components are in `docs/UI-INVENTORY.md`; the board does not use them.

## Change history
2026-09-29 — Registry, refresh, GitReader, bare `/` list; argument/ref guards added after preflight (SB-2)
2026-09-29 — `refresh_attempted_at` staleness, per-project refresh lock (`board.refresh_skipped`),
`REF_PATTERN` `D` flag, tests for stored bad ref and `refresh_crashed`, `.env.example` on MySQL (SB-2)
2026-09-29 — Refresh lock TTL (`RefreshProject::LOCK_SECONDS`) raised above the git timeout sum (SB-2)
2026-09-29 — Page-load refresh moved to the Livewire home and the queue; bare list removed; request IDs on refresh logs (SB-3)
2026-09-29 — GitReader allow-list gains four read-only forms; `(project_id, path)` no longer unique; `board:project alias`; off-main scan after each refresh (SB-5)
2026-09-29 — `board:project enable`; enable/disable go through `SwitchProject` and log `source` (SB-12)
