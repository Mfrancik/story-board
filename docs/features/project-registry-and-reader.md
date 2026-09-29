# Project registry and story reader
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-2

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

**Refresh.** `app/Actions/Board/RefreshProject.php:handle()` runs per project:
1. `GitReader::isRepository()` fails → state `unreachable`, log `board.project_unreachable`, stop.
2. `GitReader::fetch()` fetches the remote named by the ref's first segment (`origin` for
   `origin/main`). Fails → state `stale`, last snapshot kept, log `board.fetch_failed`, stop. It
   does *not* index the local copy of the ref: that would stamp an old ref as fresh.
3. `GitReader::resolve()` gets the ref's commit SHA; `GitReader::storyIndex()` runs the kit's
   `bin/story-index <path> <ref>` (contract: `docs/KIT-REFERENCE.md` §story-index) and decodes its
   JSON. Either fails → state `stale`, log `board.index_failed`, stop.
4. `replaceSnapshot()` deletes the project's `stories` rows and bulk-inserts the new ones in
   500-row chunks, then sets `state=ok`, `sha`, `indexed_at`, clears `last_error` — all in one
   transaction, so a reader never sees half a snapshot.

A failure in one project never stops the others: `board:refresh`
(`app/Console/Commands/BoardRefresh.php`) loops enabled projects and prints one line each.

**Page-load refresh.** `app/Http/Controllers/BoardController.php` (route `/`, name `home`) lists
enabled projects with `stories_count`. For each project where `Project::needsRefresh()` is true
(`indexed_at` null or older than `Project::STALE_AFTER_MINUTES`), it calls
`RefreshProjectJob::dispatchAfterResponse()`. The page renders from the existing snapshot; the
refresh runs in the same PHP process after the response is sent, so no queue worker is needed. The
manual Refresh button is SB-3's to place.

**The git gateway.** `app/Services/GitReader.php` is the only app code that runs git. `run()`
refuses any subcommand not in `ALLOWED` (`fetch`, `ls-tree`, `show`, `rev-parse`, `cat-file`,
`remote`), refuses `remote` with arguments, and refuses any `--output`/`-o` argument. Every ref goes
through `assertRef()` (`REF_PATTERN`: no leading `-`, no `..`). git runs with
`GIT_TERMINAL_PROMPT=0`, ssh `BatchMode=yes`, `GIT_OPTIONAL_LOCKS=0`, and fetch adds
`-c gc.auto=0 -c maintenance.auto=false`, so the board never prompts, never takes an index lock and
never repacks a project's `.git`. `bin/story-index` is spawned only from `GitReader::storyIndex()`
with the same env.

**The page.** `resources/views/board/index.blade.php` is a plain Blade page (not Livewire): a bare
table of project, story count, state, ref @ SHA and indexed time. It exists only to prove the data;
SB-3 replaces it.

## Data model
- `projects` (`database/migrations/2026_09_29_000001_create_projects_table.php`, `app/Models/Project.php`):
  `name` (unique), `path`, `ref` (default `origin/main`), `is_enabled`, `state`
  (`pending|ok|stale|unreachable`, constants `Project::STATE_*`), `sha`, `indexed_at`, `last_error`.
  `state` and `last_error` go beyond the story's schema; they carry the stale/unreachable outcomes.
  The story's `enabled` is `is_enabled` per boolean naming.
- `stories` (`database/migrations/2026_09_29_000002_create_stories_table.php`, `app/Models/Story.php`):
  one row per story file at the ref. `story_id`, `title`, `status` are nullable, so a file that fails
  to parse is still stored with its `parse_errors`. `status` holds the raw first word, even outside
  the vocabulary. `depends_on`, `mockups`, `parse_errors` are JSON (cast to arrays). Unique on
  `(project_id, path)`; cascades on project delete. The rows are a snapshot, replaced wholesale on
  each successful refresh; they have no identity across refreshes.

## Interfaces
- `php artisan board:project add <path> [--name=] [--ref=origin/main]` — register; fails on bad
  ref, non-repo path, or a duplicate name/path.
