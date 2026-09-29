# SB-18 — Production dashboard
Status: built          Journey: none
Source: owner 2026-09-29 (/story): *"a dashboard report that connects to production … query things like number of
users, users logged in today, etc.. so i have a centralized place to see all of that."* Owner answer: daily
snapshot for history.

## Story
As the owner, I want one page that shows every project's production numbers side by side, with how they have
changed, so that I can see how all my apps are doing in one place.

## Why
SB-17 makes a safe connection and defines the metrics. This page is the reason for it: the centralized view,
plus history so a number means something ("412 users, +9 this week").

## In scope
- A **Production** page at `/prod`, linked from the sidebar, inside the design-A shell.
- One section per project that has a connection: each enabled metric as a stat (value, change vs. yesterday and
  vs. 7 days ago), and a small trend line per metric over the last 30 days of snapshots. Projects without a
  connection are listed at the end as "not connected — set up on /projects".
- **Live on load.** Values are read when the page opens, per project, in parallel via the queue (the same
  queued pattern as refresh, ADR-006), so one slow project never blocks the rest. Each project section shows a
  skeleton, then its numbers or its error ("unreachable", "timed out", "refused: this user can now write").
  A Refresh button re-reads one project.
- **Daily snapshot.** A new table stores one value per metric per local day; each successful read upserts
  today's row (the latest read of the day wins). A scheduled `board:prod-snapshot` command reads every
  connected project once a day at 23:55 local, so days with no page visit still have a point.
- Each stat shows when it was read ("read 2 min ago"). A failed read keeps showing the last good value, greyed,
  with its time, and the error.
- **Changes** compare against the snapshot for yesterday and for 7 days ago; missing history shows "—".

## Out of scope (do NOT build)
- Connection and metric setup (SB-17). Any write to production.
- Alerts or notifications. Charts beyond one trend line per metric. Per-project production tabs.
- Snapshots older than 400 days are kept; no pruning in this story.

## Acceptance criteria (executable — these become the Pest test names)
- Given two connected projects with 2 metrics each, when `/prod` loads, then both sections render with a
  skeleton and each metric's value arrives from a queued read.
- Given a metric read today, then a snapshot row exists for that project, metric and local date; a second read
  the same day updates it rather than adding one.
- Given snapshots for yesterday and 7 days ago, then each stat shows its change against both.
- Given no snapshot 7 days ago, then that change shows "—".
- Given one project unreachable, then its section shows "unreachable" with the last good values greyed and
  timed, and the other project's numbers still show.
- Given a connection whose grants now include a write privilege, then the read is refused, nothing is queried,
  and the section says so.
- Given `board:prod-snapshot` runs, then every connected project's enabled metrics are read once and snapshotted.
- Given a project with no connection, then it is listed under "not connected" with a link to `/projects`.
- Given no project is connected, then `/prod` shows an empty state pointing to `/projects` and returns 200.
- Given a disabled project, then it is not shown on `/prod` and its snapshot is skipped.
- Given the page loads, then `board.prod_viewed` is logged with `projects` (count); each read logs
  `board.prod_read` with `project`, `metrics`, `ms`, `ok`.

## Applicable standards
- Design: design-A shell and sidebar (SB-7); stat tiles and trend lines per the dataviz method; skeleton per
  project section; tokens are law. No chart library unless the mockup pick needs one (ask before adding).
- Codebase/DB: new table `prod_snapshots` (project_id, prod_metric_id, day date, value decimal, read_at; unique
  on metric + day). All production reads go through SB-17's `ProductionReader`. The scheduled command is
  registered in `routes/console.php`; note in the doc that `composer run dev` must run the scheduler for it.
- Logging: `board.prod_viewed` (info; `projects`), `board.prod_read` (info; `project`, `metrics`, `ms`, `ok`),
  `board.prod_read_failed` (warning; `project`, `reason`), `board.prod_snapshot_run` (info; `projects`, `ok`,
  `failed`). Never values or credentials in warnings about other projects.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/SB-18/option-{a,b,c}.html (inside the design-A shell; coins, client-dashboard,
  asset-track and rent-track as sections, one of them unreachable and one not connected; plausible example
  values, labelled as examples)
- Chosen option: b
- Why I chose it: owner pick 2026-09-29 (/story mockup gate), no reason given. Direction: comparison table: projects as rows, metrics as columns, each cell holding value, both changes, sparkline and read time; cards per project below 768 px.

## Do NOT touch
- `ProductionReader`'s safety checks (SB-17): reuse, never bypass. `config/`. `app/Services/GitReader.php`.
  Any registered project's files.

## Data & interfaces
- Schema/migrations: `prod_snapshots` (new).
- Route: `GET /prod` (named `prod`). Livewire: `ProductionDashboard`. Job: `ReadProductionMetrics` (unique per
  project). Command: `board:prod-snapshot`. Sidebar gains a Production link. `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST. The same two-user local MySQL fixture as
  SB-17; time frozen with `travel`.
- Journey test: none.
- Browser check: with coins connected, `/prod` shows its numbers after a skeleton; Refresh re-reads; stop the
  local fixture DB and see "unreachable" with greyed last values.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-17
