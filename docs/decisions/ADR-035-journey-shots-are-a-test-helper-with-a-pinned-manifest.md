# ADR-035 — Journey shots are a test helper with a pinned manifest

Date: 2026-09-30 · Status: accepted · Story: SB-22

## Context

The app map (SB-24) and the gallery's Current pane (SB-23) need screenshots of each built screen. The
board must never run a project against real data, and the owner picked "journey tests capture them":
those tests already walk every screen in order, logged in, on seeded dummy data. The helper belongs in
the dev-standards kit so every project gets it on sync, so it cannot depend on story-board's own code.
`ReadJourneyShots` (SB-24) already existed and accepted several manifest shapes, waiting for this story to
pin one.

## Decision

- **A test helper, `journeyStep()`, called in place of `visit()`.** It takes an optional `$page`, so a
  step reached by a click or a form is captured where the test already is, and it always returns the page.
  Adoption is one file (`tests/Support/JourneyShots.php`) plus a three-line wrapper in `tests/Pest.php`.
- **Off unless `getenv('JOURNEY_SHOTS') === '1'`.** Only the process environment, not `env()` or
  `$_SERVER`. The shell variable is the single switch, a stray `.env` line cannot turn capture on in
  preflight, and a test can turn it off with `putenv()`.
- **Two full-page PNGs per step at fixed viewports** (1280×800, 375×812). Pest shoots at `scale: css`
  (its own default, `Playwright/Page.php:screenshotOptions()`), so the image width equals the manifest
  `width`; the tests check it with `getimagesize()`. The viewport is restored afterwards so later assertions are unaffected.
- **Through Pest's screenshot folder.** Pest writes screenshots only into `tests/Browser/Screenshots/`
  (`Screenshot::path()`, `@internal`). The helper saves there under a random name, moves the file out and
  removes the folder only if it is empty.
- **The manifest shape is a contract with `ReadJourneyShots`:** a JSON list, one entry per PNG, with
  `journey`, `step`, `route`, optional `story`, bare `file`, `width`, `captured_at` and optional `commit`.
  Neither side changes without the other.
- **A run is one PHP process.** A journey's first step in a process clears its PNGs and manifest, so a
  re-run replaces them instead of appending.
- **Story lookup is local and simple:** first story ID on a flow line or table row naming the route as a
  code span, else the item numbered like the step. It is not the board's `ReadJourneyMap`, because the
  kit helper cannot depend on it.

Alternatives rejected:
- **A separate crawler or artisan command that screenshots routes.** It would need its own logins and
  seed data, which journey tests already have.
- **Screenshots only on failure / Pest's default path.** Pest's folder is test debris, not a stable
  place the board can read, and it is not per journey.
- **Committing screenshots.** They churn on every run and bloat history. Out of scope by the story.
- **Reusing `ReadJourneyMap` for story lookup.** It would tie the kit helper to story-board.

## Consequences

- Capture breaks if Pest renames or moves its internal `Screenshot` helpers (see RUNBOOK).
- Shot folders must be named like the journey doc for the board to match them.
- Parallel test runs with capture on would clear each other's journeys.
