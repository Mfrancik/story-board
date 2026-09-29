# ADR-025 — Production reads go through one read-only gateway (amends "reads from git only")

Date: 2026-09-29 · Status: accepted · Amends: ADR-004

## Context

Until SB-17 the board read only git, and all of it went through `GitReader`
([ADR-004](ADR-004-gitreader-read-only-git-gateway.md)). The owner now wants live production figures
(users, sign-ups, activity) in one place. That means the board holds credentials to real production
databases and sends SQL to them. Owner requirement: it must never be able to change them.

A database user the owner *meant* to be read-only is not proof. Grants drift, roles can carry writes, and
custom SQL can hide a write (`WITH … DELETE`, `SELECT … INTO OUTFILE`, `FOR UPDATE`, a `/*! */` comment).
The board's own tooling (query log, Telescope, exception reports) also sees everything that goes through
Laravel's DB manager, so production SQL and results could leak into the board's logs.

## Decision

The board may read production, **read-only**, through one gateway. `app/Services/ProductionReader.php`
is the only code that opens a production connection, just as `GitReader` is the only code that runs git.
Every open, whether save, test, schema listing or SB-18's reads, does all of the following:

- **Raw PDO built at runtime**, not a connection in `config/database.php`. It never enters the DB manager,
  so it never reaches query listeners or logs. Connect timeout 5 s, native prepares, multi-statements
  off, `LOCAL_INFILE` off, and `mysqlnd.net_read_timeout` held to 7 s only while connecting. Host and
  database are refused if they could inject DSN options.
- **Locked session**: `SET SESSION TRANSACTION READ ONLY` before anything else, `max_execution_time` 5 s,
  UTC.
- **Proof, not trust**: `SHOW GRANTS` on every open. Any line granting more than `SELECT`, `SHOW VIEW` or
  `USAGE` anywhere is refused, and so is any granted role or line that cannot be parsed.
- **SQL guard before the wire**: custom SQL must be one `SELECT` / `WITH … SELECT` with no forbidden words,
  hints or executable comments. Preset identifiers must be plain names and are backtick-quoted.
- **Redaction**: the password is scrubbed, case-insensitively, from every message the gateway returns.
  Credentials, SQL and values are never logged.

Credentials are stored encrypted (`encrypted` casts, APP_KEY). The owner creates the read-only user; the
board never creates or changes users.

Alternatives rejected:
- **A named connection in `config/database.php`, switched at runtime.** Idiomatic, but it puts production
  inside the DB manager. Its queries reach listeners and debug tooling, and any code could call
  `DB::connection('prod')`, which removes the single choke point. It also means touching `config/`.
- **Trust the user's grants as saved.** Cheaper (no `SHOW GRANTS` per read), but grants change on the
  server without the board knowing. Re-checking costs one round trip per session.
- **Check only grants on the target database.** This is what the story asked. It was rejected in the
  build, because a user with `INSERT` on another schema or a role can still write. The board wants a
  user that can write nowhere.
- **Presets only, no custom SQL.** Safer, but the owner asked for custom metrics. The guard plus the
  read-only session is defence in depth: the server refuses writes even if the guard misses one.

## Consequences

- "The board reads from git only" is now "the board reads git, and production read-only through
  `ProductionReader`". It still writes nothing outside its own database.
- A new production need means changing `ProductionReader`, which is a visible, reviewable diff. SB-18
  consumes `readEnabledMetrics()` and must not open its own connection.
- Some legitimate but broader users are refused (a role that only holds SELECT, a user with SELECT
  plus `PROCESS`). The fix is a narrower user, which is the point.
- SSL is encrypted but not verified (no per-server CA). The password is off the wire, but an impostor
  server is not stopped. This is a known limitation.
- `/projects` now guards production credentials with no auth. That is acceptable only while the board
  is localhost-only. SB-6 must add auth before hosting.
