# RD-12 — Project overview: git activity tiles
Status: built           Journey: none
Source: owner 2026-10-11 (same request as RD-11: *"last edits … recent updates"*).

## Story
As the owner, I want the project overview to show what changed in the code lately, so that I can see at a glance
how active the project is and what was last edited.

## Why
Option B's activity tiles need git reads the board does not allow today: `GitReader::log()` takes one fixed form
(subjects, one `--since`). This story widens the reader, read-only, and adds the three tiles.

## In scope
- New read-only `GitReader` methods, each a fixed argument form that `run()` allows and nothing else:
  - commits per day over the last 14 days on the project's ref;
  - per-commit lines added/removed (`--shortstat`) for the latest N commits;
  - files changed per commit (`--name-only`) for a window, to rank the most-edited files;
  - the working copy's uncommitted-path count (`status --porcelain`, counted, never contents).
- Tiles on the RD-11 Overview, in option B's places:
  - **Commits per day** — 14-day bar chart with today's count;
  - **Last edits** — the latest commits: short sha, subject, story ID link when the subject names one, +/− counts,
    relative time;
  - **Most-edited files today** — top files by commits touching them, code files only (docs/ and stories/ excluded);
  - uncommitted-path count shown beside Last edits.
- Results cached with the project's snapshot (same refresh cadence as other git reads); git failures show each
  tile's error state and log, the rest of the page still renders.

## Out of scope (do NOT build)
- Story status-change history or an activity timeline built from `git log -p` (no tile in option B needs it).
- Diffs or file contents of any kind — counts and names only.
- Any git write.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given a fixture repo with commits over 20 days, then commits-per-day covers exactly the last 14 days with
  correct counts, zero-filled.
- Given commits with known line changes, then Last edits shows each commit's +/− counts and links a subject's
  story ID to its story page.
- Given commits today touching `app/X.php` three times and `docs/Y.md` five times, then most-edited lists
  `app/X.php` and not `docs/Y.md`.
- Given a working copy with uncommitted paths, then the count is shown; given a clean one, then it reads "clean".
- Given `GitReader` is asked for any other `log`/`status` form (patch, `-p`, free-text dates), then it refuses and
  logs (read-only guard — prove the test red by deleting the guard, L-11).
- Given git fails for the project, then each activity tile shows its error state, `board.git_activity_failed` is
  logged, and the other Overview tiles still render.
- Given a 375px viewport, then the chart and lists fit with no sideways scroll.

## Applicable standards
- Design: option B tiles; reuse shell tokens and existing chart patterns (ProjectPreflight's SVG charts) — check
  `docs/UI-INVENTORY.md` first.
- Codebase/DB: no migrations. GitReader stays read-only; each new form is allow-listed in `run()` with a WHY comment.
- Logging: `board.git_activity_failed` (warning: project, which read, error); GitReader refusals reuse its existing
  refusal log.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/RD-11/option-{a,b,c}.html (shared with RD-11)
- Chosen option: B — Command center (owner, 2026-10-11)
- Why I chose it: not given — build honours option B's commits-per-day, Last edits and most-edited tiles.

## Do NOT touch
- `config/`, migrations; existing `GitReader` methods' behaviour; RD-11's tiles beyond placing these three.

## Data & interfaces
- Schema/migrations: none.
- Added: `GitReader` read methods (above), an Action that assembles the activity for a project, three tiles in
  `ProjectOverview`'s view.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST (GitFixture repos), then build.
- Journey test: none.
- Browser check: open `/p/coins` → the three activity tiles show today's real numbers; resize to 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option recorded above (reason not given)
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-11
