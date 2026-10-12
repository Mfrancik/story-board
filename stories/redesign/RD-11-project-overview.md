# RD-11 — Project overview: tiles from existing data
Status: draft          Journey: none
Source: owner 2026-10-11: *"id like a better dashboard when i go into a specific project. it should show me last
edits, project summary, metrics if appropriate. recent updates. can you give me a few mockups to choose from when i
go into /p/thisproject"* + F-16 (manual Refresh button, folded in at /story).

## Story
As the owner, I want `/p/{project}` to open a dense overview of that project, so that I see its progress, what
needs me, its health and its numbers at a glance before I drill into a tab.

## Why
RD-9 retired the old project page, so a project has no landing page — links drop me on Stories. Option B (Command
center) puts everything I check first on one screen, using data the board already reads. Git activity tiles follow
in RD-12.

## In scope
- Route `GET /p/{project}` named `projects.overview` (Livewire `ProjectOverview`), behind `EnsureProjectIsShown` and
  the `GuardsProjectPage` trait like every other `/p/{project}/*` page. Do not reuse the retired name `projects.show`.
- **Overview** becomes the FIRST tab in `board/project-tabs` (before Stories / Preflight / Handbook / Map).
- Repoint to Overview: the shell sidebar's project links (`shell/sidebar`, `data-nav-project`) and the project-name
  breadcrumb in `board/project-header` (both go to `projects.stories` today).
- Option B tile grid (4 → 2 → 1 columns at desktop / tablet / 375px), using existing readers only:
  - **Story progress by status** — counts on the project's ref (reuse `board/status-bar` and the status order).
  - **Needs you** — this project's rows from `ListNeedsMe` (drafts, awaiting a pick, ready), each linking to its
    story page; empty state "Nothing needs you here".
  - **Live now** — this project's cards from `ListLiveSessions::overview()`; empty state when none.
  - **Latest preflight** — last run's time, mode, tokens and verdict when the project's runs record has one (RD-8),
    plus a 13-day runs-vs-all-pass chart; a run with no verdict shows "—", not a guess.
  - **Production** — the project's connected metrics as tiles with sparklines (reuse `board/sparkline`,
    `MetricNumber`); with no connection, one line: "Production not connected · Set up" linking to `/projects`.
  - **About** — the "What it is" section of the project's `docs/PROJECT-BRIEF.md` at its snapshot (the handbook's
    read path); a missing brief shows the handbook's empty state, not an error.
  - **Biggest initiatives** — the top initiatives by story count with built/total bars, linking to Stories.
- **Refresh this project** button in the Overview header (F-16): queues `RefreshProjectJob` for this project, shows
  "Refreshing…" while queued, and is disabled for a disabled project.

## Out of scope (do NOT build)
- Git activity tiles (commits per day, Last edits with +/−, most-edited files) — RD-12.
- A story status-change history or merged activity timeline (option A/C ideas; no data source exists).
- A "Now:" line (option C; no clean source).
- Writing production data or preflight run records (RD-10, parked).
- Retiring a page or feature? None retired. The Stories tab keeps every action it has today; only the default
  landing page for a project link changes.

## Acceptance criteria (executable — these become the Pest test names)
- Given an enabled project, when I open `/p/{project}`, then the Overview renders with the project header, the
  Overview tab first and current, and every tile in option B's order.
- Given an unknown or disabled project, when I open `/p/{project}`, then it is refused and
  `board.project_page_refused` is logged with the reason (same as the other project pages).
- Given the shell sidebar and a project sub-page's breadcrumb, then the project link points at `/p/{project}`.
- Given stories on the project's ref, then the progress tile's counts by status match the Stories page's counts.
- Given this project has a draft, a story awaiting a pick and a ready story, then Needs you lists exactly those,
  each linking to its story page; given none, then it reads "Nothing needs you here".
- Given a live session in this project and one in another, then Live now shows only this project's.
- Given preflight runs with and without a verdict, then the latest-run tile shows the verdict only when recorded
  and "—" otherwise; given no runs, then the tile's empty state shows.
- Given a project with no production connection, then the Production area is the single "not connected · Set up"
  line linking to `/projects`; given connected metrics with snapshots, then each shows its latest value and a
  sparkline.
- Given a brief with a "What it is" section, then About shows it; given no brief, then About shows the empty state
  and the page still renders.
- When I press Refresh this project, then one `RefreshProjectJob` is queued for this project and
  `board.project_refresh_requested` is logged; given the project is disabled, then the button is disabled.
- Given a 375px viewport, then the page has no sideways scroll and the tiles stack in one column.
- RetireOldPagesTest's "bare `/p/{project}` is 404" case is superseded: it now asserts 200 for an enabled project
  (L-12: update the test that pinned the removal; do not leave it red or deleted silently).

## Applicable standards
- Design: design-standards.md shell tokens; reuse `board/status-bar`, `board/sparkline`, `board/state`,
  `shell/figure`, `shell/tag` — check `docs/UI-INVENTORY.md` before adding any component; add rows for new ones.
- Codebase/DB: no migrations. Readers stay read-only; any new query goes through an Action with a docblock.
- Logging: `board.project_refresh_requested` (info: project, request id); refusals reuse
  `board.project_page_refused`; reader failures log through their existing events.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/RD-11/option-{a,b,c}.html (chooser: docs/mockups/RD-11/index.html)
- Chosen option: B — Command center (owner, 2026-10-11)
- Why I chose it: not given — build honours option B's dense tile grid. Production numbers in the mockup are SAMPLE.

## Do NOT touch
- `config/`, migrations, kept readers' behaviour (`ListNeedsMe`, `ListLiveSessions`, `ReadPreflightHistory`,
  `ReadPreflightRuns`, `ProductionReader`) — call them, don't change them.
- The Stories / Preflight / Handbook / Map pages' content.

## Data & interfaces
- Schema/migrations: none.
- Added: route `projects.overview` (`/p/{project}`), Livewire `ProjectOverview` + view, Overview tab entry,
  refresh action. Changed: `shell/sidebar` and `board/project-header` project links.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: `tests/Browser/Journeys/BrowseTheBoardTest.php` — the project step now lands on Overview; walk on to
  Stories via its tab.
- Browser check: from `/`, click a project in the sidebar → Overview loads with every tile; click each tab and back;
  press Refresh → "Refreshing…"; resize to 375px → one column, no sideways scroll.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option recorded above (reason not given)
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-9 (merged first) · Followed by: RD-12
