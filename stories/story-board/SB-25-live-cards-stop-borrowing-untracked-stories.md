# SB-25 — Live cards stop borrowing every untracked story
Status: approved          Journey: none
Source: owner 2026-09-29, on the coins Live panel: *"i see all these, what are they"* — two coins sessions on
`main` (main checkout, worktree `prf7ch`) each listed ADMIN-16, ADMIN-23, ADMIN-24, BRAND-10, BRAND-9…, all
"Not on main · untracked in /Users/mikefrancik/Code/coins". Owner: *"lets fix it, you can write a story for it"*.

## Story
As the owner, I want a live session card to link only the stories that session is actually working on, so that
a session on `main` doesn't show every uncommitted story file lying around in the main checkout.

## Why
SB-11's fallback (`ListLiveSessions::links()` step 2) links off-main rows whose `branch` equals the session's
branch. `IndexOffMain::untrackedRows()` tags untracked files with the branch of the checkout they sit in, so every
untracked story in the main checkout carries `branch = main`, and every session on `main`, in any folder,
links all of them.

## In scope
- **Rule 1:** when the session's branch is the project's default branch (the branch named by `projects.ref`,
  `origin/` stripped), and the branch holds no story ID, the fallback does not run. The card shows
  "No story linked".
- **Rule 2:** in the fallback, an untracked row links only to a session whose `cwd` equals or sits under that
  row's checkout root (the path in its `location`, "untracked in <root>"). Branch and worktree rows still link by
  branch as today.
- Step 1 (story IDs in the branch name) is unchanged, including on the default branch.
- A debug log when rule 1 or rule 2 drops rows, so the panel's choice can be traced.

## Out of scope (do NOT build)
- Changing how `IndexOffMain` indexes or tags untracked rows (the "Not on main" section still lists them).
- Anything about committing or cleaning up the untracked files in coins.
- The sidebar live badge (`counts()`): it has no story links.
- Any visual change to the card.

## Acceptance criteria (executable — these become the Pest test names)
- Given a session on `main` in the main checkout and untracked stories in that checkout, when the panel renders,
  then the card shows "No story linked".
- Given a session on `main` in a worktree and untracked stories in the main checkout, when the panel renders,
  then the card shows "No story linked".
- Given a session on `feat/x` (no ID) in worktree W and an untracked story in W, when the panel renders, then
  that story is linked.
- Given a session on `feat/x` (no ID) in worktree W and an untracked story in a different checkout also tagged
  `feat/x`, when the panel renders, then that story is not linked.
- Given a session on `feat/x` and a branch row (not untracked) on `feat/x`, when the panel renders, then the
  branch row is linked as before.
- Given a session on `main` whose branch name contains no ID but the project's ref is `origin/main`, when the
  panel renders, then rule 1 still applies.
- Given a session on `feat/SB-9-thing`, when the panel renders, then SB-9 is linked by step 1, unchanged.
- Given rule 1 or rule 2 drops rows, then `board.session_links_filtered` is logged at debug with project, branch
  and the count dropped, and no story content.

## Applicable standards
- Design: none (no visual change; the existing "No story linked" state is reused).
- Codebase/DB: codebase-standards.md: docblocks on changed methods, WHY comments on both rules. No migration.
- Logging: `board.session_links_filtered` (debug): project, branch, rule, dropped count. Metadata only, per
  SB-11's privacy rule.

## Design mockup gate (visual stories only — else "n/a — non-visual")
n/a — non-visual

## Do NOT touch
- `app/Actions/Board/IndexOffMain.php`
- `app/Services/SessionReader.php`
- `resources/views/livewire/board/live-sessions.blade.php`
- `config/`

## Data & interfaces
- Schema/migrations: none.
- Livewire components / routes / events added or changed: none. `ListLiveSessions::links()` gains the session
  `cwd` as an argument.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, in `tests/Feature/Board/LiveSessionsTest.php`,
  then build.
- Journey test: none.
- Browser check: with a coins session open on `main` and untracked stories in `~/Code/coins`, open `/p/coins`.
  The Live panel card shows "No story linked" instead of the ADMIN/BRAND list.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc `docs/features/live-sessions.md` updated via /document

## Links
Journey: none · Depends on: SB-11