- `php artisan board:project list` — table of every project and its last refresh.
- `php artisan board:project disable <name>` — sets `is_enabled=false`; the row and snapshot stay.
- `php artisan board:refresh [project]` — refresh all enabled projects, or one by name. Exits
  non-zero only when the named project does not exist or is disabled.
- `GET /` — the bare list.
- `GitReader` public methods: `isRepository()`, `fetch()`, `resolve()`, `show()`, `storyIndex()`,
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
| `board.refresh_crashed` | error | `RefreshProjectJob` | `project`, `exception` (rethrown) |
| `board.project_registered` | info | `RegisterProject` | `project`, `path`, `ref` |
| `board.project_disabled` | info | `BoardProject` command | `project` |

A healthy refresh is `refresh_started` then `refresh_finished` for the same `project`. A `started`
with no `finished` means one of the warnings fired (or a crash). There is no request ID yet (no
`AssignRequestId` middleware in the app), so trace by `project` and timestamp. The quickest check
without logs: `php artisan board:project list` shows each `state`, and `projects.last_error` holds
the first line of git's stderr.

## Testing & verification
- `tests/Feature/Board/RefreshProjectsTest.php` has one `it()` per acceptance criterion: 3 stories on
  `origin/main` with the right SHA; statuses from `origin/main`, not a lagging working tree; non-repo
  path `unreachable` while others refresh; failed fetch keeps the snapshot as `stale`; parse-error
  stories stored; `git status --porcelain` byte-identical. It also covers logging, single-project
  refresh, disabled projects and snapshot replacement.
- `tests/Feature/Board/ProjectRegistryTest.php` covers registration, list/disable, the seeder, the
  page and the page-load refresh.
- `tests/Unit/GitReaderTest.php` covers the allow-list, the argument guards and ref validation.
- `tests/Support/GitFixture.php` builds real fixture repos with a bare "origin" in the test. It runs
  git directly on purpose: it is test code building fixtures. The GitReader-only rule is for `app/`.
- Browser: `tests/Browser/BoardListTest.php` (Pest browser plugin, `Browser` testsuite in
  `phpunit.xml`). Manual check, 2026-09-29: coins shows 916 stories (see Gotchas for why the story's
  `git grep` oracle says 918).

## Key decisions & tradeoffs
- One git gateway with an allow-list plus argument guards, not care at call sites →
  [ADR-004](../decisions/ADR-004-gitreader-read-only-git-gateway.md).
- Page-load refresh after the response, stale-on-fetch-failure, wholesale snapshot replace →
  [ADR-005](../decisions/ADR-005-snapshot-refresh-model.md).
- Log events are `board.<event>`, exactly as the story named them, not
  `<feature>.<action>.<result>`.

## Known limitations & gotchas
- **918 vs 916 for coins.** The story's oracle `git grep -h '^Status:' origin/main -- 'stories/**/*.md'`
  also counts `stories/app-store-launch/README.md` and `stories/import/README.md`, which
  `bin/story-index` excludes by contract. 916 is correct.
- **rent-track rows all carry a parse error** because its `stories/README.md` has no §Status list.
  That is the project's problem, not the reader's.
- **A failing project retries on every page load.** `indexed_at` only moves on success, so a
  `stale` or `unreachable` project stays past `STALE_AFTER_MINUTES` and each `/` load dispatches
  another refresh (a fetch can take up to 60 s after the response). Nothing dedupes concurrent
  refreshes of one project either: two quick loads can run two snapshot replacements at once.
- `REF_PATTERN` ends in `$`, not `\z`, so a ref with a trailing newline passes validation.
- No test covers a bad `projects.ref` already stored in the DB reaching `RefreshProject`, or the
  `board.refresh_crashed` path.
- `storyIndex()` does not guard a `-`-leading path; paths come from `realpath()`, so they are
  absolute today.
- The seeder assumes projects live under `$HOME/Code/`.
- Laravel 13 / Livewire 4 are installed (starter kit) while `CLAUDE.md` says 12 / 3. Not changed;
  flagged to the owner.
- Starter-kit auth/settings components are in `docs/UI-INVENTORY.md`; the board does not use them.

## Change history
2026-09-29 — Registry, refresh, GitReader, bare `/` list; argument/ref guards added after preflight (SB-2)
