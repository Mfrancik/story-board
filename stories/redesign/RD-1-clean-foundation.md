# RD-1 — Clean foundation
Status: approved         Journey: none
Source: owner 2026-10-10, redesign brief: *"if were going to have a bunch of dead obselete code id rather scrap the
code too and just build on a good foundation. if some is reasonable we can keep it, if its only reuseable and
clean. dont try to fit new stuff on bad fondation if it doesnt fit"*. Scope from the 2026-10-10 foundation audit.

## Story
As the owner, I want the dead code removed and the kept backend untangled from the pages being replaced, so that
the redesign is built on code that is all in use, tested directly, and free of ties to the old UI.

## Why
The audit found ~2,000 lines of unused auth starter kit, duplicated parsing, and four ties between kept code and
scrapped pages (status ordering, the story modal, refresh-on-load, tests that only reach actions through old pages).
Every later RD story would otherwise work around them.

## In scope
- **Delete the unused auth starter kit:** `routes/settings.php` and its include, the auth-only `dashboard` route
  and `dashboard.blade.php`, `app/Livewire/Settings/*`, `app/Livewire/Actions/Logout.php`, `app/Actions/Fortify/*`,
  `app/Concerns/*`, `FortifyServiceProvider` (and its `bootstrap/providers.php` line), `resources/views/livewire/auth/*`,
  `livewire/settings/*`, `layouts/app/*`, `layouts/auth/*`, the starter Blade components (`desktop-user-menu`,
  `passkey-*`, `placeholder-pattern`, `app-logo*`, `auth-*`, `settings/layout`), `partials/settings-heading`,
  `welcome.blade.php`, the Flux overrides used only by those layouts, and their tests (`tests/Feature/Auth/*`,
  `Settings/*`, `DashboardTest`, both `ExampleTest`s). Grep proves nothing still references each before it goes.
- **`config/fortify.php`:** CLAUDE.md puts `config/` off limits without asking. Ask the owner at build start; if
  declined, leave the file and note it.
- **One status-ordering class** (`StoryStatusOrder` or similar) holding `rollup()` and `ordered()`, and one
  "open statuses" constant. `ReadProjectStories` and `StoryPickWriter` use it; neither reaches into
  `ListWhatNeedsMe` or `ReadProjectProgress` any more.
- **One mockup-gate parser:** a pure class with the gate-section regex, `pickedLetter`, `refusalFor` and
  `boardBranch`, used by `ReadMockupGate`, `ReadMockupSets` and `StoryPickWriter`. `StoryPickWriter` keeps only
  writing.
- **One project-page trait** for the copied `#[Locked] $project`, `$gone` and `hydrate(CheckProjectShown)` block,
  used by `ProjectStories`, `ProjectPreflight`, `ProjectHandbook`, `ProjectAppMap` and `MockupViewer`.
- Move `MockupFileController::TYPES` to where `MockupSetFileController` owns it.
- **Port action-level coverage off the pages RD-9 deletes:** direct tests for `AddProject`, `SwitchProject`,
  `RemoveProject`, `RenderStory`, `FindStoryVersion::handle()` and the `AssignRequestId` middleware;
  `ProjectRegistryTest` stops asserting on `GET /`.

## Out of scope (do NOT build)
- Deleting any board page, Livewire board component or board Blade component (RD-9).
- Dropping tables or columns, including `users` and `passkeys` (a destructive change; not asked for).
- Any visual change.
- Changes to `preflight.sh` or the meter.

## Acceptance criteria (executable — these become the Pest test names)
- Given the app, when `/dashboard`, `/settings/profile`, `/login` or `/register` is requested, then each is 404.
- Given the board pages, when each existing board route is requested, then each still returns 200 as before.
- Given `ReadProjectStories`, then an arch test shows it depends on neither `ListWhatNeedsMe` nor `ReadProjectProgress`.
- Given a story with a chosen option written in bold, when the gate parser reads it, then the pick is found, and
  `ReadMockupSets` and `StoryPickWriter` give that same answer.
- Given a disabled project, when a component using the project-page trait hydrates, then it redirects as before.
- Given each ported action test, then it passes without loading a board page.
- Given the full suite, Pint and PHPStan, then all are green.

## Applicable standards
- Design: n/a.
- Codebase/DB: codebase-standards §Architecture (single-purpose actions), §Comments (docblock on every new class and
  public method). No migrations.
- Logging: no new events; existing event names unchanged.

## Design mockup gate (visual stories only — else "n/a — non-visual")
n/a — non-visual

## Do NOT touch
- `preflight.sh`, `bin/`, `.claude/`.
- Board Livewire components and views, other than adding the trait.
- Migrations.

## Data & interfaces
- Schema/migrations: none.
- Routes removed: the starter-kit auth, settings and `dashboard` routes. New classes: status order, gate parser,
  project-page trait.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: open `/`, `/p/story-board/stories`, `/p/story-board/preflight`, `/p/story-board/handbook` and
  `/mockups`; each looks and behaves as before.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: none
