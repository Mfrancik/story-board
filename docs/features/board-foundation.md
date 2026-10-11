# Board foundation
Status: active   ·   Last updated: 2026-10-11   ·   Stories: RD-1

## Overview
The shared backend the board's pages stand on: one status order, one mockup-gate parser, one
project-page guard, and the action-level tests that prove them without loading a page. RD-1 built it
for the redesign. It deleted the unused auth starter kit and untangled the kept backend from the
pages RD-9 will delete, so later RD stories build only on code that is in use and tested directly.
There is no route or UI of its own. Every board page reads through it.

## How it works
**What was removed.** The Laravel auth/settings starter kit is gone. That covers `routes/settings.php`
and its include, the auth-only `dashboard` route and view, `app/Livewire/Settings/*`,
`app/Livewire/Actions/Logout.php`, `app/Actions/Fortify/*`, `app/Concerns/*`, `FortifyServiceProvider`,
`config/fortify.php` (owner OK, 2026-10-10), the auth/settings/app layouts, the starter Blade components,
`welcome.blade.php`, the Flux overrides only those layouts used, and their tests. The
`laravel/fortify` package went too, because its own provider registers `/login` and `/register` even
with the app's provider gone ([ADR-037](../decisions/ADR-037-starter-kit-and-fortify-package-removed.md)).
`App\Models\User` keeps `HasFactory, Notifiable` and lost the Fortify passkey and two-factor traits.
The `users` / `passkeys` tables and the `two_factor_*` columns stay, because dropping them is destructive
and was out of scope.

**Status order.** `app/Services/StoryStatusOrder.php` is a static, pure class:
- `ORDER` is the kit's lifecycle order (`draft, approved, built, cancelled`). Any other status follows, A–Z.
- `OPEN` is `['draft', 'approved']`, the one definition of open work. `isOpen()` checks it. It replaces
  three copies: the pick gate in `StoryPickWriter::pick()`, the `open` rollup column, and the `pick`
  query in `ListWhatNeedsMe::handle()`.
- `ordered(array $counts)` sorts tally keys. It moved here from `ListWhatNeedsMe`, and project tiles and
  initiative bars both use it.
- `rollup(iterable<stdClass> $rows)` folds `(initiative, status, n, parked)` rows into one row per
  initiative, most open work first. It moved here from `ReadProjectProgress`. `ReadProjectProgress`
  feeds it its grouped SQL, and `ReadProjectStories` feeds it rows counted in PHP (ADR-025 still holds).
  Neither action depends on the other, or on `ListWhatNeedsMe`, any more.

**Mockup-gate parser.** `app/Services/MockupGateParser.php` is the only reader of a story's
`## Design mockup gate` section. It is pure: it never touches git, the database or the log.
- `section($markdown)` returns `[body, byteOffset]` or null. `StoryPickWriter::rewrite()` needs the offset
  to splice the edited section back in.
- `isNonVisual($section)` checks for an `n/a` opening.
- `refusalFor($markdown)` gives the reason the board cannot write a pick, or null when it can.
- `pickedLetter($markdown)` returns a single-letter Chosen value, lower-cased. `b`, `**B**` and `` `b` `` all
  read as `b`.
- `boardBranch($ref, $remotes)` returns the ref without a known remote (`origin/main` becomes `main`),
  or the ref itself.
- `LINE` is the gate-line regex (`sprintf` the field name in).

`ReadMockupGate`, `ReadMockupSets` and `StoryPickWriter` all inject the parser, so the gallery's
"awaiting", the story page's quote and the writer's refusal follow one rule. The remotes come from the
new `GitReader::remotes($path)` (`git remote`). `GitReader::fetch()` uses it too.
`StoryPickWriter` now only writes. `pick()` returns `array{sha, branch}`, and the branch is the one
`assertCheckoutReady()` checked against. `MockupViewer::pick()` uses it for its toast
([ADR-038](../decisions/ADR-038-kept-board-logic-moves-into-pure-shared-classes.md)).

**Project-page guard.** `app/Livewire/Board/Concerns/GuardsProjectPage.php` holds the block that used to be
copied into each page: `#[Locked] public string $project`, `private bool $gone`, and
`hydrate(CheckProjectShown)`. `hydrate()` re-checks the project on every Livewire update request,
because route middleware never sees those requests (ADR-019 amendment). When the project has left the
board, it logs, sets `$gone` and redirects. `ProjectStories`, `ProjectPreflight`, `ProjectHandbook`,
`ProjectAppMap` and `MockupViewer` use it. There are two override hooks:
- `refusedRoute()` defaults to `home`. `MockupViewer` returns `mockups`.
- `logProjectRefused($reason)` logs `board.project_page_refused` by default. `ProjectHandbook` routes it
  through its own `refuse()`, so it keeps `board.handbook_refused`.

Every page redirects and logs exactly as it did before. `ProjectPage` has its own guard and does not
use the trait.

