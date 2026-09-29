# SB-3 — The home page shows what needs me
Status: approved         Journey: none
Source: owner 2026-09-29 (coins /story). Ruling on the main screen: **"What needs me"**, ahead of
per-project counts or a kanban.

## Story
As the owner, I want the board's first screen to show what's waiting on me across every project, so
that I can see at a glance what to approve, which mockup to pick, and what's ready to build, without
opening each repo.

## Why
The owner picked this over counts or a kanban. The board is for making decisions, not for reporting.

## In scope
- Top of `/`, across all projects, three groups (each row shows project, ID, title, initiative):
  1. **Awaiting approval**: `draft` stories, excluding those in parked draft groups (an initiative
     folder with its own README, per stories/README.md §Draft groups).
  2. **Awaiting a mockup pick**: stories with a mockup directory but an empty `Chosen option:`.
  3. **Ready to build**: `approved` stories, oldest first.
- Below that: one card per project with its counts by status, parse-error count, last refresh time,
  ref SHA, and stale/unreachable state from SB-2.
- Filters for project, initiative and text search (ID or title) that apply to every group. Filter
  state lives in the URL.
- A Refresh button (runs SB-2's refresh).
- A row links to the story view (SB-4). Until SB-4 ships, it links nowhere.
- Parse errors show as a visible warning on the project card, never hidden.

## Out of scope (do NOT build)
- Any write action: approve, pick, cancel. The board is read-only by owner ruling.
- Story detail and mockup rendering (SB-4).
- Branch-only and untracked work (SB-5).

## Acceptance criteria
- Given coins has 2 drafts, 1 of them in a parked draft group, when `/` loads, then Awaiting approval
  lists only the unparked one.
- Given a story with `docs/mockups/<ID>/option-a.html` and an empty `Chosen option:`, when `/` loads,
  then it appears under Awaiting a mockup pick. Once `Chosen option: a` is on the ref and refreshed,
  it no longer does.
- Given 3 approved stories across 2 projects, when `/` loads, then Ready to build lists all 3, oldest
  first.
- Given the project filter is set to coins, when the page reloads, then the filter persists (from the
  URL) and only coins rows show.
- Given a search for `MOB-65`, then only that story shows in any group.
- Given nothing is waiting in any group, then each group shows a one-line empty state, not a blank.
- Given a project is unreachable, then its card shows it and the other projects still render.
- Given a story has a parse error, then its project card shows the count, and the list shows the
  story's raw status value.

## Applicable standards
- Design: the design-standards.md installed with the kit in this repo; Flux/Tailwind tokens;
  light and dark themes; works at 375px.
- Codebase/DB: one Livewire page component; filters are URL-bound properties; reads come only from
  the `stories` table, never git at request time (apart from the SB-2 staleness refresh).
- Logging: `board.home_viewed` (filters as context), `board.refresh_requested`.

## Design mockup gate
- Mockups: `docs/mockups/SB-3/option-{a,b,c}.html`, using the real coins data shape (~900 stories,
  4 projects).
- Chosen option: _pending_
- Why I chose it: _pending_

## Do NOT touch
- Registered projects. SB-2's `GitReader`, except to call it.

## Data & interfaces
- Route `/` → `App\Livewire\Home`. No schema change. Parked groups can be detected from the path
  (an initiative with a README); if that needs a column, add it in a migration.

## Test plan
- Pest tests from the acceptance criteria, written first.
- Journey test: none.
- Browser check: with the four real projects refreshed, open `/`, then screenshot and open the
  screenshots at 1280 and 375, in light and dark. Coins' Ready-to-build count must match
  `git grep -h '^Status: approved' origin/main -- 'stories/**/*.md' | wc -l`.

## Definition of done
- [ ] Acceptance criteria pass
- [ ] Mockup gate cleared: chosen option and reason recorded above
- [ ] Logging events in place
- [ ] Status flipped to `built` in the build commit
- [ ] /preflight GO
- [ ] /document

## Links
Journey: none · Depends on: SB-2
