# ADR-004 — All git goes through one read-only gateway with an allow-list and argument guards

Date: 2026-09-29 · Status: accepted

## Context

The board reads stories out of the owner's real project repos. Owner ruling: the board never writes
to a project — no checkout, pull, commit, or file write. SB-2 needs `fetch`, `ls-tree`, `show` and a
few plumbing reads, and it spawns the kit's `bin/story-index`, which itself calls git.

The first preflight on SB-2 showed that an allow-list of subcommands was not enough. `git remote add`
and `git remote set-url` are the `remote` subcommand and rewrite `.git/config`. A stored ref such as
`--output=/path` reaching `git show` is read as an option and writes a file. Both could happen
without any call site doing anything that looked wrong.

## Decision

`app/Services/GitReader.php` is the only app code that runs git. `bin/story-index` is spawned
only through it too. `run()` enforces:

- a subcommand allow-list: `fetch`, `ls-tree`, `show`, `rev-parse`, `cat-file`, `remote`;
- `remote` only when it has no arguments (list remotes);
- no argument starting with `--output` or `-o`;
- every ref checked by `REF_PATTERN` (no leading `-`, no `..`, a narrow character set). This is
  stricter than `git check-ref-format` on purpose. `RegisterProject` rejects bad refs up front too.

Every invocation runs with `GIT_TERMINAL_PROMPT=0`, ssh `BatchMode=yes` + `ConnectTimeout=10`,
`GIT_OPTIONAL_LOCKS=0`. Fetch adds `-c gc.auto=0 -c maintenance.auto=false`. This way the board
never waits on a prompt, never takes an index lock a running editor or build would trip over, and
never repacks a project's `.git`.

Alternative rejected: keep git calls wherever they are needed and rely on review. Preflight run 1
showed that review misses the argument-level writes. A single choke point makes the rule testable
(`tests/Unit/GitReaderTest.php`).

## Consequences

- A new git need means changing the allow-list, which is a visible, reviewable diff.
- `fetch` is the one allowed write, and it touches only remote-tracking refs under `.git`.
- Test fixtures (`tests/Support/GitFixture.php`) run git directly. The rule covers `app/`, not test
  code building fixture repos.
- Known gaps: `REF_PATTERN` uses `$` rather than `\z`, and `storyIndex()` does not guard a `-`-leading
  path (paths are `realpath()` output today).
