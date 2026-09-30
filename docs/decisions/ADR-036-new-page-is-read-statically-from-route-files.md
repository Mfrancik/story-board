# ADR-036 — "New page" is read statically from a project's route files

Date: 2026-09-30 · Status: accepted · Story: SB-23

## Context

The mockup viewer's Current pane must never be blank. With no journey shot for the story's Where, it has
to say either "No current version — new page" (the story creates the page) or "No journey test covers this
route yet" (the page exists, nobody screenshotted it). Telling them apart means knowing the routes the
project's Laravel app defines. The board never runs a project's code (it reads git, read-only), and
`GitReader` was on the story's Do-NOT-touch list.

## Decision

- `ReadProjectRoutes` reads `routes/*.php` at the set's commit through the existing `GitReader`
  (`listFiles` + `showMany`) and tokenises them with `token_get_all()`. It follows `prefix()` chains into
  nested group closures, reads the common registrar calls (`Route::get` … `resource`, `Volt::route`), and
  gives `routes/api.php` Laravel's default `api` prefix. Cached per project and commit for a day.
- **"New page" needs positive evidence.** It is claimed only when the files were read, at least one route
  was parsed, and none matches the Where (either side may be a `{param}` pattern). A git failure (logged as
  `board.project_routes_unreadable`), an empty parse, or a story with no Where all fall back to "uncovered".
  A wrong "uncovered" costs nothing; a wrong "new page" tells the owner a page does not exist.

Alternatives rejected:
- **`php artisan route:list --json` in the project.** Exact, but it executes the project's code (and needs
  its dependencies, env and database config) from the board. That breaks the board's read-only posture.
- **Only two states (shot / none).** Simpler, but the story requires the new-page wording, and "none"
  hides whether a journey test is missing.

## Consequences

- Static reading misses routes registered in service providers, loops, variable or concatenated URIs, and
  packages, so an existing page can read as "new page". On coins it read 220 URIs and got `/grading` right.
- A new registrar shape needs a line in `ReadProjectRoutes::VERBS` or its parser.
- The same reader is available to any later feature that needs a project's route list without running it.
