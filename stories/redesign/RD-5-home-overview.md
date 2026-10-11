# RD-5 — Home overview
Status: built            Journey: none
Source: owner 2026-10-10, Home A on the redesign canvas: *"A is better. we can pick and choose what metrics to show
at each phase, we can just build the overview for now."*

## Story
As the owner, I want `/` to tell me at a glance how many things need me, what is being built right now, and how
preflight is doing, so that I know where to spend my next ten minutes.

## Why
The old home led with three cards and long sections. Home A leads with a headline row, then the two lists that
need action: Needs me and Live now.

## In scope
- `/` is a new overview page in the RD-2 shell. Header: today's date and one sentence built from the counts
  ("7 things need you. 3 sessions are building.").
- **Headline row, four figures:**
  - Live sessions: count, and how many projects they span.
  - Needs me: count, and the split between picks, drafts and ready to build.
  - Preflight, last 7 days: number of runs and median wall time.
  - In flight: approved stories not yet built.
- **Needs me**, oldest first: mockup sets awaiting a pick, draft stories awaiting approval, and approved stories
  ready to build. Each row shows a kind tag, the title, project and ID, its age, and links to the RD-3 story page.
  Built from the salvaged `ListWhatNeedsMe` queries in a new action.
- **Live now:** one card per active Claude Code session showing project, branch, the story it is building (when
  matched), elapsed time, **what it is doing** (its latest tool call, summarized in one line) and **how full its
  context is** (tokens in use from the latest usage record, against the model's window, as a bar and "142k / 200k").
  Refreshes every 30 s with `wire:poll`, the same as today.
- Each panel has a loading, empty and error state.

## Out of scope (do NOT build)
- Project tiles and Shipped this week (RD-7); the project filter (RD-6).
- Spend, Money, the Brain card, and cost per session.
- A metric picker. The owner will choose metrics per phase in later stories.

## Acceptance criteria (executable — these become the Pest test names)
- Given 2 picks awaiting, 1 draft and 3 approved stories, then Needs me reads 6 with "2 picks · 1 draft · 3 ready",
  and the list shows 6 rows, oldest first.
- Given a Needs me row, when it is clicked, then it opens that story's page.
- Given two live sessions in one project, then Live sessions reads 2, "1 project", and Live now shows two cards.
- Given a session whose last record is a Read tool call on `tests/Feature/X.php`, then its card reads
  "Reading tests/Feature/X.php".
- Given a session whose latest usage totals 142,000 tokens on a 200k model, then its bar is 71% full and reads
  "142k / 200k"; given no usage record yet, then the bar reads "—".
- Given 5 preflight runs in the last 7 days across projects, then the Preflight figure reads 5 with their median
  wall time; given none, then it reads 0 and "no runs this week".
- Given no projects, then the page shows one empty state linking to /projects instead of the panels.
- Given the session reader throws, then Live now shows its error state and the rest of the page still renders.

## Applicable standards
- Design: RD-2 tokens; design-standards §Every interactive view must handle four states.
- Codebase/DB: new actions for the headline figures and Needs me. A slimmed `ListLiveSessions` (sessions per project
  and branch, plus the new "doing" and context fields) on top of `SessionReader`. No migrations.
- Logging: `board.overview_viewed` (counts); `board.live_session_unparsed` (warning) when a session file's
  usage or tool record cannot be read.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: redesign canvas https://claude.ai/artifact/Ervkp5duofs7VbUbbz7tDg, board "Home A — Command center"
  (the header, the headline row, Needs me and Live now). Save it as `docs/mockups/RD-5/option-a.html` before building.
- Chosen option: A (owner, 2026-10-10)
- Why I chose it: "A is better", over the timeline-and-stream direction.

## Do NOT touch
- `SessionReader`'s defensive parsing rules (extend them, do not loosen them).
- The old `Home` component (removed in RD-9).

## Data & interfaces
- Schema/migrations: none.
- `/` routes to the new overview component. The old home stays in the code, unrouted, until RD-9.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build. Session fixtures via
  `tests/Support/SessionFixture`.
- Journey test: none.
- Browser check: with this session running, open `/`; this session's card shows its branch, what it is doing and
  a context bar.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-2, RD-3
