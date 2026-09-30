# ADR-034 — Live cards link untracked stories only from their own checkout

Date: 2026-09-30 · Status: accepted

## Context

SB-11's fallback (`ListLiveSessions::links()` step 2) links a session with no story ID in its branch to the
off-main rows on that branch. `IndexOffMain` tags an untracked file with the branch of the checkout it sits
in, so every untracked story in a project's main checkout carries `branch = main`. Every session on `main`,
in any folder, then listed all of them: the owner saw ADMIN and BRAND lists on two coins sessions, one in the
main checkout and one in a worktree. SB-25 fixes this without changing how `IndexOffMain` tags rows.

The story states rule 2 as "the session's cwd equals or sits under the row's checkout root". Worktrees live
at `<main>/.claude/worktrees/<name>`, so every worktree cwd sits under the main checkout, and that literal
rule would let a worktree session borrow the main checkout's untracked rows. That contradicts the story's
own second acceptance criterion.

## Decision

In `app/Actions/Board/ListLiveSessions.php:branchRows()`:
- **Rule 1.** On the project's default branch (`projects.ref` with a leading `origin/` stripped) the
  fallback returns nothing. The default branch collects every checkout's leftovers and does not name a unit
  of work. Step 1 (IDs in the branch name) runs before this and is unchanged.
- **Rule 2, stricter than the story's wording.** An untracked row links only when its root equals the
  session's **own** checkout root: the deepest of the project path, its registered locations and
  `<main>/.claude/worktrees/<name>` (derived from the cwd, so it works before a refresh registers the
  worktree) that contains the cwd, matched on whole path segments. Equality, not containment.
- Paths compare case-sensitively with trailing slashes trimmed, as typed and via `realpath`, because
  `IndexOffMain` stores the root resolved.
- Branch and worktree rows are committed to the branch and still link by branch alone.
- `board.session_links_filtered` (debug, metadata only) records each drop.

Alternatives rejected:
- **The literal "cwd sits under the root" rule.** Fails criterion 2, as above.
- **Re-tag untracked rows in `IndexOffMain`** (e.g. no branch for untracked files). Out of scope and on the
  story's Do-NOT-touch list; the "Not on main" section relies on the current rows.
- **Only rule 2, no rule 1.** A session in the main checkout on `main` would still claim every untracked
  file there, which is the owner's first case.

## Consequences

- A session on the default branch never links its own untracked stories; naming the ID in a branch is the
  way to get a link.
- Only the `origin/` prefix is recognised. A ref on another remote (`upstream/main`) escapes rule 1.
- `checkoutRoot()` duplicates the idea behind `matched()`'s deepest-anchor rule plus the worktree folder
  convention in `checkout()`. A change to where worktrees live must update all three.
