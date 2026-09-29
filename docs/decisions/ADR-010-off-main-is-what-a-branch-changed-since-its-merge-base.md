# ADR-010 — Off-main work is what a branch changed since its merge-base, not how it differs from main today

Date: 2026-09-29 · Status: accepted

## Context

SB-5 lists stories and mockups on unmerged branches, labelled "not on main". The story says that a
branch counts only for stories "whose content differs from the ref". The first version implemented
exactly that: it compared each branch tip with the ref. On coins it reported 903 rows. An old branch
forked before main built hundreds of stories, so every one of those stories "differed" on the branch,
where it was still `draft` or missing. None of those rows were work the owner had done on the branch.

Two other shapes showed up on real data. Stacked branches (MINT-10 on MINT-9 on …) each carry the
same story files. And client-dashboard has a branch with no merge-base with main
(`standards/feat/feature-skill`), which failed the whole project's scan.

## Decision

`app/Actions/Board/IndexOffMain.php:branchRecords()` compares three blob maps
(`GitReader::storyBlobs()`): the branch tip, `merge-base(tip, ref)`, and the ref. A file counts only if
**the branch changed it since the merge-base and it still differs from the ref today**. A changed
story is then kept only if the board would show something different: a story the ref does not have
(matched by path, then by ID), or a different `status`, `mockups.chosen` or `mockups.options`.

- Stacked branches: one row per file version (`path@blob`), attributed to the first branch that holds
  it. Local branches come before remote-tracking ones, and a tip commit is scanned once.
- A branch that git cannot compare (for example, no merge-base) logs `board.offmain_branch_skipped`
  and is skipped. It never fails the scan.
- If the scan fails as a whole, it logs `board.offmain_failed` and keeps the ref snapshot and the
  previous off-main rows.

Alternatives rejected:
- **Diff against the ref alone** (the literal wording). It gave 903 rows on coins, and nearly all of
  them were noise.
- **Rank or hide branches by commit date.** Any cutoff is arbitrary and would hide a real old pick.
- **`git diff` / `git log` per branch.** These are not needed, because blob maps from `ls-tree` give
  the same answer. Keeping to `ls-tree` also keeps the allow-list smaller (ADR-012).

## Consequences

- Coins dropped from 903 to 148 branch rows (132 distinct stories). The final count across all
  locations was 263.
- A stale branch still shows what it changed, even when main has since moved past it. For example,
  coins ACQ-20 is built on main but reads "Draft on branch design/ACQ-20-mockups — not on main". This is
  deliberate and truthful.
- The rule interprets the story's "content differs from the ref". It is pinned by `NotOnMainTest`
  "does not report what main changed after an old branch forked".
