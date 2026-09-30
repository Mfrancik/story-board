# App map
Status: active   ·   Last updated: 2026-09-30   ·   Stories: SB-24

## Overview
`/p/{project}/map` draws a project's journeys as screens in order. Each journey is a numbered strip of
steps under a large stage with Back/Next. An **All flows** overview stacks every journey as a lane, with
lettered rings on the screens (routes) that more than one journey visits. Journey docs describe flows
in text and stories describe pieces, but nothing showed the app as screens. A hand-drawn wireframe would
go stale the day after it was drawn. So the map is **generated** from files each project already keeps
(journey docs, story files, mockups) and needs **no change to any project**. Real screenshots appear
only when a project adopts SB-22's journey shots. The layout is mockup option A (the storyboard strip).

## How it works
**Routes** (`routes/web.php`), both behind `EnsureProjectIsShown` (ADR-013):
- `projects.map`: `GET /p/{project}/map` → `App\Livewire\Board\ProjectAppMap`.
- `shots.file`: `GET /shots/{project}/{journey}/{file}` → `App\Http\Controllers\JourneyShotController`.
  `file` is `->where('file', '.*')` **on purpose**. A traversal attempt (`../x`, slashes) reaches the
  action, where it is refused and logged, instead of dying as an unlogged router 404.

**Tab.** `board/project-tabs` gains **App map**. Five tabs do not fit at 375 px, so the row is
`overflow-x-auto pb-px` and each tab is `shrink-0` (see RUNBOOK for why `pb-px`).

**Read, once per page load.** `ProjectAppMap::render()` calls `ReadJourneyMap::handle()` and logs
`board.app_map_viewed`. The component has no actions, and a normal visit never re-renders it.
`hydrate()` re-checks that the project is still shown (ADR-019 amendment) and sends the owner home if not.

`app/Actions/Board/ReadJourneyMap.php`:
1. **List and read.** `GitReader::listFiles()` lists `docs/journeys/*.md` at the snapshot `sha`, one level
   only and excluding `README.md`. `showMany()` then reads them in one batch. If git fails, it logs
   `board.app_map_unreadable` and returns `unreadable: true`, and the page says so. A project with no
   `sha` yet gets the empty state, not an error.
2. **Parse** (`parse()`). This is public and pure, so it can be tested on its own. Journey docs are
   free-form, so it is tolerant:
   - **Flow list** (`flowItems()`): the numbered list under the first `##`–`####` heading that contains
     flow / steps / per step and does not contain stor / test / decision. That rule catches
     client-dashboard's `## The contract (per step)`.
   - **Stories table** (`tableRows()`): the first table whose first column is `Step`. The story column is
     `ID` or `Story`. A label like `3, 5` applies to both steps. `(substrate)` / `(framing)` rows are not
     screens and are dropped.
   - **Joining them.** A table row whose step the list lacks (e.g. `4b`) is inserted after its numbered
     step (`insertAfter()`). With no list, the table's rows are the steps. A list item with no table row
     takes the first story ID in its own text. The step name is the first clause, cut at `NAME_LENGTH`.
   - **Fails to parse** means neither a numbered flow list nor a `| Step |` table. The doc is logged
     `board.journey_unparsed` and named on the page. The other journeys still render.
3. **Compose** (`compose()`):
   - **Stories.** One `Story::onRef()` query fetches every story named by any step.
   - **Picturing story.** When a step names several stories, the first one with a route pictures it.
     The rest show as **Also**.
   - **Route.** The first `` `/path` `` code span in the step text. Otherwise it is the story file's
     `- Routes:` line, via `ReadMockupSets::where()`, now `public static` and shared with the gallery.
     Those story files are read in one `showMany()` per commit (`storyRoutes()`).
   - **State.** The story snapshot's `Status:` wins. The journey table's status is only a fallback for a
     story the snapshot lacks. `built` → built, anything else → pending, no story → `unlinked`.
   - **Unlinked marker.** A step with no story or no route gets `unlinked: story|route` and is shown as
     "Not linked", never hidden.
