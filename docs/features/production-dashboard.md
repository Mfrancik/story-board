# Production dashboard
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-18

## Overview
`/prod` puts every connected project's production numbers side by side: one row per project, one column
per metric, each cell holding the value, its change against yesterday and 7 days ago, a 30-day trend line
and when it was read. It is the reason SB-17's read-only connection exists
([production-connection.md](production-connection.md)). A daily snapshot table gives each number history,
so "412 users" can also say "+9 this week".

> **The daily snapshot needs the scheduler, and `composer run dev` does not start it.** `composer run dev`
> runs the server, queue worker, pail and Vite only. Run `php artisan schedule:work` in a second terminal,
> or `board:prod-snapshot` at 23:55 never fires and a day nobody opened `/prod` has no point on the trend
> line (RUNBOOK "No production snapshot for a day"). The owner chose to document this rather than change
> `composer.json` (2026-09-29).

## How it works
**Page load.** `GET /prod` (route `prod`, `routes/web.php`) is `app/Livewire/Board/ProductionDashboard.php`
inside the design-A shell. Mockup: option B (`docs/mockups/SB-18/option-b.html`), a comparison table that
becomes one card per project below 768 px. `mount()` logs `board.prod_viewed`, then for every enabled
project with a connection calls `queue()`. That records the project in `$pending` as
`{seq, at}`, where `seq` is the project's current read count, and dispatches `ReadProductionMetrics`.

**The read.** `app/Jobs/ReadProductionMetrics.php` is `ShouldBeUnique` per project (`uniqueFor` 120 s), so
a burst of page loads or Refresh presses queues one read. This is ADR-006's refresh pattern: the worker
reads projects in parallel and one slow database never holds up the page or the other rows. The job
restores the request's `request_id` and calls `app/Actions/Board/ReadProductionProject.php:handle()`:
1. Skips (and logs `board.prod_read_skipped`) a project switched off or disconnected since it was queued.
2. Calls SB-17's `ProductionReader::readEnabledMetrics()`. This is the only production access; the
   grants check runs on every read, so a user that has gained a write privilege is refused before any
   metric is queried. `ProductionReader` is unchanged by this story.
3. If the connection check passed: sets `prod_connections.verified_at` and **upserts today's
   `prod_snapshots` row** for every metric that returned a number. "Today" is the owner's day in
   `board.timezone` (`ReadProductionProject::today()`). The upsert is keyed on metric + day, so the last
   read of the day wins.
4. Logs `board.prod_read`, plus `board.prod_read_failed` if the connection or any metric failed.
5. Stores the **outcome** in the cache under `board:prod-read:{project_id}`: `seq` (previous + 1), `at`,
   `ok`, `status`, `reason`, `message`, and each failed metric's status and message. It is an array, not an
   object (the cache store has `serializable_classes` off), and `outcome()` rebuilds it field by field so an
   entry written by an older build cannot break the page
   ([ADR-030](../decisions/ADR-030-production-read-outcome-lives-in-the-cache-and-rows-settle-by-read-count.md)).

**Settling rows.** While `$pending` is not empty the view renders a hidden `wire:poll.2s="poll"`; otherwise
it does not poll at all. `settle()` drops a project from `$pending` once its cached `seq` is higher than the
one recorded when the read was asked for. It compares **read counts, not timestamps**: timestamps failed
under frozen test time, could clear a row with an older read's result, and a count also settles correctly
when a second request folds into an already-queued unique job (ADR-030). `mount()` and `refresh()` call
`settle()` straight away, so under a `sync` queue the numbers are there on first render.

**No worker.** A row still pending after `ProductionDashboard::STALL_SECONDS` (60) moves to `$stalled`, shows
"No answer from the queue worker after a minute — is composer run dev running?" over its last good values,
and logs `board.prod_read_stalled`.

**Refresh.** Each row's button calls `refresh(projectId)`. It re-checks that the project is enabled and
connected (else logs `board.prod_refresh_refused` and does nothing), logs `board.prod_refresh_requested`
and queues one read.

**What the page draws.** `render()` calls `app/Actions/Board/ListProductionDashboard.php:handle()`, which
reads the **board's own database only, never production**, in a fixed number of queries:
- Rows: enabled, connected projects in name order. Each row gets colour slot `position % 3 + 1`
  (`--color-series-1..3`), so colours follow alphabetical position, not the project.
- Columns (`columns()`): each preset any row tracks, in `ProdMetric::PRESETS` order, then one **Custom**
  column that stacks each project's own SELECTs. A row without a column's metric shows "not tracked".
