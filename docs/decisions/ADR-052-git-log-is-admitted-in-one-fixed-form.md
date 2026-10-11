# ADR-052 — `git log` is admitted in one fixed form

Date: 2026-10-11 · Status: accepted · Story: RD-7

## Context

Shipped this week (RD-7) needs each project's recent commits, so `GitReader` (the board's only route to git,
ADR-004) must allow `log`. `log` is a wide command: it can print patches, read free-text dates, walk any ref or
path, write a file with `--output`, and, through a project's own `log.showSignature` config, run gpg. The board
runs git against checkouts it does not own, and the story requires `--output` and any other writing option to be
refused the way the existing guards refuse them.

## Decision

- `log` joins `GitReader::ALLOWED`, and `run()` accepts it **only** in the form `isReadOnlyLog()` describes,
  exactly five arguments: `log`, `GitReader::LOG_FORMAT` (`--format=%H%x1f%ct%x1f%s`: SHA, committer time,
  subject, unit-separated), `--since=@<unix>` (`LOG_SINCE`, an absolute time and never git's free-text dates), a
  ref that passes `isValidRef()`, and `--`. Anything else is refused through the existing `refuse()`, so it logs
  `board.git_refused` like every other refusal.
- `GitReader::log($path, $ref, $since)` is the one caller that builds that form. It also passes
  `-c log.showSignature=false`, so a project's config cannot make the board run gpg. It parses only lines shaped
  `<40–64 hex>␟<digits>␟<subject>`.
- The older `--output`/`-o` guard stays. `log --output=x` is refused by both guards; each has its own tests
  (`tests/Feature/Board/HomeTilesTest.php`: the AC test plus a dataset of refused forms), because the AC test
  alone stays green if only one guard is deleted.

Alternatives rejected:
- **An option allow-list for `log`** (allow `--format`, `--since`, `--until`, `-n`, …). It is looser, every new
  option would need its own review, and a free-text `--since` would hand git's date parser input from the caller.
- **Read history from the stored snapshot.** The snapshot holds story files, not commits, so it cannot say when a
  story was built.

## Consequences

- A future reader that needs a different `log` (another format, a path filter) has to change `isReadOnlyLog()`
  and add its own refusal tests; nothing can reach it by accident.
- `--since` filters on committer date and git stops walking at the first commit older than the cutoff, so
  out-of-order commit dates can hide older commits (RUNBOOK 2026-10-11).
