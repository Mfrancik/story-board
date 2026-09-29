# Production connection and metrics
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-17

## Overview
Each project on `/projects` can hold one **read-only** connection to its production MySQL database and a list
of metrics to read from it: four presets (Total users, New today, Active today, Logged in today) and custom
single-`SELECT` metrics. This story makes the connection safe to hold and lets the owner test each number;
SB-18 shows the numbers on a dashboard and keeps daily snapshots. It is the board's first read of anything
that is not git, so it goes through one gateway, `ProductionReader`, that proves the user read-only every
time it connects ([ADR-025](../decisions/ADR-025-production-reads-go-through-one-read-only-gateway.md)).

> **Localhost only.** `/projects` has no auth. Stored production credentials are one more reason SB-6
> (hosted board) must put auth in front of this page before the board runs anywhere but localhost.

## How it works
**Where it lives.** `resources/views/livewire/board/manage-projects.blade.php` gains a Production column per
row: "Not set up", or "Read-only · N metrics". Clicking it opens a panel under the row (Alpine `open`, no
round trip) holding `<livewire:board.production-settings lazy />`
(`app/Livewire/Board/ProductionSettings.php`). The panel is lazy, so nothing is decrypted or read from
production until a row is first opened. `ManageProjects::render()` only adds `withExists('prodConnection')`
and a count of enabled metrics, so drawing the list decrypts nothing. Mockup: option A
(`docs/mockups/SB-17/option-a.html`).

**The panel** (`resources/views/livewire/board/production-settings.blade.php`):
1. *Connection*: host, port, database, username, password, Use SSL (on by default). **Test connection** and
   **Save connection** both run the full check and show the result inline (`data-prod-result`): reachable
   or not, the grants found, MySQL version, SSL, time taken. Never a toast.
2. *Read-only user*: the paste-ready one-liner (below), with a Copy button.
3. *Metrics*: the four presets, each with an on/off switch, table and column inputs, **Test** and "Show SQL".
   Then custom metrics (name + SQL, **Test**, remove) and **Save metrics**. Table and column inputs are text
   fields with a `<datalist>` filled from `information_schema` by `loadSchema()` on `wire:init`. If listing
   fails, the pickers stay free text and setup still works.
4. **Remove connection** goes through `<x-board.confirm-modal>` (SB-12).

After a save or removal the panel dispatches a `prod-summary` browser event. The row's Alpine picks it up
and updates its summary. The page is not re-rendered, because re-rendering `ManageProjects` re-mounts the
lazy child and wipes the result the owner is reading (RUNBOOK).

**Actions** (`app/Actions/Board/`):
- `PrepareProdConnection::handle()` turns the form into an **unsaved clone** of the connection. A blank
  password keeps the stored one **only if host and username are unchanged**. Otherwise it refuses with
  reason `password_needed`, so a stored password is never sent to a server the owner has just typed in.
- `TestProdConnection` runs `ProductionReader::check()`, logs, and stores nothing.
- `SaveProdConnection` runs the same check and stores the connection only if it is `ok`, with `verified_at`
  set to now.
- `RemoveProdConnection` deletes the metrics and the connection in one transaction. Nothing in production
  is touched: the read-only user still exists there.
- `SaveProdMetrics` stores the four preset rows (on or off) and the custom rows in display order, deletes
  custom rows no longer in the form, and first runs every enabled metric through
  `ProductionReader::refuseMetric()`, so nothing is stored that could never run.
- `TestProdMetric` runs one metric as the form has it now (saved or not) on the **saved** connection.

**The gateway**: `app/Services/ProductionReader.php`. It is the only code that opens a production connection.
`open()` does this in order:
1. Refuses a host or database outside `DSN_SAFE` (reason `bad_target`). These are DSN text, and a `;` or `=`
   would add options such as `unix_socket`.
2. `connect()`: raw `PDO`, not Laravel's DB manager, so the connection never enters `config/` and never
   reaches query listeners or Telescope. It sets `ATTR_TIMEOUT` 5, native prepares, multi-statements off
   and `LOCAL_INFILE` off. With SSL on it sets `SSL_CA=''` and `VERIFY_SERVER_CERT=false`: the connection is
   encrypted but the certificate is not verified. `mysqlnd.net_read_timeout` is raised to 7 s only for the
   connect and then restored, because its default is a day and a host that accepts TCP but never speaks
   MySQL would hang the request.