- Each stat (`stat()`): the latest snapshot in the last 30 days is the value. Changes (`change()`) compare
  it with the snapshots for **the day before the value's own day** and 7 days before it, not before today.
  A greyed value from an earlier day compared with "yesterday" would otherwise be compared with itself.
  No snapshot that day gives "—". The trend is 30 days of values, `null` for a missing day.
- Row state: `loading` (skeleton), `stalled`, `error` (the last read's connection check failed:
  "Unreachable", "Refused: this user can now write", "Refused: this user can do more than SELECT", or
  "Could not connect") or `ok`. On an error every value shows greyed with its read time; the last good
  values come from the snapshots. A metric that failed on its own is greyed with its message under it,
  and the other metrics still show.
- `historySince` says where a line starts when a project has under 30 days of history.
- Projects without a connection are listed under **Not connected** with a link to `/projects`. With no
  connected project at all, the page is an empty state pointing to `/projects`.

**Blade components** (`resources/views/components/board/`): `prod-stat` (one metric, `layout="cell"` or
`"line"`), `prod-change` (signed change, ▲/▼, `text-gain`/`text-loss`), `prod-row-status` (Reading… / read
time / error badge), and `sparkline`. The sparkline is inline SVG stretched to its box
(`preserveAspectRatio="none"`, non-scaling stroke), with no chart library. A missing day keeps its x
position, so the spacing stays true to the calendar. The end dot and the hover readout (nearest day with a
value) are HTML over the SVG, driven by Alpine.

**Sidebar.** `app/View/Components/Board/Sidebar.php` adds a Production link with connected / shown projects
("2/3").

**Daily snapshot.** `board:prod-snapshot` (`app/Console/Commands/BoardProdSnapshot.php`) is scheduled in
`routes/console.php` at 23:55 in `board.timezone`, `withoutOverlapping()`. It reads each enabled, connected
project **in its own process, one after another, through the same `ReadProductionProject`**, not via the
queue, so the schedule needs no worker and no open page
([ADR-031](../decisions/ADR-031-daily-production-snapshot-reads-in-process.md)). Switched-off projects with
a connection are counted as skipped. A failing project never stops the rest, and the command always exits 0.

