# ADR-050 — The old Home is unrouted and tested at a test-only route until RD-9

Date: 2026-10-11 · Status: accepted · Story: RD-5

## Context

RD-5 points `/` at the new `Overview`. The SB-9 `Home` component is on RD-5's Do-NOT-touch list and is deleted in
RD-9. Many feature and browser tests (story modal, old sidebar, all-projects dashboard, mockup links) reached it
through `/`.

## Decision

- `Home` stays in the code, unrouted.
- `tests/Pest.php:oldHome()` registers `/test/old-home` (name `test.old-home`) on first use, with the same
  `RefreshStaleProjects` middleware `/` gave it, and returns that URL plus an optional query. The old tests call
  `oldHome()` instead of `/`.
- One assertion is weakened: the old sidebar's "All projects" entry is no longer checked for `aria-current` on
  `/`, only for being present (`AppShellTest`). `ReskinKeptPagesTest`'s second leg starts its navigation with
  `Livewire.navigate('/p/fx')`, because no shell link leads to an old page any more.

Alternatives rejected:
- **Delete Home and its tests now.** That is RD-9's scope, and the component is RD-5's Do-NOT-touch.
- **Keep Home at a real route (e.g. `/old`).** It would ship a page the redesign is retiring and invite links to it.

## Consequences

- The old dashboard keeps its coverage until RD-9 deletes it, together with `oldHome()`.
- `/test/old-home` exists only inside a test run.
