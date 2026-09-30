# SB-23 — Current version in the mockup compare
Status: built           Journey: none
Source: owner 2026-09-29 (/story): *"shows me a current version (if that exists, then i can see the two mockups side
by side in a single screen."* Screenshots come from journey tests (SB-22, owner pick).

## Story
As the owner, I want the mockup viewer to show today's version of the page next to a mockup option, so that I can
see what would change before I pick.

## Why
A mockup alone doesn't show the delta. The page the story changes usually already exists and was screenshotted by
its journey test.

## In scope
- The SB-21 compare gains a **Current** pane: the newest journey shot (SB-22 manifest) whose route matches the story's
  Where (exact, then by route pattern, e.g. `/p/{project}/preflight`), with its capture time and commit.
- Compare choices: Current vs an option (default when a current shot exists), or option vs option.
- No match → the pane says "No current version — new page" or "No journey test covers this route yet", never blank.
- A stale shot (older than the project's ref commit it was captured at by more than N commits, or 14 days) is labelled
  "captured <date>, may be out of date".

## Out of scope (do NOT build)
- Capturing screenshots (SB-22). The app map (SB-24). Live iframes of running apps.

## Acceptance criteria (executable — these become the Pest test names)
- Given a manifest shot for `/p/coins/preflight` and a story whose Where is that route, then the compare opens with
  Current on the left and option a on the right, with the shot's capture time.
- Given no shot for the route, then the Current pane shows "No journey test covers this route yet".
- Given a story for a new page (Where names a route with no shot and no existing route), then it shows
  "No current version — new page".
- Given a shot older than 14 days, then it is labelled as possibly out of date.
- Given a shot path in the manifest outside `journey-shots/`, then it is refused and logged.
- Given the Current pane renders, then `board.mockup_viewed` includes `current: true|false`.

## Applicable standards
- Design: the SB-21 chosen option's compare layout; tokens are law.
- Codebase/DB: no schema change. Shots are read from the project's working tree (`storage/app/journey-shots/`), not
  git — they are gitignored; reader is read-only and whitelists by the manifest. Served as images from a board route.
- Logging: extend `board.mockup_viewed` with `current`; `board.journey_shot_refused` (warning; `project`, `path`).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: covered by docs/mockups/SB-21/option-{a,b,c}.html (each includes the Current pane)
- Chosen option: follows SB-21's pick
- Why I chose it: —

## Do NOT touch
- `app/Services/GitReader.php`, `config/`, any registered project's files (read-only).

## Data & interfaces
- Reads SB-22's `manifest.json` through SB-24's `ReadJourneyShots` and `shots.file` route (reuse, don't duplicate).

## Test plan
- Pest: failing tests first with a fixture manifest + PNGs in a temp project path.
- Journey test: none.
- Browser check: after `JOURNEY_SHOTS=1` on coins, `/mockups` → a coins set → compare shows Current vs option.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-21, SB-24 (shot reader); shots appear once a project adopts SB-22