**Mockup content types.** `MockupSetFileController::TYPES` now owns the extension→MIME map. That is the
gallery's frame route, which the redesign keeps. The story page's older `MockupFileController` reads
the map from there until RD-9 removes it.

## Data model
None. No migrations. The `users`, `passkeys` and `two_factor_*` schema is kept but unused.

## Interfaces
- `StoryStatusOrder::ORDER`, `::OPEN`, `::isOpen(?string): bool`, `::ordered(array): array`,
  `::rollup(iterable<stdClass>): list<{name, counts, total, built, open, parked}>`.
- `MockupGateParser::section(string): ?array{0: string, 1: int}`, `isNonVisual(string): bool`,
  `refusalFor(string): ?string`, `pickedLetter(string): ?string`,
  `boardBranch(string $ref, list<string> $remotes): string`, const `LINE`.
- `GitReader::remotes(string $path): list<string>`. It throws `GitReaderException` when the path is not a repository.
- `StoryPickWriter::pick(...)`: `array{sha: string, branch: string}` (was the SHA string).
- Trait `GuardsProjectPage`: `hydrate(CheckProjectShown)`, plus the protected hooks `refusedRoute()` and `logProjectRefused(string)`.
- Removed routes: `/dashboard`, `/settings/*`, and Fortify's `/login`, `/register` and friends. All are 404s now.

## Configuration
`config/fortify.php` and the `laravel/fortify` composer package are removed. Nothing else changed.

## Observability
No new events, and no event was renamed. The trait logs `board.project_page_refused` (info:
`project`, `reason` `disabled|unknown`, `request: update`). `ProjectHandbook` logs
`board.handbook_refused` instead. A healthy refusal trace shows that line, then a navigate redirect to `home`
(or to `mockups` from the viewer). `board.mockup_picked` / `board.mockup_pick_refused` are unchanged.

## Testing & verification
- `tests/Feature/Board/CleanFoundationTest.php` has one test per page-level criterion: the removed URLs are 404s,
  each board route is still 200, each mockup file route serves the right type, a bold pick agrees across
  parser / `ReadMockupSets` / `StoryPickWriter`, and the trait redirects for each page using it. The
  bold-pick test commits `Chosen option: **B**` on a local, unpushed branch in a fixture checkout. The kit
  parser cannot read `**B**` (L-6), so the test proves that the board's three readers agree.
- `tests/Unit/FoundationArchTest.php` contains the arch rules. `ReadProjectStories` and `StoryPickWriter` depend on
  neither `ListWhatNeedsMe` nor `ReadProjectProgress`. `ReadMockupSets` reads the gate through the parser.
  The parser and the status order use no git, DB or log.
- `tests/Unit/MockupGateParserTest.php` and `tests/Unit/StoryStatusOrderTest.php` cover the pure classes.
- Action tests ported off the pages RD-9 deletes:
  - `tests/Feature/Board/ProjectActionsTest.php` covers `AddProject`, `SwitchProject` and `RemoveProject`.
  - `tests/Feature/Board/StoryReadActionsTest.php` covers `RenderStory` and `FindStoryVersion::handle()`.
  - `tests/Feature/AssignRequestIdTest.php` covers the middleware.
  - `ProjectRegistryTest` now asserts on the model (`stories_count`, `needsRefresh()`), not on `GET /`.
- Browser check: `/`, `/p/story-board/stories`, `/preflight`, `/handbook` and `/mockups` look and behave as before.

## Key decisions & tradeoffs
- The Fortify package was removed, not just disabled, because its provider keeps the auth routes alive
  ([ADR-037](../decisions/ADR-037-starter-kit-and-fortify-package-removed.md)).
- The kept logic moved into pure classes. The parser takes the remotes and does not shell out. `pick()` returns
  its branch. The status order is static in `app/Services`. The trait has hooks instead of one unified
  behaviour ([ADR-038](../decisions/ADR-038-kept-board-logic-moves-into-pure-shared-classes.md)).

## Known limitations & gotchas
- Some starter-kit leftovers are still unused: `resources/js/passkeys.js`, its `vite.config.js` input, the
  `@laravel/passkeys` npm dependency, and `UserFactory::withTwoFactor()`. They were left for the owner.
- The page-load refresh dispatch is still proven only through the page tests (`HomePageTest`,
  `ProjectPageTest`). RD-9 must keep or port that coverage when it deletes those pages.
- A new one-project page should `use GuardsProjectPage` and override a hook only if it must return elsewhere or
  keep its own event name. Do not copy `hydrate()` again.
- `composer remove` runs `boost:update`, which rewrites `.claude/` and agent files. Use `--no-scripts` (RUNBOOK).

## Change history
2026-10-11 — Starter kit and `laravel/fortify` removed; `StoryStatusOrder`, `MockupGateParser`, `GuardsProjectPage` and `GitReader::remotes()` added; action tests ported off old pages (RD-1, `6ad827b`)