3. Sets the session: `SET SESSION TRANSACTION READ ONLY` first, then `max_execution_time = 5000` and
   `time_zone = '+00:00'`.
4. Reads `SHOW GRANTS`, `VERSION()` and `Ssl_cipher`. `extraPrivilege()` inspects **every** grant line, not
   only those on the target database. Only `SELECT`, `SHOW VIEW` and `USAGE` pass. A granted role (listed as
   `ROLE <name>`, since its privileges are not visible), `WITH GRANT OPTION`, `PROXY`, dynamic `*_ADMIN`
   privileges and any line it cannot parse are refused. Partial `REVOKE` lines are skipped. A privilege in
   `WRITE_PRIVILEGES` gives "This user can write (X) — create a SELECT-only user." Anything else gives "This
   user can do more than SELECT (X) — …".

This runs on **every** open: save, test, schema listing and SB-18's reads. It is not trusted from the save.

**Metrics.** `sqlFor()` builds a preset as ``SELECT count(*) FROM `t` `` (or `count(DISTINCT `c`)`). For the
"today" presets it adds ``WHERE `col` >= ? AND `col` < ?``. Table and column names must match
`^[A-Za-z0-9_$]{1,64}$` and are backtick-quoted. **Today** is `today()`: local midnight in `board.timezone`
(else `app.timezone`) up to the next midnight, both converted to UTC and passed as bound values. The
production session is pinned to UTC to match. Custom SQL passes `refuseSql()` before any connection opens.
It masks strings, quoted names and comments. It then refuses more than one statement, a first word other
than `SELECT`/`WITH`, a forbidden word anywhere (`INSERT`, `UPDATE`, `DELETE`, `INTO`, `LOCK`, `CALL`, …,
which catches `WITH … DELETE`, `FOR UPDATE` and `INTO OUTFILE`), optimizer hints `/*+ */` and executable
comments `/*! */` (a hint can lift `max_execution_time`). `read()` insists on exactly one row with one
numeric column. Otherwise the result is "It must return one number — this returned N rows / N columns /
NULL / text". MySQL error 3024 counts as timed out. **So does any result that took the full 5 s**, because
`SLEEP()` under `max_execution_time` returns without an error (RUNBOOK).

**Redaction.** `redact()` replaces the stored password in any message, case-insensitively (MySQL on macOS
lower-cases names it echoes back). Every `ConnectionCheck` and `MetricReading` message is already redacted
when it leaves the reader.

## Data model
Migrations `2026_09_29_170001_create_prod_connections_table.php` and `…170002_create_prod_metrics_table.php`.
- `prod_connections` (`app/Models/ProdConnection.php`): `project_id` unique, cascade on project delete.
  `host`, `database`, `username`, `password` are TEXT with `encrypted` casts (APP_KEY), because ciphertext
  has no fixed length. `port` (default 3306), `use_ssl` (default true), `verified_at`. `password` is in
  `$hidden`, and the form clears it after a save. `target()` gives `host:port` for messages and is never logged.