4. **Picture** (`picture()`), one of seven kinds:

   | Kind | When | Shows |
   |---|---|---|
   | `shot` | built, and `ReadJourneyShots::forStep()` found one | the PNG via `shots.file`, with capture time |
   | `placeholder` | built, no shot (the default today) | drawn `.map-page` wireframe labelled with route and story |
   | `mockup` | pending, set `picked` | the chosen option via SB-21's `mockups.frame` |
   | `awaiting` | pending, set `awaiting` | "Awaiting pick", links `/mockups/{project}/{story}` |
   | `mockups` | pending, set in `other` state | "No pick recorded", links the gallery |
   | `none` | pending, no mockup set for the story | "No mockup yet" |
   | `unlinked` | step names no story | "Not linked" |

5. **Shared screens** (`share()`). A route visited by more than one journey gets a letter (A…Z, then AA)
   in order of first appearance. Its uses are listed in the side list (`data-shared-list`).

**Shots** (`app/Actions/Board/ReadJourneyShots.php`, owned here, and SB-23 will reuse it):
- `handle(Project)` globs `storage/app/journey-shots/*/manifest.json` in the project's **working tree**,
  read-only. It returns `journeyFolder => list<JourneyShot>`, memoised per project path. A project that
  never adopted SB-22 has none.
- The manifest is the whitelist. `resolve()` refuses absolute paths, backslashes, NUL, a `realpath()`
  outside `journey-shots/` (which catches symlinks), missing files and non-image extensions
  (`TYPES`: png/jpg/jpeg/webp).
- `forStep()` matches by story first, then by route. It prefers desktop shots, because a `width` of 480
  or less counts as phone.
- `file(Project, journey, file)` is an **exact lookup** among what `handle()` read, never a path join. A
  miss logs refusal and the controller returns 404. Served shots carry `nosniff`, `no-referrer` and
  `private, max-age=60`.

**View** (`resources/views/livewire/board/project-app-map.blade.php`, Alpine `appMap` in
`resources/js/app-map.js`):
- Everything is rendered by the server once. Picking a flow, Back/Next (buttons and ← →), full screen and
  the shared-screen hover are all Alpine, with no round-trip.
- Next is disabled on the last step (`atLast`). Each flow's stage, step card and strip sit in `x-if`
  templates, so only the open flow's frames exist in the DOM. A hidden iframe still loads eagerly (see
  RUNBOOK, SB-4). Full screen reuses the stage in place as a fixed dialog.
- `resources/views/components/board/map-screen.blade.php` draws one screen in browser chrome with its
  route, for every picture kind, at `size` sm or lg.
- Shared-screen rings cycle the `series-1..3` colour tokens. The letter is what tells them apart.

## Data model
None. No schema change, nothing stored or cached. Reads the `stories` snapshot (on-ref rows), journey docs
and story files from git at the snapshot sha, and journey-shot manifests from the working tree.

## Interfaces
- `ReadJourneyMap::handle(Project): JourneyMap` returns `journeys`, `unparsed` (file paths), `shared`,
  `unreadable`, `steps` and `shots`. The phpstan shapes `MapStep` / `MapPicture` / `MapJourney` are in the
  class docblock.
- `ReadJourneyMap::parse(string $markdown, string $slug): ?array` returns name, journey test path
  (`tests/Browser/Journeys/…`) and steps, or null.
- `ReadJourneyShots::handle(Project)`, `forStep(shots, journey, ?story, ?route)` and
  `file(Project, journey, file): ?string`. The phpstan type is `JourneyShot`.
- **Manifest shapes accepted.** SB-22 writes the first shape below with `file` as a bare name (pinned in
  [journey-shots.md](journey-shots.md)); the others remain accepted:
  - The top level is a JSON list, or an object holding the list under `shots` / `steps` / `entries`.
  - The file key is `file`, `path` or `png`. Its value is a bare name (relative to the manifest folder),
    `journey-shots/<j>/…` or `storage/app/journey-shots/<j>/…`.
  - Optional keys are `step`, `route`, `story`, `captured_at`, `commit` and `width`.
- `ReadMockupSets::where(string $markdown): ?string` is now public static.
- Test hooks: `data-overview`, `data-flow`, `data-journey`, `data-step`, `data-state`, `data-story`,
  `data-picture`, `data-shared`, `data-shared-list`, `data-unlinked`, `data-unparsed`, `data-unreadable`,
  `data-empty`, `data-stage`, `data-next`, `data-back`, `data-open-large`, `data-lightbox-*`, `data-strip`.

