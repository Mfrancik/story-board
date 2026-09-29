# Not on main (off-main work)
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-5

## Overview
The ref (`origin/main` by default) is the truth for a story's status, but a lot of real work is not
there yet: picks made on unpushed branches, stories drafted on a branch, and mockup folders left
untracked in a checkout. SB-5 indexes that work at refresh time and shows it beside the ref, always
labelled with where it lives. On the home page it appears in a "Not on main" section, and on the
story page as a banner. It never counts in the home page's groups, because the ref is still the only
source of status.

## How it works
**When it runs.** `app/Actions/Board/RefreshProject.php:refresh()` calls
`app/Actions/Board/IndexOffMain.php:handle()` right after `replaceSnapshot()` has committed the ref
snapshot. It passes the ref SHA and the ref's story-index records. If the scan throws, the error is
logged as `board.offmain_failed` and the project stays `ok`. The ref snapshot is complete without the
scan, and a failed scan leaves the previous off-main rows in place, because the delete and insert
happen in the same transaction.

**Branches.** `branchRows()` walks the project's own checkout and then each registered alias:
1. `GitReader::unmergedBranches()` (`for-each-ref --no-merged=<ref>` over `refs/heads` and
   `refs/remotes`, skipping symrefs such as `origin/HEAD`). Local branches sort before
   remote-tracking ones (`isRemote()` checks the name against `git remote`), so `docs/X` wins over the
   `origin/docs/X` it was pushed as. A tip commit already seen is skipped.
2. `branchRecords()` compares three `GitReader::storyBlobs()` maps (`ls-tree -r -z` of `stories/` and
   `docs/mockups/`, path → blob SHA): the branch tip, its `GitReader::mergeBase()` with the ref, and
   the ref. A file counts only if **the branch changed it since it forked and it still differs from the
   ref**. Comparing against the ref alone reports every story that main has moved on since an old
   branch forked ([ADR-010](../decisions/ADR-010-off-main-is-what-a-branch-changed-since-its-merge-base.md)).
3. Changed story files are read with `GitReader::show()` at the tip and parsed by the kit's own parser
   (`app/Services/StoryParser.php`, below). A record is kept only if the ref has no such story (it is
   matched by path, then by ID, because stories move between folders and land through other branches)
   or if its `status`, `mockups.chosen` or `mockups.options` differ.
4. A changed `docs/mockups/<dir>/` whose story file the branch did not touch becomes a
   **mockup-only** record (`mockupOnlyRecord()`), unless its option list equals the ref's. That
   exception exists because editing an option file is not new work to pick from. Title and status come
   from the ref's story of that ID.
5. **Stacked branches** (MINT-10 on MINT-9 …) carry the same file versions. Each `path@blob` (for a
   mockup directory, a hash of its blobs) is kept once and attributed to the first branch that holds
   it.
6. One bad branch, such as an orphan with no merge-base, logs `board.offmain_branch_skipped` and the
   scan continues.

A branch that is checked out in a worktree is labelled `worktree <path>` (kind `worktree`).
Otherwise it is labelled `branch <name>` (kind `branch`).

**Untracked files.** `untrackedRows()` visits every worktree from `GitReader::worktrees()` (the
primary checkout included) and every alias. Worktree directories that were deleted without
`git worktree prune` are skipped. For each one it asks `GitReader::untrackedStoryFiles()`
(`status --porcelain -z --untracked-files=all -- stories/ docs/mockups/`, run with
`core.fsmonitor=false`, `core.untrackedCache=false` and `GIT_OPTIONAL_LOCKS=0`). Story files are read
with `readInside()`, which refuses any path whose `realpath` is not inside the checkout (a symlink
pointing out) and logs `board.offmain_file_skipped`. Any mockup directory name is accepted, not only
a well-formed ID. Rows are labelled `untracked in <path>`, with `sha` set to the ref SHA and
`branch` set to that worktree's branch.

**One parser.** `StoryParser::parse()` pipes `{readme, files, mockup_files}` as JSON to
`scripts/parse-story-files.py`. That script loads the kit's `bin/story-index` as a module and calls
its `parse_vocabulary()` and `index_story()`, so text that is not at a ref is parsed by exactly the
same rules as the ref snapshot
([ADR-011](../decisions/ADR-011-off-main-text-parsed-by-the-kits-own-parser.md)).

