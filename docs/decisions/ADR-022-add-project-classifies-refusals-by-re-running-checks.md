# ADR-022 — Add project names a refusal by re-running RegisterProject's checks, not by parsing its message

Date: 2026-09-29 · Status: accepted

## Context

SB-12's Add project form must show each refusal under its own field (Path, Name or Ref) and log
`board.project_add_refused` with a stable `reason` (`not_a_repo | duplicate | bad_ref | missing_path`).
The story requires `RegisterProject`, which `board:project add` already uses, to be reused unchanged for
validation. It throws one `ProjectRegistrationException` whose message is a sentence for the CLI.

## Decision

`app/Actions/Board/AddProject.php` wraps `RegisterProject`. On a `ProjectRegistrationException`,
`classify()` re-runs the checks in `RegisterProject`'s own order — ref (`GitReader::isValidRef`), folder
exists (`realpath`), is a repository (`GitReader::isRepository`), then duplicate by path, then by name —
and throws a `ProjectAddRefusedException` carrying `field`, `reason` and the owner-facing message.

A blank path is refused before `RegisterProject` is called, because `realpath('')` is the current
directory (see RUNBOOK, 2026-09-29).

Alternatives rejected:
- **Parse the exception message.** Couples the form to CLI wording; a reworded sentence would silently
  misfile a refusal.
- **Give `RegisterProject` typed reasons.** Cleaner, but out of the story's scope (reuse unchanged), and
  it would change the CLI's code path for no CLI benefit.
- **Validate in the component before calling `RegisterProject`.** Duplicates the rules in two places
  that could drift; here `RegisterProject` still decides, and `classify()` only names the outcome.

## Consequences

- The checks now exist twice: `RegisterProject` decides, `classify()` explains. If `RegisterProject`
  gains a check or changes its order, `classify()` must follow, or the reason will be wrong (it falls
  through to `duplicate`). The one-`it()`-per-refusal tests in `ManageProjectsTest` catch that.
- A refusal costs one extra `isRepository` git call and up to two queries. It happens only on a failed add.
