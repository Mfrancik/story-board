# ADR-012 — Off-main reads: four more read-only git forms, and untracked files read only at refresh

Date: 2026-09-29 · Status: accepted

## Context

[ADR-004](ADR-004-gitreader-read-only-git-gateway.md) limits the board to `fetch`, `ls-tree`, `show`,
`rev-parse`, `cat-file` and `remote`. [ADR-008](ADR-008-mockups-served-from-git-in-a-sandbox.md)
serves mockups only from git at the snapshot SHA, never from the working tree. SB-5 has to find
unmerged branches, the forks' merge-bases, worktrees and untracked files, and untracked files are by
definition not in git.

## Decision

- **Allow-list additions** in `app/Services/GitReader.php`, each in one read-only form:
  `for-each-ref --no-merged`, `merge-base`, `worktree` (only `worktree list`; `run()` refuses any
  other `worktree` action), and `status` (only with `--porcelain`, run with `core.fsmonitor=false`,
  `core.untrackedCache=false` and `GIT_OPTIONAL_LOCKS=0`, so it neither refreshes the index nor runs a
  checkout's configured fsmonitor program). The owner approved `for-each-ref` and `merge-base`
  beyond the story's original list.
- **Untracked files are read in one place only**: `IndexOffMain` at refresh, through `readInside()`,
  which refuses any path whose `realpath` resolves outside its checkout. At request time the board
  never reads untracked text. `RenderStory::read()` returns null for an untracked row, and
  `ReadMockupFile` serves `?v=` only for `branch` and `worktree` rows, reading at their commit
  through `git show`.

Alternatives rejected:
- **Serve untracked mockups from disk.** That would break ADR-008's rule of reading only from git and
  would add a working-tree path to a public route.
- **Read untracked story text on the page, as the ref text is read on expand.** The same objection
  applies, and it would also add file-system reads during requests.

## Consequences

- An untracked row shows where the file is but not its text or mockups. The owner opens it in that
  checkout.
- Branch and worktree mockups are viewable on the board through `?v=<row id>`.
- The read-only guarantee is tested: `NotOnMainTest` compares every checkout's status, HEAD and local
  branches before and after a scan.
