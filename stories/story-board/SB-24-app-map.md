# SB-24 — App map
Status: draft          Journey: none
Source: owner 2026-09-29 (/story): *"would it be difficult, or cause a lot more work on the other sessions to design a
wireframe of an app and where it currently stands with dummy data that we can flip through and navigate or see flows
of how things move? … id want to see a mockup of what that'd look like."* Agreed approach: generated, not hand-drawn —
built from files each project already keeps (journey docs, story files, mockups), so build sessions do no extra work
and it cannot drift. Owner 2026-09-29: the map must work with **no change to any project** — real screenshots of built
screens are a bonus that appears only when a project adopts SB-22.

## Story
As the owner, I want a clickable map of each project's flows built from its journeys and real screenshots, so that I
can flip through where the app stands today and what is still to come.

## Why
Journey docs describe flows in text; stories describe pieces. Nothing shows the app as screens in order, and a
hand-maintained wireframe would go stale the day after it was drawn.

## In scope
- An **App map** tab on the project page (`/p/{project}/map`): every journey from `docs/journeys/*.md` (read through
  `GitReader` at the project's ref) as a flow of steps, each step with its story ID and state.
- Each step's picture: **Built** → a drawn placeholder page labelled with its route and story (the default), or its
  journey shot when the project has SB-22's `manifest.json` · **Pending** → the story's chosen mockup option (SB-21) ·
  awaiting pick → "awaiting pick" with a link to the gallery · no mockup → "no mockup yet".
- Works for every registered project with journey docs **without any change to that project**.
- Owns `ReadJourneyShots` (read-only, whitelisted by the manifest); SB-23 reuses it.
- Click a step to open it large and step **Next / Back** through the flow (keyboard arrows too).
- An overview of all journeys, showing where they share a screen (same route).
- Journey doc steps that name no story or no route are shown with a "not linked" marker, not hidden.

## Out of scope (do NOT build)
- Hand-drawn wireframes or editing flows on the board. Capturing shots (SB-22). Writing to journey docs or to any
  project. Requiring any project to adopt SB-22.
- Running the project's app or its tests from the board.

## Acceptance criteria (executable — these become the Pest test names)
- Given a project with two journey docs, when `/p/{project}/map` loads, then both flows are listed with their steps in
  order and each step's story ID and state.
- Given a project with no journey shots at all (no SB-22), then every built step shows a placeholder page labelled with
  its route and story ID, the map is fully navigable, and the page returns 200.
- Given a built step with a journey shot in the project's `manifest.json`, then its picture is that shot with the
  capture time.
- Given a manifest entry whose path is outside `journey-shots/`, then it is refused, the step falls back to the
  placeholder, and `board.journey_shot_refused` is logged.
- Given a pending step whose story has `Chosen option: b`, then its picture is option b from the gallery.
- Given a pending step whose story awaits a pick, then it shows "awaiting pick" linking to `/mockups/{project}/{story}`.
- Given a step open large, when Next is pressed on the last step, then it stays and Next is disabled.
- Given two journeys visiting the same route, then the overview marks the shared screen.
- Given a journey doc that fails to parse, then the other journeys still render and the page says which one failed.
- Given a project with no journey docs, then the empty state explains where journeys come from and returns 200.
- Given a disabled or unknown project, then the route returns 404.
- Given the page loads, then `board.app_map_viewed` is logged with `project`, `journeys`, `steps`, `shots`.

## Applicable standards
- Design: design-A shell, `board/project-tabs` gains App map, `board/project-header`; the chosen mockup option below.
  Step-through is Alpine (no round-trip). Tokens are law.
- Codebase/DB: no schema change. Journey docs through `GitReader`; shots via `ReadJourneyShots` (new here: reads the project's
  working-tree `storage/app/journey-shots/*/manifest.json` if present, read-only); mockups via SB-21's `ReadMockupSets`. A parser `ReadJourneyMap` tolerant of the journey doc format (see `docs/journeys/README.md`).
- Logging: `board.app_map_viewed` (info; `project`, `journeys`, `steps`, `shots`), `board.journey_unparsed` (warning;
  `project`, `file`), `board.journey_shot_refused` (warning; `project`, `path`).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/SB-24/option-{a,b,c}.html (coins' real journeys as example data)
- Chosen option: a
- Why I chose it: owner pick 2026-09-29 ("A works for SB 24"), no reason given. Direction: storyboard strip — a flow's screens in a numbered row with arrows under a large stage with Back/Next, ← → keys and full screen; "All flows" stacks every journey as a row, shared screens get a lettered coloured ring lit across rows on hover, plus a shared-screens side list.

## Do NOT touch
- `app/Services/GitReader.php` (reads only), `config/`, any registered project's files.

## Data & interfaces
- Schema/migrations: none.
- Route `GET /p/{project}/map` (`projects.map`). Livewire `ProjectAppMap`. Action `ReadJourneyMap`.
  Action `ReadJourneyShots` + route `GET /shots/{project}/{journey}/{file}` (`shots.file`). `board/project-tabs` gains the tab. `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: failing tests first; fixture repo with journey docs + mockups, run once with no shots and once with a fixture
  manifest + PNGs.
- Journey test: none.
- Browser check: `/p/coins/map` (no shots today) → a journey → placeholders for built steps, mockups for pending →
  open step 1 → Next through to the end → All flows marks shared screens.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-21 (mockup reader) · Optional: SB-22 (real shots) · Next: SB-23 reuses `ReadJourneyShots`
