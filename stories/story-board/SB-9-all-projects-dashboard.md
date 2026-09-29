# SB-9 — All-projects dashboard
Status: approved         Journey: none
Source: owner 2026-09-29 (/story): *"a main dashboar dthat gives me an overview of everything going on with
projects … insights to basically visually see what is being worked on as we speak."*

## Story
As the owner, I want `/` to be a dashboard that leads with what needs me and then shows every project's
shape at a glance, so that one page tells me what to decide and where work stands.

## Why
The home page is a set of lists. The owner wants to see the whole portfolio: what needs a decision,
what's in flight, and how far each project has come, without opening each project.

## In scope
- `/` in the design-A layout, in this order (the owner ruling: the home page leads with what needs me):
  1. **What needs me**: three cards side by side (stacked below 768px). Each has a count and its top 5
     rows plus "Show all N". The cards are Awaiting a pick, Drafts to approve and Ready to build, with
     the same rules as SB-3's groups. Rows open the SB-8 modal.
  2. **Live now**: an empty slot that SB-11 fills. Until SB-11 ships it is not rendered.
  3. **In flight**: three figures across all enabled projects. Approved and unbuilt stories, versions not
     on main, and projects not `ok`.
  4. **Projects**: one tile per enabled project. Each tile has a stacked status bar by raw status
     (out-of-vocabulary values get their own danger-toned segment), counts, not-on-main count, a
     parse-error warning, state and "refreshed N min ago". A tile links to `/p/{project}`.
  5. The collapsible Not on main, Built and Parked drafts sections from SB-3/SB-5, unchanged, below.
- The header shows "Refreshed N min ago" and the existing Refresh button.
- Filters: the initiative filter and search stay. The project filter is replaced by the sidebar (SB-7).

## Out of scope (do NOT build)
- Live sessions (SB-11). A timeline of what was built (F-2, left open by the owner).
- Charts beyond the stacked bars. Any per-project breakdown by initiative (SB-10).

## Acceptance criteria (executable — these become the Pest test names)
- Given the dev data, when `/` loads, then the first heading in `<main>` is "What needs me".
- Given 30 approved stories across enabled projects (coins 29, client-dashboard 1), then the Ready to
  build card shows 30, lists the 5 oldest first by `dated_on`, and "Show all 30" reveals the rest.
- Given no drafts to approve, then that card shows "Nothing to approve" instead of an empty list.
- Given rent-track with statuses draft 11, `in` 1 and `done` 3, then its tile's bar has `in` and `done`
  segments in the danger tone, and a parse-error warning shows 15.
- Given a project in state `unreachable`, then its tile says so with `last_error`'s first line, and the
  In flight "projects not ok" figure counts it.
- Given a disabled project, then it has no tile and none of its stories count anywhere on the page.
- Given a row in any card, when it is clicked, then the SB-8 modal opens for it.
- Given the page renders, then the number of queries is independent of the number of projects (grouped
  counts, as in SB-3).

## Applicable standards
- Design: design-A overview (SB-7 gate), with status colours from the tokens. The stacked bar becomes a
  Blade component (`board/status-bar`) because the tile, SB-10's initiative rows and the sidebar all use
  it. The `board/project-card` component is extended into the tile rather than duplicated. Every card
  and tile has a designed empty state.
- Codebase/DB: no schema change. `ListWhatNeedsMe` is reused for the three cards. The per-project counts
  stay as the two grouped queries SB-3 already has, with the not-on-main count added to the same grouped
  query.
- Logging: `board.home_viewed` stays (existing). No new events: the page has no new refusal paths.

## Design mockup gate
- Mockups: covered by docs/mockups/SB-7/option-a.html, view 1 (all projects).
- Chosen option: a (SB-7's gate)
- Why I chose it: "A is the best design" (owner, 2026-09-29).

## Do NOT touch
- `app/Services/GitReader.php`, `app/Actions/Board/RefreshProject.php`, any registered project's files.

## Data & interfaces
- Schema/migrations: none.
- Livewire: `Home` is re-laid out. New Blade `board/status-bar`; `board/project-card` extended into the
  tile. `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: `/` at 1280px shows the three What needs me cards first and four project tiles, with
  rent-track's red segments. Click a tile to reach `/p/rent-track`. At 375px the cards stack.
- Checked against real data (2026-09-29): approved on the ref is coins 29 and client-dashboard 1 = 30;
  rent-track is `{draft: 11, in: 1, done: 3}`; not on main is coins 262 (branch 154, untracked 93,
  worktree 15) and client-dashboard 28. The Awaiting a pick card shows 9 today, of which 6 are bold
  picks misread by the kit parser (F-1). This story shows what the parser reports and does not work
  around it.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7, SB-8
