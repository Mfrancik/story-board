# Codebase & Database Standards
Read before writing any PHP or touching the database. These are the choices
Laravel leaves open, pinned down. Where this doc is silent, follow Laravel's
own conventions exactly.

## Architecture
- **Skinny controllers.** Controllers validate (via Form Request), call an
  Action, return a response. No business logic, no queries.
- **Actions** in `app/Actions/<Domain>/` — one class, one verb, one `handle()`
  method (`CreateInvoice`, `ArchiveUser`). All business logic lives here so it's
  testable and reusable from controllers, Livewire, jobs, and commands alike.
- **Form Requests** for all validation of controller input. Livewire components
  validate with `#[Validate]` attributes or `rules()`.
- **Livewire components** in `app/Livewire/<Domain>/`. One responsibility each.
  Components call Actions for writes — no business logic in components.
- Eloquent conventions strictly: singular model names, standard foreign keys
  (`user_id`), relationships defined on both sides, `$fillable` explicit.
- **No raw SQL** unless Eloquent/Query Builder genuinely can't express it —
  and then it gets a comment justifying why.
- Query performance: eager-load relationships used in views (`with()`); no
  queries inside Blade loops. N+1s found in review are bugs.

## Database (MySQL 8 — every environment)
- `DB_CONNECTION=mysql` locally, in CI, and in production. SQLite is banned —
  including for tests (`phpunit.xml` points at a MySQL test database), so what
  we test is what we ship.
- Schema changes ONLY through migrations. Never edit a migration that has run;
  write a new one. Every migration has a working `down()`.
- Naming: tables plural snake_case; pivot tables alphabetical singular
  (`project_user`); columns snake_case; booleans read as questions
  (`is_active`, `has_paid`).
- Every foreign key is a real constraint (`foreignId()->constrained()`) with an
  explicit `onDelete` decision — cascade or restrict is a choice, not a default.
- Index anything queried in a WHERE or ORDER BY on a large table. Add indexes
  in the same migration as the column when the query pattern is known.
- Money is stored as integer minor units (cents), never floats.

## Comments (mandatory)
- Docblock on every class: one sentence on its purpose in the system.
- Docblock on every public method: what it does, params/return where not
  obvious from types, and any side effects (writes, events, external calls).
- Inline comments explain **WHY** — the intent, constraint, or gotcha — never
  narrate what readable code already says.
- Complex conditionals get a one-line plain-English translation above them.
- TODOs must reference a story ID or they don't ship.

## Docs assert facts — never restate what a machine can read
Read this before writing or updating ANY doc (feature docs, RUNBOOK, ADRs,
standards, story files).
- Never restate in a doc a fact a machine can already read — git history,
  `phpunit.xml`, `.env`, `config/`, migrations. Reference it, derive it, or gate
  it with a check. A copied fact has no owner and drifts silently: a story saying
  `Status: draft` while its `feat|fix|test(<ID>)` build commit is on main, a
  RUNBOOK naming a database that no longer exists, a standards doc claiming tests
  run that have never run.
- If a fact MUST be restated in prose, it is **unverified** until a check
  confirms it against its source of truth. Write the check, or drop the claim.
  (Status vocabulary lives in `stories/README.md §Status`; its check is the
  story-status gate in `preflight.sh`.)
- **Index rows are pointers.** A `docs/INDEX.md` row is
  `name | one sentence ≤ 200 chars | status | date | link`; a `docs/UI-INVENTORY.md`
  row is `component | type | one-line purpose | used in`. Edit the row in place
  (one feature, one row, keyed by its link); move anything longer into the
  feature doc — never delete it, never append it to the row. The indexes are read
  by every scoped preflight audit, so a byte added to a row is paid on every run
  forever. Check: `preflight.sh` "docs rows are pointers" (warn-only, 300/600 B).

## Errors & exceptions
- Throw domain exceptions from Actions (`app/Exceptions/`); catch at the edge
  (controller/Livewire) and translate to user-facing messages.
- Never swallow exceptions silently. Catch → log with context → rethrow or
  handle deliberately.

## Testing (Pest)
- Every story ships feature tests mirroring its acceptance criteria, named so
  they read as the criteria: `it('blocks guests from the dashboard')`.
- Feature tests hit real routes and the MySQL test DB (`RefreshDatabase`).
  Unit tests cover Actions with logic worth isolating.
- **Every guard gets a test and a log line.** Any early exit on rejected input —
  `abort*`, a thrown domain exception, a `continue`/`return` that skips work —
  ships in the same commit with a test that triggers it and a log line saying why
  (logging-standards §What must be logged). List the guards in the diff before
  calling the story done; the happy path is not the whole contract.
- Factories for all models; states for meaningful variants (`suspended()`).
- A skipped test needs a comment with a story ID and a reason.
- **Journey tests** live in `tests/Browser/Journeys/` using Pest's browser
  testing plugin (Playwright under the hood), so the whole suite — unit,
  feature, and journeys — runs under one `php artisan test`.
- **Changing how tests EXECUTE** — parallelism, isolation traits, seeding
  strategy, suite splits — is verified by diffing the SET of test identities
  and statuses, before against after: `php artisan test --log-junit
  before.xml`, apply the change, re-run to `after.xml`, diff the two. An
  aggregate count and a green light are not evidence; they hide the two
  failure modes that matter — a test that silently stops running, and a test
  that flips status. A red→green flip is as suspicious as green→red:
  infrastructure has no business fixing a test.

## Tooling gates (enforced by preflight)
- `vendor/bin/pint` clean · `vendor/bin/phpstan analyse` clean (Larastan,
  level 6+) · `php artisan test` green · no `dd()`/`dump()`/`ray()` committed.
