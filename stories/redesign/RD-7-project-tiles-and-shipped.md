# RD-7 — Project tiles and Shipped this week
Status: approved         Journey: none
Source: owner 2026-10-10, Home A on the redesign canvas (Projects grid, Shipped this week).

## Story
As the owner, I want one tile per project on the home page and a list of what shipped this week, so that I can
see every project's health and recent progress without opening each one.

## Why
The tiles answer "is anything wrong in a project", and Shipped this week answers "what got done". Both were in
Home A; RD-5 deliberately left them out to keep that story small.

## In scope
- **Projects grid** below Needs me and Live now: one tile per enabled project (following the RD-6 filter), each
  linking to its Stories page, showing:
  - name, state dot, and a live dot when a session is running in it;
  - story progress as one bar (built · in flight · draft) with the counts underneath;
  - off-main count (branches, worktrees and untracked, from `IndexOffMain`), or "clean";
  - **last preflight:** when, wall time and mode, from `ReadPreflightHistory`; "no runs yet" when there are none.
    RD-8 adds where it ran;
  - **production headline:** when the project has a production connection, its first metric's latest value and a
    7-day sparkline from `ProdSnapshot`; otherwise nothing.
- **Shipped this week:** build commits (`feat|fix|test(<ID>)`) on each project's ref in the last 7 days, newest
  first: when, story ID, the story's title, project. Each row links to the story page. Read through `GitReader`.
- **GitReader gains `log`**, read-only: a fixed `--format`, `--since` and the ref only. `--output` and any other
  writing option are refused, in the same way as the existing guards.

## Out of scope (do NOT build)
- Spend per project, and the October spend card (Money is a later phase).
- The full timeline (F-2 stays in the backlog).
- Tile customization or a metric picker.

## Acceptance criteria (executable — these become the Pest test names)
- Given 21 built, 2 approved and 2 draft stories in story-board, then its tile shows a bar in those proportions
  and "21 built · 2 in flight · 2 draft".
- Given a project with an unmerged branch and an untracked mockup directory, then its tile reads "2 off main";
  given none, then "clean".
- Given a preflight run 6 hours ago taking 3m 12s, then the tile reads "6h ago · 3m 12s"; given no runs, then
  "no runs yet".
- Given a project with a production connection and snapshots, then the tile shows the metric's label, latest value
  and a sparkline; given none, then no production row shows.
- Given three `feat(SB-n)` commits and one `docs(SB-n)` commit on the ref this week, then Shipped this week lists
  the three, newest first, and not the docs commit.
- Given `GitReader` is asked for `log --output=x`, then it refuses and logs the refusal.
- Given the RD-6 filter set to one project, then only its tile and its shipped rows show.

## Applicable standards
- Design: RD-2 tokens; sparkline as inline SVG, decorative and `aria-hidden`, with the figure in text beside it.
- Codebase/DB: new actions for the tiles and for shipped; reuse `ReadProjectStories`' status rollup (RD-1), `IndexOffMain`,
  `ReadPreflightHistory`, the prod models. Add `log` to `GitReader::ALLOWED` with argument guards. No migrations.
- Logging: `board.shipped_read` (project, count); the existing `GitReader` refusal event for refused `log` arguments.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: redesign canvas https://claude.ai/artifact/Ervkp5duofs7VbUbbz7tDg, board "Home A — Command center"
  (Projects grid, Shipped this week). Save as `docs/mockups/RD-7/option-a.html` before building.
- Chosen option: A (owner, 2026-10-10)
- Why I chose it: "A is better".

## Do NOT touch
- `ProductionReader`, and anything that opens a production connection. The tiles read stored snapshots only.
- `preflight.sh`, `bin/`.

## Data & interfaces
- Schema/migrations: none.
- `GitReader` allow-list gains guarded `log`. New home actions for the tiles and for shipped.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build (git fixture repos as in the
  existing reader tests).
- Journey test: none.
- Browser check: open `/`; story-board's tile matches its Stories page counts; Shipped this week lists SB-19 if it
  was built within 7 days.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-5, RD-6