## Data model
Migration `2026_09_29_180001_create_prod_snapshots_table.php`, model `app/Models/ProdSnapshot.php`.
- `prod_snapshots`: `project_id` and `prod_metric_id` (both cascade on delete, so removing a metric or a
  project removes its history), `day` (date, the owner's local day, not the UTC date of `read_at`),
  `value` `decimal(20,4)` (custom SELECTs may return fractions), `read_at`, timestamps.
  Unique `(prod_metric_id, day)`; index `(project_id, day)`. Nothing is pruned.
- Written only by `ReadProductionProject::snapshot()`. The only other board-DB write in this feature is
  `prod_connections.verified_at` after a good grants check.
- Not a table: the last read outcome per project, in the cache (see How it works). Losing the cache
  loses only the error banner; last good values come from snapshots.

## Interfaces
- Route `GET /prod` → Livewire `Board\ProductionDashboard`. Public actions: `poll()`, `refresh(int)`.
  `$pending` and `$stalled` are `#[Locked]`.
- Job `ReadProductionMetrics(Project)`, unique per project id.
- `ReadProductionProject::handle(Project): ?array` (outcome, or null when skipped);
  `::outcome(int)`, `::outcomeKey(int)`, `::today()`.
- `ListProductionDashboard::handle(array $loading, array $stalled)` →
  `{rows, columns, notConnected}`; `::format(float)` (thousands separators, else up to 2 places).
- Command `board:prod-snapshot`: one line per project (`ok` / `failed: <reason>`).
- Theme tokens (`resources/css/app.css`, owner-approved 2026-09-29): `--color-series-1/2/3` (dataviz
  palette's first three slots; dark steps chosen for the dark surface, not flipped), `--color-gain` /
  `--color-loss` (emerald-700 / rose-700, -400 in dark). SB-16's single `series` token is to be pointed at
  `series-1` at integration, so the board has one chart palette.
- Test hooks: `data-prod-row`, `data-prod-card`, `data-prod-skeleton`, `data-prod-refresh`,
  `data-prod-value`, `data-prod-stale`, `data-prod-not-connected`, `data-prod-empty`, `data-sidebar-prod`.

## Configuration
- `board.timezone` (SB-17) decides the snapshot day and the 23:55 schedule.
- Fixed in code: `ProductionDashboard::STALL_SECONDS` 60, `ListProductionDashboard::DAYS` 30 and `SLOTS` 3,
  `ReadProductionMetrics::$uniqueFor` 120.
- Needs a **queue worker** for page reads (`composer run dev` has one) and the **scheduler**
  (`php artisan schedule:work`, not in `composer run dev`) for the daily snapshot.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.prod_viewed` | info | `ProductionDashboard::mount()` | `projects` (count queued) |
| `board.prod_read` | info | `ReadProductionProject` | `project`, `metrics`, `ms`, `ok` |
| `board.prod_read_failed` | warning | `ReadProductionProject` | `project`, `reason` (connection reason code, or `metrics_failed` plus `metrics`: key → reason code) |
| `board.prod_read_skipped` | info | `ReadProductionProject` | `project`, `reason` (`disabled` / `not_connected`) |
| `board.prod_read_crashed` | error | `ReadProductionMetrics` | `project`, `exception` (class only) |
| `board.prod_read_stalled` | warning | `ProductionDashboard::settle()` | `project` |
| `board.prod_refresh_requested` | info | `ProductionDashboard::refresh()` | `project` |
| `board.prod_refresh_refused` | warning | `ProductionDashboard::refresh()` | `project_id` |
| `board.prod_snapshot_run` | info | `BoardProdSnapshot` | `projects`, `ok`, `failed`, `skipped` |

**No MySQL message is ever logged**, and neither are values, hosts or credentials: a MySQL error can quote
production data. Failures log reason codes only (same rule as SB-17's `ba353b1`); the human message is shown
on the page only. SB-17's `board.prod_connection_refused` (`during=read`) also fires on a refused read.
Queued reads carry the page's `request_id`, so one visit's `prod_viewed` and each project's `prod_read`
share it; the snapshot command's lines share the command's.

Healthy visit: `prod_viewed projects=N`, then N `prod_read ok=true` under the same `request_id`. Unreachable
project: `prod_read ok=false` + `prod_read_failed reason=unreachable`. No worker: `prod_viewed` then, a minute
later, `prod_read_stalled` per project and no `prod_read`. Missing nightly point: no `prod_snapshot_run` that
night (scheduler not running).

## Testing & verification
- `tests/Feature/Board/ProductionDashboardTest.php`: one `it()` per acceptance criterion, plus the schedule
  registration, Refresh, refused Refresh, uniqueness, skipped reads, a single failing metric, the stall
  after a minute and the sidebar link. Real two-user MySQL fixture (`tests/Support/ProductionFixture.php`,
  SB-17), time frozen with `travel`.
- `tests/Browser/ProductionDashboardTest.php`: skeleton then numbers, Refresh re-reads, unreachable row keeps
  greyed last values; one card per project and no sideways scroll at 375 px. **Deviation:** "unreachable"
  is a connection to a closed local port (`127.0.0.1:1`), not a stopped fixture DB, since a test cannot stop
  the fixture database.
- Test helpers work a faked queue by hand and release each job's unique lock (RUNBOOK "Production dashboard
  tests: queued reads that never ran").
- **Not yet verified against real production.** The owner must first create coins' read-only user (SB-17).

## Key decisions & tradeoffs
- Last read outcome in the cache; rows settle by read count →
  [ADR-030](../decisions/ADR-030-production-read-outcome-lives-in-the-cache-and-rows-settle-by-read-count.md).
- Nightly snapshot reads in-process, not on the queue; the scheduler is documented, not added to
  `composer run dev` → [ADR-031](../decisions/ADR-031-daily-production-snapshot-reads-in-process.md).
- Reads are queued per project (ADR-006), so the page never waits on production.
- Changes are measured from the value's own day, not from today.
- No chart library: a 30-point line is small enough for inline SVG and keeps the token palette.
- New tokens series-1/2/3 and gain/loss (owner-approved 2026-09-29).

## Known limitations & gotchas
- **No scheduler, no nightly point** (see Overview). A day with a page visit still gets a snapshot.
- **No worker, no numbers**: rows stall after 60 s and show last good values.
- Colour slots follow alphabetical position, so adding a project can shift other rows' colours.
- The sparkline bridges a missing day with a straight line; the day keeps its spacing but no break is drawn.
- Cache clear (`cache:clear`) drops every row's error banner until its next read. Values are unaffected.
- Snapshots are never pruned (story scope).
- The sidebar's connected count is one extra query on every board page.
- `/prod` has no auth; it shows production figures. Localhost only until SB-6 adds auth.

## Change history
2026-09-29 — `/prod` comparison table, queued per-project reads, `prod_snapshots`, `board:prod-snapshot` at
23:55, sidebar link, series and gain/loss tokens (SB-18, `74e1732`)
