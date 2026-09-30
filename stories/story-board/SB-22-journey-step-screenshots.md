# SB-22 — Journey tests save a screenshot per step
Status: draft          Journey: none
Source: owner 2026-09-29 (/story): screenshots for the gallery's "current version" (SB-23) and the app map (SB-24)
come from each project's Playwright journey tests, which already walk the flows with seeded dummy data (owner pick:
"Journey tests capture them").
Scope: PROMOTE — the helper belongs in the dev-standards kit so every project gets it on sync; built and proven here
first.

## Story
As the owner, I want each journey test to save a screenshot of every step it walks, so that the board can show what
each page looks like today without running the app against my real data.

## Why
Journey tests are the one place that already visits every screen in order, logged in, with dummy data. Capturing
there costs one helper call per step and no extra work for build sessions.

## In scope
- A Pest browser helper, `journeyStep(string $journey, string $step, string $route)`, called at each step of a
  journey test: saves a PNG (desktop width, plus 375 px) and appends to a manifest.
- Output, outside git: `<project>/storage/app/journey-shots/<journey>/<nn>-<step>.png` and `manifest.json`
  — a JSON list, one entry per PNG: `journey`, `step`, `route`, `story` if the journey doc names one, `file` (the PNG's
  bare name, relative to the manifest's folder), `width` (the viewport width it was captured at — 375 for the phone
  shot), `captured_at`, `commit`. `storage/app/journey-shots/` is gitignored. This shape is what SB-24's
  `ReadJourneyShots` reads (it treats `width` ≤ 480 as the phone shot and prefers desktop); do not change it here
  without changing that reader.
- Capture is on only when `JOURNEY_SHOTS=1` (off in normal runs, so preflight stays as fast as it is).
- Adopted in story-board's own journey tests (currently none exist under `tests/Browser/Journeys/` — this story adds the
  helper and one example use; coins is the first real consumer after the kit sync).
- A doc for the kit: how to add `journeyStep()` to a journey test.

## Out of scope (do NOT build)
- Board pages that show the shots (SB-23, SB-24). Changing any other project's tests (they get it via the kit).
- Visual-regression diffs. Committing screenshots to git.

## Acceptance criteria (executable — these become the Pest test names)
- Given `JOURNEY_SHOTS=1`, when a journey test calls `journeyStep('sign-up', 'verify', '/verify')`, then a PNG exists
  at `journey-shots/sign-up/NN-verify.png` and the manifest lists it with route, commit and time.
- Given a captured step, then the manifest has two entries for it — desktop and 375 px — each with `file` set to the
  PNG's bare name and `width` set to its capture width.
- Given the manifest a real capture wrote, when SB-24's `ReadJourneyShots` reads it, then every shot is found and the
  375 px one is classed as the phone shot (round-trip test, no hand-written fixture).
- Given `JOURNEY_SHOTS` unset, then no file is written and the test runs as before.
- Given a second run, then the journey's shots and manifest entries are replaced, not appended twice.
- Given a step name with spaces or slashes, then the file name is slugged and stays inside the journey folder.
- Given the shots folder, then `git status` shows nothing new (gitignored).

## Applicable standards
- Design: n/a.
- Codebase/DB: no schema change. Helper in `tests/Support/`; gitignore entry. Proof per CLAUDE.md: a kit change is
  proven before it is pulled in.
- Logging: none (test tooling).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- n/a — non-visual

## Do NOT touch
- `preflight.sh`, `bin/preflight-meter.py`, `config/`, any registered project's files.

## Data & interfaces
- New on-disk format: `storage/app/journey-shots/<journey>/manifest.json` + PNGs (fixed by this story; SB-23/24 read it
  through `App\Actions\Board\ReadJourneyShots` — see docs/features/app-map.md for the reader's accepted keys).

## Test plan
- Pest: failing tests first, a tiny throwaway route walked by a test journey.
- Journey test: none (adds the helper, not a journey).
- Browser check: `JOURNEY_SHOTS=1 php artisan test tests/Browser/...` → open the PNGs.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: n/a — non-visual
- [ ] Journey test(s) green end-to-end (n/a)
- [ ] Logging events in place per standard (none)
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document (incl. the kit adoption note)

## Links
Journey: none · Feeds: SB-23, SB-24