- `prod_metrics` (`app/Models/ProdMetric.php`): `project_id` (cascade), `key` (a preset name, or
  `custom-<random>` that stays the same across saves so SB-18's history lines up), unique per project. Also
  `kind` `preset|custom`, `label`, `config` json (`table`, `column`, `distinct`), `sql` text, `position`,
  `is_enabled`. `ProdMetric::PRESETS` holds labels, fields and coins' real defaults. Metrics hang off the
  project, not the connection, so `RemoveProdConnection` deletes them explicitly.
- `Project::prodConnection()` (HasOne) and `Project::prodMetrics()` (HasMany).

## Interfaces
- **For SB-18**: `ProductionReader::readEnabledMetrics(ProdConnection): ProductionRead`. `->check` is a
  `ConnectionCheck`. `->readings` is `array<key, MetricReading>` in position order, read in one session.
  It is empty when the check is not ok. One failing metric does not stop the rest. The reader never writes
  the board DB.
- `ProductionReader`: `check()`, `testMetric()`, `schema()` (throws `ProductionRefusedException`), `sqlFor()`,
  `displaySql()`, `refuseMetric()`. Static helpers: `today()`, `extraPrivilege()`, `isWrite()`,
  `refuseSql()`, `redact()`.
- `App\Services\Production\ConnectionCheck`: `status` `ok|refused|unreachable|failed`. `reason` is one of
  `can_write`, `more_than_select`, `unreachable`, `access_denied`, `no_database`, `bad_target`, `failed`,
  `password_needed`. Also `message`, `privilege`, `grants`, `version`, `ssl`, `ms`, `target`, `detail`.
- `MetricReading`: `status` `ok|refused|timed_out|failed`. Also `value` (int or float), `message`,
  `reason` (`sql_refused`, `bad_name`, `incomplete`, `not_one_number`, `timed_out`, `query_failed`,
  `no_connection`, or a connection reason), `ms`, and `sql` as shown.
- Exceptions: `ProductionRefusedException` (carries the `ConnectionCheck`) and `ProdMetricsRefusedException`
  (`field` such as `customs.2.sql`, `reason`).
- Livewire `ProductionSettings`: `testConnection`, `save`, `remove`, `testPreset(key)`, `testCustom(index)`,
  `addCustom`, `dropCustom(index)`, `saveMetrics`, `loadSchema`. Browser event `prod-summary`
  `{project, connected, metrics}`.
- Blade `<x-board.metric-result>`: one metric's last Test result.
- Test hooks: `data-prod-toggle`, `data-prod-state`, `data-prod-panel`, `data-prod-test`, `data-prod-save`,
  `data-prod-result`, `data-prod-remove`, `data-prod-remove-modal`, `data-preset`, `data-custom`,
  `data-prod-save-metrics`, `data-prod-ssl`.

### The read-only user (owner creates it on the production server)
Paste-ready. Swap in the database name and a strong password:

```sql
CREATE USER 'board_ro'@'%' IDENTIFIED BY '<strong-password>' REQUIRE SSL; GRANT SELECT ON `coins`.* TO 'board_ro'@'%';
```

- `REQUIRE SSL` is optional. Keep it when the board's **Use SSL** is on (the default). Drop it if the server
  has no TLS.
- Prefer the board's address to `'%'` where you can, e.g. `'board_ro'@'203.0.113.7'`.
- Grant nothing else. `SHOW GRANTS` must show only `USAGE ON *.*` plus `SELECT` (or `SHOW VIEW`). Any other
  privilege on any database, or any granted role, is refused.
- The panel shows a shorter version (no `REQUIRE SSL`) filled in with the typed database name.

## Configuration
- `board.timezone` = `env('BOARD_TIMEZONE', 'America/New_York')` in `config/board.php`. This is the owner's
  "today" (owner-approved 2026-09-29; `app.timezone` stays UTC).
- `APP_KEY` encrypts the stored credentials. **Rotating it without re-encrypting makes stored connections
  unreadable.** Remove and re-add them.
- Fixed in code: `ProductionReader::TIMEOUT_SECONDS` = 5 (connect and `max_execution_time`); the connect
  read timeout is that plus 2 s.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.prod_connection_tested` | info | `TestProdConnection` | `project`, `result`, `reason`, `privilege`, `duration_ms` |
| `board.prod_connection_saved` | info | `SaveProdConnection` | `project`, `created`, `duration_ms` |
| `board.prod_connection_refused` | warning | `ProductionReader::refuse()`, `PrepareProdConnection` | `project`, `reason`, `privilege`, `during` (`check\|metric\|read\|schema`) |
| `board.prod_connection_removed` | warning | `RemoveProdConnection` | `project`, `metrics` |
| `board.prod_connection_remove_refused` | info | `ProductionSettings::remove()` | `project`, `reason: none` |
| `board.prod_metric_tested` | info | `TestProdMetric`, `ProductionSettings` | `project`, `metric` (key), `kind`, `result`, `reason`, `error` (only `query_failed`, redacted), `duration_ms` |
| `board.prod_metrics_saved` | info | `SaveProdMetrics` | `project`, `enabled`, `custom` |
| `board.prod_metrics_refused` | warning | `SaveProdMetrics` | `project`, `reason`, `field` |

Host, database, username, password and metric values are never logged, and neither is the SQL. Every line
carries `request_id`. A healthy save is one `board.prod_connection_saved` with no preceding `_refused`
under the same `request_id`. A save refused for privileges is `board.prod_connection_refused`
`reason=can_write privilege=INSERT during=check`. An unreachable host shows `reason=unreachable` within
about 5 to 7 s. A refused custom SQL shows `board.prod_metric_tested result=refused reason=sql_refused`
with no production connection.

## Testing & verification
- `tests/Feature/Board/ProductionConnectionTest.php`: one `it()` per acceptance criterion, plus extras. The
  extras cover a changed host asking for the password again, Remove and Test with no connection, a
  `bad_target` host, `readEnabledMetrics()` order and its empty result on refusal, unsafe preset names,
  Save metrics, and the row summary.
- `tests/Unit/ProductionReaderTest.php`: `extraPrivilege()`, `isWrite()`, `refuseSql()` datasets and `redact()`.
- `tests/Browser/ProductionSettingsTest.php`: a writing user refused inline, a read-only one saved, Total
  users tested, Remove only after confirming, and no sideways scroll at 375 px.
- `tests/Support/ProductionFixture.php`: a real second MySQL database, `sb17_prod_<hash>`, plus users
  `sb17ro_` (SELECT), `sb17rw_` (SELECT + INSERT) and `sb17al_` (ALL), named per checkout and per test DB.
  They are created once per process and dropped in `afterAll` using the stored root login, not `config()`
  (RUNBOOK). Never a real production database.
- The timeout test uses a four-way cross join of `information_schema.COLLATIONS`, which raises error 3024.
  `SLEEP()` would not (RUNBOOK).
- **Not yet verified:** the story's real browser check on coins' production with the owner's read-only user
  (test passes, Total users and Active today show numbers, and an INSERT user is refused). Owner action.

## Key decisions & tradeoffs
- One read-only gateway for production, built on raw PDO with the session locked down. This amends
  "reads from git only" → [ADR-025](../decisions/ADR-025-production-reads-go-through-one-read-only-gateway.md).
- The grants check is stricter than the story asked. It covers every grant line and refuses roles and
  unparseable lines. False refusals are cheap (make a narrower user). A false pass is not.
- Custom SQL is guarded twice: by `refuseSql()` before connecting, and by a read-only session with
  multi-statements off on the server side.
- The row summary is updated by a browser event, not a parent re-render (see How it works).
- Deviations from the story or mockup: the column is `use_ssl`, not `ssl`, because booleans read as
  questions. Preset pickers are text inputs with `<datalist>`, not selects, so setup works when table
  listing fails. There are extra log events (`prod_metrics_saved|refused`, `prod_connection_remove_refused`,
  `password_needed`).

## Known limitations & gotchas
- **SSL is encrypted but not verified.** The password stays off the wire, but an impostor server holding
  the DNS name or IP would get it. A per-server CA is not supported.
- **No auth on `/projects`**, which now holds production credentials. It is localhost-only. SB-6 must add
  auth first.
- Metrics run on the **saved** connection, so save the connection before testing metrics.
- Changing host or username means typing the password again. This is intended.
- "Today" presets assume production stores UTC timestamps. `DATE` columns are listed as timestamps too and
  compare against a UTC datetime range.
- A slow but legitimate query that finishes in about 4.95 s or more is reported as timed out.
- A failed custom query's own error text is logged in `error` (redacted of the password). MySQL errors can
  quote table and column names.
- `mount()` uses `$project->withoutRelations()`. The parent's instance can carry a stale `prodConnection`.
- Rotating `APP_KEY` breaks stored credentials (see Configuration).

## Change history
2026-09-29 — Production panel on `/projects`: read-only connection with grants proof, presets and custom SQL metrics, `ProductionReader` gateway, `board.timezone` (SB-17, `c8bcfbe`)