**Other checkouts.** Worktrees need no registration: `IndexOffMain` rewrites the project's
`project_locations` rows of kind `worktree` on every scan. A true sibling clone (a separate
`git clone`, not a worktree) is registered by hand with `board:project alias <path> --name=<project>`
(`app/Actions/Board/RegisterAlias.php`). On 2026-09-29 all 17 of coins' `coins-*` siblings turned out
to be worktrees, so coins has no aliases.

**Home page.** `ListWhatNeedsMe::scoped()` takes `offMain: true` for the `offmain` count and section.
Every other group keeps `location_kind IS NULL`. `Home::SECTIONS` puts `offmain` first. It reuses
SB-3's collapsible `board/section`, closed by default, with its count in the header. It loads rows
only when opened, ordered by project, location kind, branch and path. Each `board/story-row` for an
off-main row shows its `location`, then either a status chip or a "mockups only" tag, and "picked X"
when it has a choice.

**Story page.** See [Story page and mockups](story-page-and-mockups.md#versions-sb-5). `?v=<row id>`
shows one off-main version. Every page lists the story's other versions in a banner, for example
"Picked D on branch docs/MOB-56-pick — not on main". A story that exists only off main opens its
first off-main version (branch, then worktree, then untracked) instead of returning 404.

**What is never read at request time.** Untracked files are read only by `IndexOffMain` during a
refresh. `RenderStory::read()` returns null for an untracked row, `Story::isInGit()` is false for it,
and `ReadMockupFile` serves `?v=` only for `branch` and `worktree` rows, reading at that row's commit
([ADR-012](../decisions/ADR-012-off-main-reads-stay-read-only-and-in-git.md)).

## Data model
Migration `database/migrations/2026_09_29_000005_create_project_locations_and_off_main_stories.php`:
- `project_locations` (`app/Models/ProjectLocation.php`): `project_id` (cascade), `kind`
  (`alias` | `worktree`, `ProjectLocation::KIND_*`), `path`, `branch` (the branch checked out, for
  worktrees). Aliases persist. Worktree rows are replaced on each scan.
- `stories.location_kind` (null = on the ref; `branch` | `worktree` | `untracked`, `Story::KIND_*`),
  `stories.location` (the human label), `stories.branch`. Scopes are `Story::onRef()` and
  `Story::offMain()`.
- The `(project_id, path)` unique key from SB-2 is dropped for a plain index, because one path now
  appears once on the ref and again per location. `down()` deletes the off-main rows first so that the
  unique key can come back.
- Off-main rows are a snapshot too. `IndexOffMain` deletes and reinserts them all on each scan, so a
  row ID (`?v=`) is only valid until the next refresh.

## Interfaces
- `php artisan board:project alias <path> --name=<project>` registers a sibling clone. It fails on an
  unknown project, a path that is not a repo, or a duplicate.
- `IndexOffMain::handle(Project, string $sha, array $refRecords): array{branch, worktree, untracked}`.
  It throws `GitReaderException`, which the caller catches.
- `StoryParser::parse(?string $readme, list<{path,text}> $files, array $mockupFiles): list<record>`.
- New `GitReader` methods: `storyBlobs()`, `mergeBase()`, `unmergedBranches()`, `worktrees()`,
  `untrackedStoryFiles()`. The allow-list gains `for-each-ref`, `merge-base`, `worktree` (only
  `worktree list`) and `status` (only with `--porcelain`); `run()` enforces both restrictions.
- `Story` helpers: `isInGit()`, `placePhrase()`, `mockupUrl()` (adds `v` for off-main rows),
  `isMockupOnly()` and `hasPage()`.
- `ListWhatNeedsMe::handle()` gains `offmain` (a count), and `section('offmain', …)`.
- Test hooks: `data-section="offmain"`, `data-offmain-row`, `data-offmain-shown`,
  `data-offmain-banner`.

## Configuration
Nothing new in `.env`. The scan needs `python3` on the PATH to run `scripts/parse-story-files.py`
(the kit's `bin/story-index` already needs it). `StoryParser` times out after 60 s. `untrackedStoryFiles()` uses
a 60 s git timeout, and every other git call uses the 30 s default.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.offmain_indexed` | info | `IndexOffMain::handle()` | `project`, `branch`, `worktree`, `untracked` (row counts) |
| `board.offmain_failed` | warning | `RefreshProject::refresh()` | `project`, `error` |
| `board.offmain_branch_skipped` | warning | `IndexOffMain::branchRows()` | `project`, `branch`, `error` |
| `board.offmain_file_skipped` | warning | `IndexOffMain::readInside()` | `checkout`, `file`, `reason` |
| `board.alias_registered` | info | `RegisterAlias` | `project`, `path` |
| `board.version_rejected` | warning | `StoryPage::mount()`, `MockupFileController` | `project`, `story`, `v` |
| `board.story_viewed` | info | `StoryPage::mount()` | now also `version` (the row's `location`, null on the ref) |

In a healthy refresh, `board.refresh_started` is followed by `board.offmain_indexed` and then
`board.refresh_finished`, all with the same `request_id`. If `offmain_failed` appears instead of
`offmain_indexed`, the Not on main section shows the previous scan. A `version_rejected` means a `v`
that the board never builds.

## Testing & verification
- `tests/Feature/Board/NotOnMainTest.php` drives `IndexOffMain` against real fixture repos
  (`tests/Support/GitFixture.php`) with a branch, a worktree and an untracked file. It has one `it()`
  per acceptance criterion and a regression test for each bug below: merge-base scoping, an orphan
  branch, a story moved on the ref, stacked branches, a failed scan keeping the snapshot, and alias
  scanning. The acceptance criterion "git status unchanged" is checked by comparing
  status, HEAD and the local branches of every checkout before and after the scan.
- `tests/Feature/Board/NotOnMainPageTest.php` covers the UI: the MOB-56-style banner, the section
  rows, branch-served mockups, `?v=` refusals (untracked, another story's or project's row,
  non-numeric), branch/commit labelling, mockup-only labelling, and untracked text not being read.
- `tests/Unit/GitReaderTest.php` covers the `worktree`/`status` restrictions.
- Real data, 2026-09-29: coins Not on main = 263. There were 56 untracked mockup-only rows (the story
  needed a non-zero count). The MOB-56 banner is absent because `docs/MOB-56-pick` has since been
  merged, and the story's check applies only "if that branch is still unmerged". MINT-1 exists only on
  branches and opens its branch version. At 375 px in dark mode there was no horizontal scroll.

## Key decisions & tradeoffs
- A branch counts only for what it changed since its merge-base and that still differs from the ref.
  Stacked branches are deduplicated by file version, and one bad branch is skipped →
  [ADR-010](../decisions/ADR-010-off-main-is-what-a-branch-changed-since-its-merge-base.md).
- Off-main text is parsed by the kit's `index_story()` through a Python shim, not a PHP copy →
  [ADR-011](../decisions/ADR-011-off-main-text-parsed-by-the-kits-own-parser.md).
- Four more git subcommands are allowed in read-only forms, and untracked files are read only at
  refresh and never served →
  [ADR-012](../decisions/ADR-012-off-main-reads-stay-read-only-and-in-git.md).
- "Not on main" reuses SB-3's collapsible section and is closed by default, so no new layout and no
  design gate were needed (the story allows this).
- Stale branches are shown as they are. For example, coins ACQ-20 is built on main but reads
  "Draft on branch design/ACQ-20-mockups — not on main", because that branch did change it. There is
  no ranking by commit date.

## Known limitations & gotchas
- **Uncommitted edits to tracked files are invisible** (out of scope, too noisy). Only branch commits
  and untracked files count.
- **Untracked text and mockups cannot be opened on the board.** The row and banner say where they
  are, so open them in that checkout.
- **`?v=` links die at the next refresh**, because off-main rows are reinserted with new IDs. The
  resulting 404 is expected.
- **Other sessions change checkouts during a scan.** During a real coins refresh, two coins worktrees
  changed: a file edit, and a `test(PRF-7b)` commit at 01:23:38. Timestamps showed these came from
  other active sessions. The board ran only read-only git, and the fixture test proves the scan
  changes nothing.
- A remote-only branch is visible only after `git fetch`. The board fetches only the ref's remote,
  and only for the project's own path, not for aliases.
- The scan's cost grows with the number of branches (two `ls-tree`s and a `merge-base` for each
  distinct tip). On coins this is the slowest part of a refresh, which is one reason the refresh runs
  on the queue (see RUNBOOK "Page hangs").

## Change history
2026-09-29 — Off-main indexing: branches, worktrees, untracked files, `project_locations`, `board:project alias` (SB-5, `ab69da3`, `7f4d864`)
2026-09-29 — Not on main section on the home page, versions banner and `?v=` on the story page, branch mockups served (SB-5, `7165b18`)
2026-09-29 — `board.version_rejected` logged and tested; view checks moved to `Story` helpers (SB-5, `f83e02e`)