## Configuration
None.

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.app_map_viewed` | info | `ProjectAppMap::render()` | `project`, `journeys`, `steps`, `shots` |
| `board.journey_unparsed` | warning | `ReadJourneyMap::handle()` | `project`, `file` |
| `board.journey_shot_refused` | warning | `ReadJourneyShots` (`entries()`, `file()`) | `project`, `path`, `reason` |
| `board.app_map_unreadable` | warning | `ReadJourneyMap::handle()`, `storyRoutes()` | `project`, `ref`, `error` |
| `board.project_page_refused` | info | `ProjectAppMap::hydrate()` | `project`, `reason`, `request: update` |

`board.app_map_unreadable` was not named in the story. It was added so that a git failure is visible.
Every line carries `request_id`.
- **Healthy load:** one `app_map_viewed`. `shots` is 0 until the project adopts SB-22.
- **Missing screens:** a `journey_unparsed` names the doc, which then needs a numbered flow list or a
  `| Step |` table.
- **Shot shows as a placeholder:** look for `journey_shot_refused`. Its `reason` is "outside
  journey-shots/", "not a JSON list of shots" or "not in a manifest".
- **Steps with no routes:** if the story files could not be read, look for `app_map_unreadable` with a
  story `ref`.

## Testing & verification
- `tests/Feature/Board/ProjectAppMapTest.php` has one `it()` per acceptance criterion, run on a fixture
  repo once with no shots and once with a fixture manifest and PNGs. Extra cases:
  - route-only shot match and the symlink refusal;
  - the shot route 404s (unlisted PNG, traversal, unknown journey, disabled project);
  - a non-JSON manifest, and "no mockup yet";
  - no snapshot, and git failure;
  - the `hydrate()` re-check and the tab;
  - a visit leaves the working tree and `git status` unchanged.
- `tests/Browser/ProjectAppMapTest.php` steps a flow to its end with Next disabled and no page load.
  It also checks there is no sideways scroll at 375 px on All flows and on one flow.
- Real data, verified at build: every journey doc across coins, client-dashboard, asset-track and
  rent-track parses, and every `/map` returned 200.

## Key decisions & tradeoffs
- The map is generated on the server from each project's own files. Placeholders are one CSS element, and
  shots are served by manifest lookup, never by path →
  [ADR-033](../decisions/ADR-033-app-map-is-generated-server-side-from-project-files.md).
- The route falls back to the story's `- Routes:` line. Journey docs rarely name routes, so without the
  fallback shared screens would almost never show.
- The story's own `Status:` beats the journey table's status column, which is a copy that drifts.
- **Open question for the owner:** the new type token `--text-thumb` (0.5rem, `resources/css/app.css`)
  sets text inside thumbnails at half of xs, as the mockup did. It was proposed during the build and is
  not approved. Either approve it or swap it for `text-xs`.

## Known limitations & gotchas
- **Page weight is the cost driver.** Every screen of every journey is in the HTML. On coins that is
  about 3.1 MB for 205 steps across 27 journeys, rendered in 0.24 s. It was 4.2 MB before the placeholder
  became a single `.map-page` element. Rendering the stage and strip client-side from JSON would be
  lighter, but it would duplicate `map-screen` in JS (ADR-033).
- asset-track's CV-2 and CV-3 show "No mockup yet" because their mockups live under CV-1's folder. That is
  accurate to the files, but it differs from the approved mockup.
- A set whose choice the kit parser cannot read (`other`, e.g. asset-track TS-3) shows "No pick recorded",
  not the option.
- Shots come from the **working tree**, not the ref, so they can be newer or older than the snapshot.
- SB-22's manifest format is pinned to this reader ([journey-shots.md](journey-shots.md)). Phone vs
  desktop rests on `width` of 480 or less. A shot folder shows only when it is named like the journey doc.
- Shared-screen colours repeat after three, and only the letter tells rings apart.

## Change history
2026-09-29 — App map tab, All flows overview with shared screens, flow stage/strip with Alpine stepping,
`ReadJourneyMap`, `ReadJourneyShots`, `shots.file` route; project tabs scroll on phones (SB-24, `b838dcf`)
2026-09-30 — Manifest format pinned by SB-22's `journeyStep()`; doc notes only, no code change (SB-22)
