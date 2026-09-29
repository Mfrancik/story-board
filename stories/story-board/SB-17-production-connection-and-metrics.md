# SB-17 — Production connection and metrics
Status: built          Journey: none
Source: owner 2026-09-29 (/story): *"id also like to have a dashboard report that connects to production (i can
create a user), ideally a read only db user that can query things like number of users, users logged in today,
etc.. so i have a centralized place to see all of that."* Owner answers: credentials encrypted in the board DB;
metrics are presets plus custom SQL; a daily snapshot keeps history (SB-18).

## Story
As the owner, I want to give a project a read-only production database connection and choose which numbers to
read from it, so that the board can show me live production figures without ever being able to change them.

## Why
The board today reads only git. Production numbers (users, sign-ups, activity) live in each app's production
database, and checking them means a DB client per project. This story makes the connection safe to hold; SB-18
shows the numbers.

## In scope
- On `/projects`, each project row gains **Production**: add, edit or remove one connection — host, port,
  database, username, password, SSL on/off (on by default).
- **Credentials are stored encrypted** in the board's database (Laravel `encrypted` cast, APP_KEY). The password
  is never rendered back into a form, a log or an error; the edit form shows "saved — leave blank to keep".
- **Read-only is proven, not trusted.** Saving connects, runs `SHOW GRANTS`, and refuses the connection unless the
  user's privileges on the database are only `SELECT` (plus `SHOW VIEW`, `USAGE`). A user with `INSERT`, `UPDATE`,
  `DELETE`, `ALL`, `CREATE`, `DROP`, `ALTER`, `GRANT OPTION` or any global write privilege is refused, and the
  form says which privilege was found. The check re-runs on every read (SB-18), not only on save.
- **Every production query** runs on a connection that is built at runtime (not in `config/database.php`), with
  `SET SESSION TRANSACTION READ ONLY`, `max_execution_time` 5 s, and a 5 s connect timeout.
- **Metrics per project**, two kinds:
  - **Presets** you switch on and point at a table/column, with a Test button that shows the value:
    Total users (`count(*)` of a table, default `users`) · New today (`count(*)` where a timestamp column, default
    `created_at`, is today) · Active today (where a timestamp column, e.g. coins' `users.last_seen_at`, is today)
    · Logged in today (distinct `user_id` of an events table where a timestamp is today, e.g. coins'
    `user_login_events.occurred_at`, or a column such as `users.last_login_at`).
  - **Custom**: a name plus one `SELECT` (or `WITH … SELECT`) that returns a single number. One statement only;
    anything else is refused before it runs. Test shows the value or the error.
- "Today" means the owner's timezone (`app.timezone` / `board.timezone`), converted to the production DB's UTC
  timestamps in the query.
- A **Test connection** button reports: reachable or not, the privilege check, the MySQL version, and the time taken.

## Out of scope (do NOT build)
- The production dashboard page and snapshots (SB-18).
- Any database other than MySQL. SSH tunnels. Anything that writes to production.
- Creating the read-only user. The owner does that; the story's doc gives the paste-ready `CREATE USER … GRANT
  SELECT` one-liner.
- Auth for `/projects`. It stays localhost-only; SB-6 (hosted) must add auth before this ships beyond localhost.

## Acceptance criteria (executable — these become the Pest test names)
- Given valid read-only credentials, when the connection is saved, then it is stored with host, database,
  username and password encrypted at rest, and the password never appears in the rendered page.
- Given credentials whose grants include `INSERT`, when saved, then it is refused with "this user can write
  (INSERT) — create a SELECT-only user", and nothing is stored.
- Given `GRANT ALL`, then it is refused the same way.
- Given an unreachable host, then Test connection says "unreachable" within 6 s and nothing hangs.
- Given the edit form with the password left blank, when saved, then the stored password is kept.
- Given a custom metric `SELECT count(*) FROM users`, when Test is pressed, then it shows the number.
- Given a custom metric containing two statements, or starting with `UPDATE`, `DELETE`, `INSERT`, `DROP`, `SET`
  or `CALL`, then it is refused before it reaches the database.
- Given a custom metric returning two columns or two rows, then it is refused with "must return one number".
- Given a query running longer than 5 s, then it is stopped and reported as "timed out".
- Given the preset Active today pointed at `users.last_seen_at`, then Test shows the count of users seen since
  local midnight.
- Given a production error message containing the password, then the logged and displayed message has it redacted.
- Given the connection is removed, then its credentials and metrics are deleted from the board DB.
- Given a save, test or removal, then `board.prod_connection_saved|tested|removed` is logged with `project` and,
  for a refusal, `reason` — never host credentials.

## Applicable standards
- Design: the `/projects` row actions and `board/confirm-modal` (removal) patterns from SB-12; the form
  follows the Manage projects form style. Test results show as inline status, not a toast.
- Codebase/DB: new tables `prod_connections` (project_id unique, host, port, database, username, password —
  encrypted casts, ssl, verified_at) and `prod_metrics` (project_id, key, kind preset|custom, label, config json,
  sql text null, position, is_enabled). A `ProductionReader` service is the only code that opens a production
  connection (the same single-gateway rule as `GitReader`, ADR-004): runtime connection, read-only session,
  timeouts, grants check, one-statement guard. New ADR: the board reads production, read-only, through one
  gateway — an amendment to "reads from git only".
- Logging: `board.prod_connection_saved`, `board.prod_connection_tested`, `board.prod_connection_refused`
  (warning; `project`, `reason`), `board.prod_connection_removed`, `board.prod_metric_tested`. Credentials and
  SQL results are never logged.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/SB-17/option-{a,b,c}.html (the /projects page with a Production connection form, the
  privilege-check result, and the metrics setup with presets and a custom SQL row; coins as the example)
- Chosen option: a
- Why I chose it: owner pick 2026-09-29 (/story mockup gate), no reason given. Direction: inline row: a Production column on /projects that opens a panel under the row: connection form and test result, the one-liner, then the metrics list with SQL behind "Show SQL".

## Do NOT touch
- `config/database.php` and anything else in `config/` (runtime connection only; a `board.timezone` key in
  `config/board.php` needs the owner's OK at build time). `app/Services/GitReader.php`. Any registered
  project's files. Any production database beyond `SELECT`.

## Data & interfaces
- Schema/migrations: two new tables (above). No change to existing tables.
- Livewire: `ManageProjects` gains the Production panel, or a child `ProductionSettings` component per project.
  Service: `ProductionReader`. `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST. Tests use a second local MySQL database with
  two users created in the test setup (one SELECT-only, one with INSERT) — never a real production DB.
- Journey test: none.
- Browser check: add coins' production connection with the owner's read-only user; Test connection shows the
  grants check passing; switch on Total users and Active today (`users.last_seen_at`) and see numbers; try a user
  with INSERT and see the refusal.
- Checked against real data (2026-09-29): coins has `users.last_login_at`, `users.last_seen_at` (indexed) and a
  `user_login_events` table (`user_id`, `occurred_at`); its sessions use the database driver.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document (includes the paste-ready read-only user one-liner)

## Links
Journey: none · Depends on: SB-12 (manage projects) · Next: SB-18
