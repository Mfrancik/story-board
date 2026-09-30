# Journey shots
Status: active   ·   Last updated: 2026-09-30   ·   Stories: SB-22

## Overview
A Pest browser helper, `journeyStep()`, that a journey test calls at each step it walks. With
`JOURNEY_SHOTS=1` it saves a desktop and a 375 px PNG of that step, plus a `manifest.json`, under
`storage/app/journey-shots/<journey>/`. Journey tests already visit every screen in order, logged in, on
dummy data, so this is how the board (app map, the gallery's Current pane) shows what each page looks
like today without ever running a project against real data. It is a **dev-standards kit helper**
(scope PROMOTE): built and proven here, then synced to every project. Test tooling only: no app code,
no logging.

**Kit adoption** is in [`docs/journey-shots.md`](../journey-shots.md): copy
`tests/Support/JourneyShots.php`, add the three-line `journeyStep()` wrapper to `tests/Pest.php`, and
gitignore `/storage/app/journey-shots/`. That page is the how-to a project reads; this one is how the
helper works.

## How it works
`tests/Pest.php:journeyStep($journey, $step, $route, ?object $page = null)` forwards to
`tests/Support/JourneyShots.php:step()`:

1. **Page.** Without `$page` it `visit()`s `$route`. With `$page` it uses the page the test is already
   on, for steps reached by a click or a form. Either way it **returns the page**, so it replaces
   `visit()` and the test keeps chaining assertions.
2. **Switch.** `enabled()` is `getenv('JOURNEY_SHOTS') === '1'`, and nothing else. Off, the page is
   returned untouched and nothing is written, so normal runs and preflight pay nothing.
3. **Names.** `slug()` turns `/` and `\` into spaces before `Str::slug()` (otherwise `a/b` becomes `ab`).
   The journey is slugged as well as the step, so `../../escape` lands in `escape/`. Only `[a-z0-9-]`
   survives, so no name can leave the journey folder. A name that slugs to nothing throws
   `InvalidArgumentException` before anything is written.
4. **Run start.** `begin()` keeps a per-process counter per journey. On a journey's first step in a run
   it deletes that journey's `*.png` and `manifest.json` (nothing else in the folder) and numbers steps
   from 1. A run is one PHP process; `JourneyShots::newRun()` resets the counters so a test can simulate
   a second run.
5. **Capture.** Reads the current viewport via `script()`, then for each of `DESKTOP` (1280×800) and
   `PHONE` (375×812): `resize()`, then `capture()` a full-page PNG. Pest shoots at `scale: css` by default, so the PNG's pixel width equals
   the manifest `width` on any display. Afterwards the viewport is restored.
   Files are `NN-<step>.png` and `NN-<step>-375.png`.
6. **Pest's screenshot folder.** Pest only writes screenshots into its own `tests/Browser/Screenshots/`
   (`Pest\Browser\Support\Screenshot::path()`, marked `@internal`). `capture()` saves there under a
   random `journey-shot-<hex>` name, moves the file to its target, then `@rmdir`s the folder, which only
   succeeds if empty, so a Pest failure shot is never removed.
7. **Manifest.** Two entries per step (desktop, then phone), appended to the journey's `manifest.json`
   and rewritten pretty-printed each step.
8. **Story lookup** (`story()`). From `docs/journeys/<journey>.md`, if it exists: among numbered-flow lines
   (`3.`, `4b.`) and table rows, the first story ID on a line that has the route as a code span
   (`` `/verify` ``); else the first on the flow item or table row numbered like the step's position.
   Deliberately simpler than story-board's `ReadJourneyMap`, because the kit helper cannot depend on
   board code.
9. **Commit.** `git rev-parse --short HEAD` in the project root, cached per root per run; omitted if git
   cannot answer.

**Example use:** `tests/Browser/Journeys/BrowseTheBoardTest.php` walks `/`, `/p/coins`, then clicks to
`/p/rent-track` and passes `$page` for that last step.

## Data model
No schema. On-disk, outside git (root `.gitignore` and `storage/app/.gitignore` both cover it):

```
<project>/storage/app/journey-shots/<journey>/
  01-<step>.png  01-<step>-375.png  02-…  manifest.json
```

`manifest.json` is a JSON list, one entry per PNG:
`journey` (slug), `step` (raw, as passed), `route`, `story` (only when found), `file` (bare name,
relative to the manifest), `width` (1280 or 375), `captured_at` (ISO-8601), `commit` (only when
readable). **This is a contract** with `App\Actions\Board\ReadJourneyShots` (see
[app-map.md](app-map.md)), which treats `width` ≤ 480 as the phone shot. Change both or neither.

## Interfaces
- `journeyStep(string $journey, string $step, string $route, ?object $page = null): object` (global, `tests/Pest.php`).
- `Tests\Support\JourneyShots`: `step()`, `enabled()`, `newRun()`, `root()`, `slug()`; constants
  `DESKTOP`, `PHONE`, `FOLDER`; `public static ?string $project` (null = `base_path()`; tests point it at a
  throwaway repo).
- Throws `InvalidArgumentException` for an empty journey or step slug.

## Configuration
- `JOURNEY_SHOTS=1` in the **shell** turns capture on: `JOURNEY_SHOTS=1 php artisan test tests/Browser/Journeys`.
  It is read with `getenv()` only, so `.env` and `$_SERVER` do not turn it on, and a test can switch it off
  with `putenv()`.

## Observability
None by design: test tooling logs nothing (story's logging standard: none). To check a run, open
`storage/app/journey-shots/<journey>/`: two PNGs per step and a manifest with two entries per step. To
check the board sees them, open `/p/{project}/map`. A step with a shot shows the PNG, and a
refused manifest entry logs `board.journey_shot_refused` on the board side (app-map.md).

## Testing & verification
`tests/Browser/JourneyShotsTest.php`, one `it()` per acceptance criterion plus one for the empty-slug
refusal. `beforeEach` registers throwaway `/verify` and `/welcome` routes (Pest's in-process server
shares the app), writes a journey doc into a `GitFixture` repo and points `JourneyShots::$project` at it,
so the real shots folder is untouched. Criteria covered:
- a PNG at `sign-up/NN-verify.png`, listed with route, commit and time;
- two entries per step, desktop and 375, `file` bare and `width` matching the PNG (`getimagesize`);
- round-trip: a real capture's manifest, order reversed, read by `ReadJourneyShots`, which still classes
  375 as phone and matches by story and by route;
- `JOURNEY_SHOTS` unset writes nothing;
- a second run (`newRun()`) replaces rather than appends;
- spaces and slashes are slugged and stay inside the journey folder;
- the shots folder is gitignored (`git status` shows nothing new).

Browser check: `JOURNEY_SHOTS=1 php artisan test tests/Browser/Journeys`, then open the PNGs.

## Key decisions & tradeoffs
[ADR-035](../decisions/ADR-035-journey-shots-are-a-test-helper-with-a-pinned-manifest.md):
- Capture lives in the journey tests, not a separate crawler: the tests already have the logins and
  dummy data.
- The switch is the process environment only.
- Screenshots go through Pest's own folder and are moved out, because Pest offers no target path.
- The manifest is pinned to what `ReadJourneyShots` reads.
- Story lookup is a simple local match, not the board's journey parser.

## Known limitations & gotchas
- **Depends on Pest internals.** `Screenshot::path()` / `dir()` are `@internal`. A Pest upgrade that
  renames or moves them breaks capture (RUNBOOK).
- **Folder name must equal the journey doc name** for the app map to show shots. The example journey
  `browse-the-board` has no `docs/journeys/browse-the-board.md`, so its shots are a demo only and do not
  appear on the map.
- A run is one PHP process. Under `php artisan test --parallel`, a journey's steps split across workers
  would each clear and renumber the folder. Journey tests should not run in parallel with capture on.
- A journey no longer run keeps its old shots until its folder is deleted by hand.
- Shots are whatever the working tree was at capture; `commit` records HEAD, not uncommitted changes.
- The kit how-to lives at `docs/journey-shots.md` because the repo has no clear home for kit docs.
  Owner to decide where kit docs belong.
- Journey docs are free-form, so the story lookup can miss. The entry then has no `story`, and the
  board matches by route.

## Change history
2026-09-30 — `journeyStep()` helper, manifest format, example journey, kit how-to (SB-22, `0f7ae8b`)
