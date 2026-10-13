# ADR-081 — Git activity reads are fixed `log` and `status` forms, and `status` takes listing options only

Date: 2026-10-12 · Status: accepted · Story: RD-12 (feat `9b0addd`)

## Context

RD-12's project overview tiles need four git reads the board did not allow: commits per day, per-commit line
counts, files changed per commit, and the working copy's uncommitted-path count. `GitReader` is the board's only way
into a project's repository and must stay read-only (ADR-004, ADR-012, ADR-052). Two risks are specific to these
reads. Diff-style `git log` output runs the project's own `textconv` and external-diff programs by default, so
reading a foreign repo could execute that repo's config. `git status` was guarded only by "has `--porcelain`", which
still let `-v` through, and `-v` prints the staged diff, which is file contents.

## Decision

Each new read is a fixed argument form that `GitReader::run()` allows and nothing else
(`app/Services/GitReader.php`):

- **`commitsPerDay()` adds no git form.** It calls RD-7's `log()` (`LOG_FORMAT`, `--since=@<unix>`, ref, `--`) and
  buckets commits by committer day in the board's time zone, zero-filling the window.
- **`commitStats()`** is `log STAT_FORMAT --shortstat --no-ext-diff --no-textconv --max-count=<1–99> <ref> --`.
  `--shortstat` gives counts, never a patch. `--no-ext-diff --no-textconv` stop the project's own diff helpers from
  running. `STAT_MAX_COUNT` caps the count at 99, so no caller can stat a whole history.
- **`filesChangedSince()`** is `log -z FILES_FORMAT --name-only --no-renames --since=@<unix> <ref> --`. It returns
  names only.
- `isReadOnlyLog()` accepts exactly these three log forms.
- **`status` is tightened.** `isReadOnlyStatus()` requires `--porcelain` and allows only `STATUS_OPTIONS`
  (`--porcelain`, `-z`, `--untracked-files=all|normal`) before `--`. Paths are allowed after `--`.
  `uncommittedCount()` uses `status --porcelain -z --untracked-files=normal` with `core.fsmonitor=false`, and
  returns a count only.

Alternatives rejected:
- **Allow-list only the new `status` form and keep "has `--porcelain`" for the rest.** That guard would still pass
  `status --porcelain -v`, which prints diffs through a subcommand that is otherwise allowed. Tightening the shared
  guard closes the hole for every caller. The existing callers (`untrackedStoryFiles()`, `StoryPickWriter`) already
  use only the allowed options.
- **A new log form for commits per day.** `log()` already returns commit times. Reusing it keeps the number of git
  forms the board can run as small as possible.
- **`--numstat` or a patch, counted in PHP.** Either one moves file contents or per-file diffs into the board.
  `--shortstat` gives the totals the tile shows and nothing more.
- **`git diff --stat` per commit.** That costs one process per commit, and it needs a `diff` subcommand on the
  allow-list.

## Consequences

- Every other `log` or `status` shape is refused and logged as `board.git_refused`. That includes `-p`,
  free-text `--since`, `--max-count=100`, `status -v`, and the old `status` form plus any extra option.
- Line counts for a merge are null: `--shortstat` prints no stat line for merges.
- `--no-renames` counts a renamed file under its new path only.
- `commitsPerDay()` and `filesChangedSince()` use `--since`, which stops at the first commit older than the window.
  Out-of-order committer dates can undercount (RUNBOOK 2026-10-11, "A `git log --since` fixture silently dropped
  commits").
