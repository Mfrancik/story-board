# SB-10 — Single-project dashboard
Status: built            Journey: none
Source: owner 2026-09-29 (/story): *"id like to be able to see a view like this of all projects, but also
a view of a sepcific single project."*

## Story
As the owner, I want `/p/{project}` to be that project's dashboard, with what needs me, how each
initiative is progressing and where off-main work lives, so that I can see one project's state in depth.

## Why
The all-projects page shows breadth. A single project needs depth: coins alone has 57 initiatives and
262 versions not on main, which a portfolio tile cannot show.

## In scope
- `/p/{project}` in the design-A single-project layout, replacing SB-7's interim scoped home:
  1. Header: project name, state, ref and short SHA, "refreshed N min ago", and a "Refresh this project"
     button that queues one `RefreshProjectJob` for this project only.
  2. What needs me for this project: the SB-9 cards, scoped.
  3. Live now for this project: an empty slot that SB-11 fills.
  4. **Progress by initiative**: one row per initiative with the `board/status-bar` and its counts. Rows
     are ordered by open work (draft plus approved), most first. A parked initiative carries a "parked"
     label. 8 rows show, then "Show all N".
  5. **Not on main**: counts by location kind (branch, worktree, untracked), each expanding to its rows
     (SB-5's list, scoped). Rows open the SB-8 modal.
- Every row on the page opens the SB-8 modal.

## Out of scope (do NOT build)
- Handbook content such as lessons, rules and standards (SB-14). Live sessions themselves (SB-11).
- Editing anything about the project. Toggling a project on or off is SB-12.

## Acceptance criteria (executable — these become the Pest test names)
- Given coins with 57 initiatives, when `/p/coins` loads, then Progress by initiative shows 8 rows ordered
  by open work, and "Show all 57" reveals the rest.
- Given an initiative whose README says `Status: draft group`, then its row carries the "parked" label.
- Given coins' off-main rows (branch 154, untracked 93, worktree 15), then Not on main shows those three
  counts, and expanding "branch" lists branch rows with their branch names.
- Given asset-track with no off-main rows, then Not on main reads "Nothing off main".
- Given "Refresh this project" on `/p/coins`, when it is clicked, then exactly one `RefreshProjectJob` for
  coins is queued (none for other projects), the notice "Refresh queued for coins" shows, and
  `board.refresh_requested` is logged with `project`.
- Given rent-track, then its initiative row's bar shows the out-of-vocabulary `in` and `done` segments in
  the danger tone.
- Given the page, then the number of queries does not grow with the number of initiatives.

## Applicable standards
- Design: design-A single-project view (SB-7 gate). Reuses `board/status-bar` (SB-9), the SB-9 cards,
  `board/section` and `board/story-row`.
- Codebase/DB: no schema change. The initiative rollup is one grouped query on `stories` (project,
  initiative, status) and `is_parked`. Refresh reuses `RefreshProjectJob`.
- Logging: `board.project_viewed` (SB-7) stays. `board.refresh_requested` gains a `project` key when it is
  scoped to one project.

## Design mockup gate
- Mockups: covered by docs/mockups/SB-7/option-a.html, view 2 (single project: coins).
- Chosen option: a (SB-7's gate)
- Why I chose it: "A is the best design" (owner, 2026-09-29).

## Do NOT touch
- `app/Services/GitReader.php`, `app/Actions/Board/RefreshProject.php`, any registered project's files.

## Data & interfaces
- Schema/migrations: none.
- Livewire: a new `ProjectPage` component at `/p/{project}` (replaces SB-7's interim content).
  `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: `/p/coins` shows 8 initiative rows and the three not-on-main counts. Click "Refresh this
  project" and see the notice, then watch `board.refresh_finished` for coins only in the dev log.
  `/p/asset-track` shows the "Nothing off main" state.
- Checked against real data (2026-09-29): initiatives on the ref are coins 57, client-dashboard 14,
  asset-track 4 and rent-track 1. Off main is coins `{branch: 154, untracked: 93, worktree: 15}`,
  client-dashboard `{branch: 17, untracked: 1, worktree: 10}`, and none for asset-track and rent-track.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7, SB-8, SB-9
