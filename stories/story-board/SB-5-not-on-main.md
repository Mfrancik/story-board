# SB-5 — Show work that isn't on main yet
Status: approved         Journey: none
Source: owner 2026-09-29 (coins /story). Found at /story: 67 mockup directories sit untracked in coins'
primary checkout, 12 more `coins-*` checkouts hold their own, and approved decisions live on unpushed
branches (e.g. MOB-56's pick on `docs/MOB-56-pick`).

## Story
As the owner, I want the board to show stories and mockups that exist only on a branch, in another
checkout, or as untracked files, labelled as not on main, so that nothing I've drafted or picked goes
missing just because it hasn't been merged.

## Why
Reading only origin/main (SB-2) is correct for status, but it hides real work. Without this, the board
agrees with main and still misses things the owner has actually decided.

## In scope
- Per project, also index:
  - Local and remote branches not merged into the project ref, but only for stories whose content
    differs from the ref (new file, or a different Status or Chosen option).
  - The project's git worktrees (`git worktree list`) and sibling checkouts registered as aliases
    of the project (e.g. `~/Code/coins-*` → coins).
  - Untracked `stories/**` and `docs/mockups/**` in each checkout, read-only (`git status
    --porcelain`, then read the file).
- Every such item carries a location: `branch <name>`, `worktree <path>`, `untracked in <path>`. It
  appears as a separate "Not on main" section on the home page (SB-3) and as a banner on the story
  page (SB-4), showing each version's status.
- Branch-only mockups are viewable in SB-4's panel from that branch's ref.

## Out of scope (do NOT build)
- Merging, pushing, committing, or cleaning anything up.
- Uncommitted edits to tracked files (too noisy). Only untracked files and branch commits count.

## Acceptance criteria
- Given MOB-56 says `Chosen option: _pending_` on the ref but `d` on an unmerged branch, then the
  story shows a banner: "Picked D on branch docs/MOB-56-pick — not on main".
- Given a story file exists only on an unmerged branch, then it appears under Not on main with its
  branch name and does not count in SB-3's groups.
- Given an untracked `docs/mockups/X-1/option-a.html` in a checkout, then it appears under Not on main
  with `untracked in <path>`.
- Given a branch whose stories are identical to the ref, then it produces no rows.
- Given a merged branch, then it is ignored.
- Given indexing runs, then every checkout's `git status --porcelain` is unchanged.

## Applicable standards
- Design: follows the layout chosen at SB-3/SB-4 (no new gate unless the section needs a new layout;
  the builder raises it if it does).
- Codebase/DB: `project_locations` (aliases/worktrees); a `stories.location` column (null = on ref).
  All git access goes through `GitReader`.
- Logging: `board.offmain_indexed` (counts per location type).

## Design mockup gate
n/a. Reuses SB-3/SB-4 components. If the Not-on-main section needs a new layout, stop and open a gate.

## Do NOT touch
- Any checkout's files, branches, or worktrees.

## Data & interfaces
- Migration: `project_locations`, `stories.location`, `stories.branch`.

## Test plan
- Pest tests with fixture repos covering a branch, a worktree, and an untracked file.
- Browser check: coins shows the MOB-56 banner (if that branch is still unmerged) and a non-zero
  untracked-mockups count.

## Definition of done
- [ ] Acceptance criteria pass
- [ ] Logging events in place
- [ ] Status flipped to `built` in the build commit
- [ ] /preflight GO
- [ ] /document

## Links
Journey: none · Depends on: SB-2, SB-3, SB-4
