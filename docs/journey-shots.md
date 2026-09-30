# Journey shots — a screenshot per journey step
Kit how-to (SB-22). Journey tests already walk every screen in order, logged in, on dummy data. One
`journeyStep()` call per step saves what that screen looks like today, and the story board shows it
(app map, gallery "current version") without ever running the app against real data.

## Adopt it in a project
1. Copy `tests/Support/JourneyShots.php` from the kit.
2. Add the helper to `tests/Pest.php` (with `use Tests\Support\JourneyShots;` at the top):
   ```php
   function journeyStep(string $journey, string $step, string $route, ?object $page = null): object
   {
       return JourneyShots::step($journey, $step, $route, $page);
   }
   ```
3. Add `/storage/app/journey-shots/` to `.gitignore`. Screenshots are never committed.

## Use it in a journey test
Call it at each step, in place of `visit()`. It returns the page, so the test goes on asserting:
```php
journeyStep('sign-up', 'register', '/register')->type('email', 'a@b.test')->press('Continue');

// A step reached by clicking, not visiting: pass the page the test is on.
$page->click('Verify')->assertPathIs('/verify');
journeyStep('sign-up', 'verify', '/verify', $page);
```
- `$journey` is the journey doc's file name (`docs/journeys/sign-up.md` → `sign-up`), so the board can
  match shots to the doc.
- `$route` is the path the step shows. The board matches a step to its shot by story first, then by
  route, so use the route as the journey doc or the story's `- Routes:` line writes it.
- The story is filled in for you when the journey doc names one: the first story ID on the flow item or
  table row that has the route as a code span (`` `/verify` ``), else on the flow item numbered like the
  step (the Nth `journeyStep()` call ↔ item `N.`). Otherwise the entry has no `story`.

## Capture
Off by default, so normal runs and preflight cost nothing. Turn it on for a run:
```bash
JOURNEY_SHOTS=1 php artisan test tests/Browser/Journeys
```
Each step writes, under `storage/app/journey-shots/<journey>/`:
- `NN-<step>.png` at 1280 px wide and `NN-<step>-375.png` at 375 px, both full-page. `NN` is the step's
  order in the run, and the step name is slugged (`Pick a / plan` → `pick-a-plan`).
- two entries in `manifest.json`: `journey`, `step`, `route`, `story` (when found), `file` (bare name),
  `width`, `captured_at`, `commit` (short `HEAD`). The board's `ReadJourneyShots` reads this shape and
  treats `width` ≤ 480 as the phone shot. Change both together or neither.

The first step of a journey in a run deletes that journey's PNGs and manifest, so a re-run replaces the
shots rather than appending. A journey that is no longer run keeps its last shots until you delete the
folder.
