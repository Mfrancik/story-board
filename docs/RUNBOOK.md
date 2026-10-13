# Runbook — bugs we've solved before
Check here BEFORE debugging anything twice. Append-only; newest first.
Each entry cites the log events that identified the root cause.

<!-- Entry format:
## <date> — <short symptom>
Symptom: <what was observed>
Root cause: <what it actually was>
Fix: <what resolved it> (commit/story ID)
Log trail: <event names / request_id pattern that revealed it>
-->

## 2026-10-12 — The post studio closes right after a Rebuild (or any change)
Symptom: in the post studio, pressing Rebuild (and sometimes any other change) closed the modal, although the change was saved. It happened only on some page shapes: after the idea menu was seeded mid-session on the campaign page, after a campaign was paused on the campaigns list, or once a campaign had its first past post.
Root cause: `<livewire:marketing.post-studio>` had no `wire:key` on either host page. Livewire then derives the child's key from the loop counters around the tag. A non-empty `@forelse` never closes its loop counter (in the compiled view `closeLoop` sits in the empty branch), so the count depends on how many loops ran and which were empty. When the host re-rendered on `marketing-changed` with a different loop shape, the studio's key changed and Livewire mounted a fresh studio, which starts closed.
Fix: an explicit `wire:key="post-studio"` on both hosts (`campaign-page.blade.php`, `campaign-list.blade.php`) (MK-9, `d415a49`). Rule: every `<livewire:>` child needs an explicit `wire:key`. The browser tests in `tests/Browser/Marketing/PostStudioTest.php` seed the idea menu and pause a campaign mid-session to cover both hosts.
Log trail: none for the remount itself (a remount runs `mount()`, not `open()`, so no `marketing.studio.opened`). The write's own line, e.g. `marketing.ai.revise.succeeded`, is logged and then the studio is gone.

## 2026-10-12 — A second Http::fake for Claude is ignored in the same test
Symptom: in the marketing journey, Fill's Claude reply worked but the later Revise got the drafts-shaped reply, so `ReviseDraft` failed as `invalid_reply` (no `caption`), even though a second `fakeClaude([...caption...])` had been registered for it.
Root cause: `Http::fake()` stubs stack, and the first stub whose URL pattern matches answers every request. Both stubs matched `api.anthropic.com/*`, so the second was never reached.
Fix: register one fake whose payload carries both keys, `drafts` (read by `DraftOccurrence`) and `caption` (read by `ReviseDraft`); each reader ignores the other's key (`tests/Browser/Journeys/MarketingPostTest.php`, MK-9). Use `Http::fakeSequence()` instead when the replies must differ in order.
Log trail: `marketing.ai.revise.failed` with `reason: invalid_reply` straight after a good `marketing.ai.draft.succeeded`.

## 2026-10-12 — Tests in a worktree collide with another tree's run, or Pest fails silently
Symptom: during MK-8, Pest runs in a `.claude/worktrees/*` checkout could not be trusted while another tree also ran tests, and some failing runs printed nothing at all.
Root cause: `phpunit.xml` pins `DB_DATABASE=story_board_test`, so every worktree and the main checkout share one test database, and `RefreshDatabase` in one tree resets it under another. The silent failures are an output-plugin issue that `PAO_DISABLE=1` switches off.
Fix: give each tree its own database. Prefix the run with `DB_DATABASE=story_board_test_<tree> php artisan test …`, after creating that MySQL database. When Pest prints nothing on a failure, also prefix `PAO_DISABLE=1` to get the plain report (MK-8).
Log trail: none; test tooling.

## 2026-10-12 — Saving a campaign logged `topics` as changed when the topics were not touched
Symptom: after Save on the campaign page, `marketing.campaign.updated` listed `topics` (and sometimes `days`) in `changed` although the owner had only edited the time. Tests asserting topics with `toBe` failed on key order.
Root cause: MySQL stores JSON objects in its own key order and returns `{"own":…,"ideas":…}`, not the `{"ideas":…,"own":…}` that was written. Eloquent compares JSON casts strictly, so the same value in a different key order counts as dirty.
Fix: `SaveCampaign::handle()` compares `days` and `topics` through `canonical()` (keys sorted recursively) and leaves them out of `fill()` when they are equal, so `changed` lists only real edits. Tests compare topics with `toEqual` (MK-7). Any JSON column compared on save needs the same treatment.
Log trail: `marketing.campaign.updated` with `changed` containing `topics` when only another field was edited.

## 2026-10-12 — A big photo was refused with "12288 kilobytes", and later picks kept failing
Symptom: in Marketing settings, a photo over 12 MB was refused with Livewire's message naming 12288 kilobytes, not the page's 10 MB rule. After any refused pick, the next good pick failed too.
Root cause: Livewire validates temporary uploads against its own 12 MB cap before `updatedPhotos()` runs. Separately, Livewire appends each new pick to the existing `photos` array, so a rejected file stayed in it and failed every later validation.
Fix: `app/Livewire/Marketing/Settings.php` aliases `WithFileUploads::_uploadErrored` and rethrows with "Photos can be 10 MB at most." (or the logo's 512 KB). Both it and `updatedPhotos()` reset `photos` on a refusal (MK-2, `76e2101`).
Log trail: none for the cap (it fails before our code); a stored photo logs `marketing.asset.stored`.

## 2026-10-12 — Browser-test uploads arrive with no files
Symptom: in MK-2's Pest browser test, choosing photos in Settings stored nothing; `photos` came back empty.
Root cause: Pest browser's `LaravelHttpServer` builds the request without files (`[], // @TODO files...` in the vendor source), so Livewire's upload endpoint receives none.
Fix: the browser test seeds favourites through `StoreMarketingAsset` and checks only display, serving and delete; uploads are covered by `tests/Feature/Marketing/MarketingSettingsTest.php`. `updatedPhotos()` returns early on an empty array and logs `marketing.asset.upload_empty` rather than toasting "Added 0 photos" (MK-2).
Log trail: `marketing.asset.upload_empty`.

## 2026-10-12 — Guard tests passed with the guard removed
Symptom: during MK-2, removing the 20 MB photo limit, the logo ref check, or the font-name rule left their tests green.
Root cause: the tests did not depend on the guard they named; their inputs were refused, or passed, for some other reason.
Fix: the three tests were tightened and two more added (MK-2, `76e2101`). When writing a guard test, delete the guard once and watch the test fail.
Log trail: none; test design.

## 2026-10-12 — Every project page scrolled sideways at 375 px after the Marketing tab got its count
Symptom: during MK-1, the 375 px browser check of `/p/coins/marketing` found the document wider than the viewport, although the tab row is `overflow-x-auto`.
Root cause: the Marketing tab's count carries `sr-only` text, which is `position: absolute`. The tabs `<nav>` was not positioned, so the span was placed relative to an ancestor outside the scroll box, escaped its clipping and widened the page. This is the same mechanism as the 2026-09-29 status-chip entry.
Fix: `relative` on the tabs nav in `resources/views/components/board/project-tabs.blade.php` (MK-1, `1f54714`). It affects every project page. Any `sr-only` inside an overflow box needs a positioned ancestor inside that box.
Log trail: none; visual. Caught by `tests/Browser/Marketing/CampaignListTest.php`'s no-sideways-scroll case.

## 2026-10-12 — New Tailwind classes unstyled in browser tests, and `npm run build` refused
Symptom: MK-1's browser tests saw the new marketing classes unstyled. `npm run build` was blocked by the session's permission check.
Root cause: the browser suite serves `public/build`, which predated the new classes (see the 2026-09-30 entry). Vite empties `public/build` before writing, which the permission check treats as a destructive delete.
Fix: `npx vite build --emptyOutDir=false` writes the new bundle beside the old one, and the new manifest points at it (MK-1). `public/build` is gitignored, so the leftover files are harmless; clear them by hand if they pile up.
Log trail: none; visual or test-only.

## 2026-10-12 — Campaign rows stayed stacked at 1280 px
Symptom: the campaigns list's rows were meant to switch to columns at desktop width, but at a 1280 px window they stayed stacked.
Root cause: the switch was first at `xl` (1280 px). The shell sidebar plus a vertical scrollbar keep the page's viewport width under 1280 at a 1280 px window, so `xl` never applied.
Fix: rows switch at `lg` (1024 px) in `resources/views/livewire/marketing/partials/campaign-row.blade.php` and the header row (MK-1). Do not put a desktop layout's breakpoint exactly at the test window width.
Log trail: none; caught by the 1280 px browser case counting a row's distinct left edges.

## 2026-10-12 — Browser test reads 3 columns on a 4-column grid
Symptom: RD-11's browser test for the project overview's 4 → 2 → 1 grid reported 3 columns at desktop width, although the page visibly laid out 4.
Root cause: the test counted distinct `x` positions of the tiles. Every tile but Needs you and Live now spans two columns, so at 4 columns no tile ever starts in column 2 — three distinct left edges for four columns.
Fix: count the grid's own columns, not the tiles' positions: `getComputedStyle(grid).gridTemplateColumns.split(' ').length` (`OVERVIEW_COLUMNS` in `tests/Browser/ProjectOverviewTest.php`, RD-11). Use this for any grid with spanning items.
Log trail: none; a test-only bug, seen as a failing `assertScript(…, 4)`.

## 2026-10-11 — Project tiles show no new production figure
Symptom: an overview project tile's production row keeps yesterday's (or an older) figure, or drops off after 7 days, although the connection tests fine on `/projects`.
Root cause: since RD-9 deleted `/prod` and its `ReadProductionMetrics` job, no page load reads production. The only read is `board:prod-snapshot` at 23:55 in `board.timezone`, which needs the scheduler, and `composer run dev` does not start it. The tiles show stored `ProdSnapshot` rows only (ADR-054).
Fix: run `php artisan schedule:work` beside `composer run dev`. For a figure now, run `php artisan board:prod-snapshot` (RD-9, `2669db7`; see [retired pages](features/retired-pages.md)). Past days cannot be back-filled.
Log trail: no `board.prod_snapshot_run` that night and no `board.prod_read`. If it ran but a project failed: `board.prod_snapshot_run failed>0` and that project's `board.prod_read_failed reason=<code>`.

## 2026-10-11 — A missing-field skip test stayed green with the missing-field guard deleted
Symptom: during RD-8's build, the "no `runner` field is skipped" case stayed green when the mutation check deleted the required-field loop in `ReadPreflightRuns::read()`.
Root cause: `runner` is also value-checked a few lines later (`isset(RAN_ON[...])`), so a record with no `runner` was still skipped and counted, just with reason `runner` instead of `missing runner`. The case proved a skip happened, not which guard caused it.
Fix: the skip dataset in `tests/Feature/Board/PreflightRanOnTest.php` gained a `no gates` case; `gates` is guarded only by the missing-field check, so that case goes red without it. The shipped test also pins the logged `reason` exactly (RD-8, `5811fe3`). When a field has two guards, mutation-check each with a case only that guard catches.
Log trail: `board.preflight_run_record_skipped` `reason`: `runner` where `missing runner` was expected.

## 2026-10-11 — A `git log --since` fixture silently dropped commits
Symptom: a Shipped this week test seeded several build commits inside the 7-day window, but `GitReader::log()` returned fewer than seeded, with no error.
Root cause: `git log --since` stops walking history at the first commit older than the cutoff. Fixture commits made with out-of-order dates (a newer one committed before an older one) put an "old" commit between HEAD and the rest, so git stopped there and never saw the in-window commits behind it.
Fix: `tests/Support/GitFixture.php:commitAt()` sets author and committer dates, and tests write commits in date order (RD-7, `4d12af1`). Real repositories with skewed committer dates (rebases, clock drift) can hit the same cut-off; that is git's behaviour, accepted.
Log trail: `board.shipped_read` `count` lower than the commits seeded; no `board.shipped_unreadable`.

## 2026-10-11 — A once-per-file-version warning test stayed green with its guard deleted
Symptom: the RD-5 test for `board.live_session_unparsed` ("logged once per file version") passed with the `Cache::add` guard in `SessionReader::unparsed()` removed.
Root cause: the test read the page twice, but `SessionReader::live()` caches its whole scan for 20 s under `board.sessions`. The second read came from that cache, never re-parsed the file, so it could not log a second warning with or without the guard.
Fix: the test calls `Cache::forget('board.sessions')` between the two reads (`tests/Feature/Board/HomeOverviewTest.php`), so the second read re-parses the file; it now goes red without the guard (RD-5, `6d6f3de`). Any test of reader-level behaviour across reads must clear `board.sessions` first.
Log trail: count `board.live_session_unparsed` lines per `file`; with the scan cached, a second request logs no `board.sessions_read` either.

## 2026-10-11 — A browser test for the shell's appearance stub stayed green with the stub deleted
Symptom: a test saving `flux.appearance = light` on an old page, then navigating into the shell and back, passed with the stub in `resources/views/partials/head.blade.php` removed.
Root cause: `flux.js` captures `window.Flux.applyAppearance` once, at `alpine:init` on the first full page load, and reuses it on every `wire:navigate`. A session that starts on an old page captures the real `@fluxAppearance` function, so the stub never runs.
Fix: add a leg that starts with a full load of a shell page (`/p/fx/stories`), then navigates to an old page; that leg goes red without the stub (RD-4, `951e540`, [ADR-045](decisions/ADR-045-flux-toasts-in-the-shell-behind-an-appearance-stub.md) amendment). General lesson: mutation-check a guard test by deleting the guard; it must go red.
Log trail: none. Compare `localStorage['flux.appearance']` after each leg.

## 2026-10-11 — Old pages forgot their light/dark choice after visiting a shell page
Symptom: once `layouts/shell` loaded `flux.js` (for toasts), a light/dark choice saved on an old `layouts/board` page was lost after opening any shell page.
Root cause: `flux.js` captures `window.Flux.applyAppearance` once, on the first full page load, and calls it on load and after every `wire:navigate` swap. When a session starts on a shell page, which skips `@fluxAppearance`, nothing has defined it, and Flux's fallback clears the saved appearance.
Fix: `resources/views/partials/head.blade.php` defines a shell-only stub `window.Flux = { applyAppearance() }` that only removes `.dark` from a `.shell` `<html>` and never touches the saved choice (RD-4, [ADR-045](decisions/ADR-045-flux-toasts-in-the-shell-behind-an-appearance-stub.md)). Any new layout that loads `@fluxScripts` without `@fluxAppearance` needs the same.
Log trail: none. Check `localStorage['flux.appearance']` before and after visiting a shell page.

## 2026-10-11 — A shell page drew in old light/dark colours after navigating from an old page
Symptom: going from an old `layouts/board` page to a shell page by `wire:navigate` could leave `.dark` on `<html>` beside `.shell`, and the old `.dark` token block then competed with the shell's.
Root cause: an old page's appearance script adds `.dark` to `<html>`; the swap keeps it until something removes it. The risk pre-existed for Preflight (RD-2) and grew with RD-4's pages.
Fix: `resources/css/app.css` matches `.shell, .shell.dark` for the shell's token re-pointing, so the shell's colours win either way; the head stub above also strips `.dark` on shell pages (RD-4).
Log trail: none. Inspect `<html>`'s class list after the navigation.

## 2026-10-11 — `php artisan test --filter=…` ran browser tests unlocked
Symptom: a quick `--filter` run for feature tests also launched `tests/Browser` files, outside `.claude/browser-lock.sh`, where they can clash with another session's browser run.
Root cause: `--filter` with no suite or path matches test names in every suite, Browser included.
Fix: for non-browser runs always pair the filter with a scope: `php artisan test --testsuite=Feature,Unit --filter=Name` or `php artisan test tests/Feature/... --filter=Name`. Run browser tests only through the lock (RD-4).
Log trail: none. The run's output lists `Tests\Browser\…` files.
Enforced since 2026-10-11 (L-7): `tests/Pest.php` fails any Browser test run outside `bin/browser-lock.sh`, so a bare `--filter` now stops with "Browser tests run only under the lock" instead of running unlocked.

## 2026-10-11 — Story page's status tag text came out broken
Symptom: on the RD-3 story page, the status tag's text (status plus "· awaiting your pick") rendered garbled once the suffix was added with an inline `@if` inside the tag.
Root cause: Livewire wraps every Blade `@if` block in morph marker comments (`<!--[if BLOCK]><![endif]-->`). Inside a tag's inline text, those markers are injected into the label.
Fix: build the label as one PHP string expression inside the tag, `{{ ($story->status ?? 'no status').($awaiting ? ' · awaiting your pick' : '') }}` (`resources/views/livewire/board/story-page.blade.php`; RD-3). In a Livewire view, compute conditional text in PHP, not with `@if` inside an inline element.
Log trail: none. It is visual, so check the rendered HTML for `[if BLOCK]` comments.

## 2026-10-11 — A page test hook matched the shell sidebar too
Symptom: story page assertions on the mockups section hook `data-mockups` matched in the RD-2 shell even when the story had no mockups.
Root cause: the shell sidebar carries `data-mockups-count`, and an attribute-prefix selector or string match on `data-mockups` hits both.
Fix: the section's hook is `data-story-mockups` (RD-3). Name a page's test hooks after the page, not after a word the shell already uses.
Log trail: none. It shows up as a false pass or fail in `StoryPageTest`.

## 2026-10-11 — `package-lock.json` renamed to the worktree folder after `npm install`
Symptom: after installing a package in a worktree, `package-lock.json`'s top-level `"name"` (and `packages[""].name`) changed from `story-board` to the worktree folder, for example `rd-2`.
Root cause: npm writes the lock's name from the directory it runs in when `package.json` has no `name` field of its own. A worktree checkout's folder is named after its branch.
Fix: reset both name fields to `"story-board"` before committing, and check `git diff package-lock.json` for a name-only hunk (RD-2).
Log trail: none. It shows up in `git diff`.

## 2026-10-11 — A stray `@emnapi/runtime` stayed in the lock after swapping packages
Symptom: after removing one npm package (`geist`) and installing another (`@fontsource-variable/*`), `package-lock.json` still listed `@emnapi/runtime`, which nothing depends on any more.
Root cause: most likely an orphan of the removed package's auto-installed peer tree (`geist` peers on `next`). Running the uninstall and install back to back did not prune it.
Fix: restore `package.json` and `package-lock.json` from `HEAD`, run `npm prune`, then install only the wanted packages. The lock diff should then hold only those packages (RD-2, see ADR-039).
Log trail: none. Check `git diff package-lock.json` for packages you did not ask for.

## 2026-10-11 — `composer remove` rewrites `.claude/` and agent files
Symptom: removing `laravel/fortify` also changed files under `.claude/` and the agent guideline files, which are on RD-1's Do-NOT-touch list.
Root cause: composer's post-update scripts run `boost:update`, which regenerates Laravel Boost's agent files.
Fix: run `composer remove <pkg> --no-scripts` (RD-1). Check `git status` for `.claude/` changes after any composer change.
Log trail: none. It shows only in `git status`.

## 2026-10-11 — A project made by `AddProject` has a null `state` in tests
Symptom: a direct action test asserted `state === 'pending'` on the project `AddProject` returned and got null.
Root cause: `state` is filled by a database column default. Eloquent does not hydrate defaults back into the model on `create()`.
Fix: re-read with `->fresh()` before asserting, as `ManageProjectsTest` does (`tests/Feature/Board/ProjectActionsTest.php`, RD-1).
Log trail: none. It is a test-only symptom, and the row in MySQL is correct.

## 2026-10-11 — `php artisan test --parallel tests/Feature tests/Unit` fails "Too many arguments"
Symptom: `Too many arguments, expected arguments "path"` when running the Feature and Unit suites in parallel by path.
Root cause: paratest accepts a single path argument.
Fix: `php artisan test --parallel --testsuite=Feature,Unit`. Confirm the scope by the test count, because Browser tests run separately (RD-1).
Log trail: none. The error comes from the CLI.

## 2026-09-30 — Uncaught `SecurityError` from Flux when localStorage is blocked
Symptom: SB-19's "localStorage throws" browser test, which stubs `Storage.prototype` `getItem` / `setItem` / `removeItem` to throw `DOMException('SecurityError')` for every key, hits an uncaught error before the page's own code runs, even though the Columns picker wraps all its storage calls.
Root cause: Flux reads `localStorage` unguarded for `flux.appearance`: once in the `@fluxAppearance` script in `<head>` and again in `flux.min.js` on `alpine:init`. A real browser with site data blocked hits the same throw on every board page. Pre-existing, outside SB-19.
Fix: none in Flux. The test's init script passes `flux.*` keys through to the real storage and throws for everything else, so it still proves the picker falls back to all columns (SB-19, `8e0fa41`). Any board code that touches storage must wrap it in try/catch, as `readHiddenColumns()` / `writeHiddenColumns()` in `resources/js/preflight-history.js` do.
Log trail: none; client-side only. Visible as a Pest browser failure naming `SecurityError` and `flux.appearance`.

## 2026-09-30 — Live cards on `main` list every untracked story in the main checkout
Symptom: two coins sessions on `main` (main checkout and worktree `prf7ch`) each listed ADMIN-16, ADMIN-23, BRAND-9… on their Live now card, all "Not on main · untracked in /Users/mikefrancik/Code/coins".
Root cause: `IndexOffMain` tags an untracked file with the branch of the checkout it sits in, so every untracked story in the main checkout had `branch = main`. `ListLiveSessions::links()` step 2 links off-main rows by branch alone, so any session on `main`, in any folder, claimed all of them. A "cwd sits under the row's root" check would not have been enough: worktrees live under the main checkout.
Fix: `ListLiveSessions::branchRows()` skips the fallback on the project's default branch, and links an untracked row only when its root equals the session's own (deepest) checkout root (SB-25, `f747e88`, ADR-034).
Log trail: `board.session_links_filtered` (debug) with `rule` `default_branch` or `untracked_checkout` and `dropped`. Before the fix there was no trace; the card itself was the symptom.

## 2026-09-30 — Journey shots stop being written after a Pest upgrade
Symptom (anticipated, not yet seen): with `JOURNEY_SHOTS=1`, journey tests error in `JourneyShots::capture()` (undefined method on `Pest\Browser\Support\Screenshot`, or `File::move()` cannot find the source), or PNGs pile up in `tests/Browser/Screenshots/`.
Root cause: Pest can only save a screenshot into its own `tests/Browser/Screenshots/`, so the helper saves there under a random name and moves it out using `Screenshot::path()` and `Screenshot::dir()`, both `@internal`. Pest may rename or move them in any release.
Fix: re-point `tests/Support/JourneyShots.php:capture()` at wherever the new Pest writes `screenshot(true, $name)`, then re-run `tests/Browser/JourneyShotsTest.php`, whose first criterion fails first when this breaks (SB-22, `0f7ae8b`). Fix it in the kit copy too, since every project carries the same file.
Log trail: none; test tooling logs nothing. The PHP error names `Screenshot` or the missing temp file `journey-shot-<hex>.png`.

## 2026-09-30 — Browser test fails after a change to resources/js, though Feature tests pass
Symptom: SB-23's browser test for "opens in compare, Current left" failed on its first run; the viewer opened as before (one option, compare off) even though the server rendered `data-compare-open="true"`.
Root cause: the browser suite loads the built assets in `public/build`, not Vite's dev server. `mockupViewer(cfg)` in `resources/js/mockup-gallery.js` had been changed to take `compare`/`left`/`right` from the server, but the old bundle still hard-coded `compare: false`.
Fix: `npm run build`, then re-run the browser suite (SB-23, `079a2c1`). Any change under `resources/js` or to Tailwind classes needs a rebuild before `tests/Browser`.
Log trail: none; `board.mockup_viewed` already logged `compare: true`, so a log saying compare while the page shows a single option points at stale assets.

## 2026-09-29 — Project tabs scrolled 1 px vertically after they were made to scroll sideways
Symptom: with a fifth tab (App map), the project tab row overflowed a 375 px phone. Making it `overflow-x-auto` fixed the width, but the row then also scrolled up and down by 1 px and showed a vertical scrollbar.
Root cause: the active tab's underline uses `-mb-px` to sit on the row's `border-b`. Once the row is a scroll container, that 1 px overhang counts as overflow, and `overflow-x-auto` forces `overflow-y` to `auto` too.
Fix: `pb-px` on the `<nav>` gives the underline room inside the scroll box. Each tab is also `shrink-0` so labels do not squash (`resources/views/components/board/project-tabs.blade.php`, SB-24). Any `-mb-px` tab row inside an overflow container needs the same padding.
Log trail: none; visual. Found in the 375 px browser check (`tests/Browser/ProjectAppMapTest.php` "fits a 375px phone…").

## 2026-09-29 — "Same number of queries" tests failed once the sidebar counted mockups
Symptom: after SB-21 added the sidebar's awaiting-pick count, `ProjectPageTest` "runs the same number of queries whatever the number of initiatives" (and its `ProjectStoriesTest` twin) failed: the first render ran more queries than the second. (Both pins superseded: RD-9 deleted `ProjectPageTest`; the Stories page's query count is pinned by `ProjectStoriesTest`'s one-query test.)
Root cause: an early `ReadMockupSets` cached whole sets. A cold render queried the snapshot and filled the cache, and a warm one skipped the query, so the query count depended on cache state rather than on the page.
Fix: rows are read fresh on every call, always two queries (`ReadMockupSets::handle()`). Only git results are cached: gate readings per commit (`board:mockup-gates:{id}:{sha}`) and the local-branch pick check (`board:mockup-local:{id}`) (SB-21). Cache what is expensive (git), not what a query-count test measures.
Log trail: none; found by the query-count assertions (`DB::getQueryLog()` lengths differing between the two renders).

## 2026-09-29 — No production snapshot for a day
Symptom (`/prod` was deleted in RD-9; today's form of this is "Project tiles show no new production figure" above): a project's trend line on `/prod` has a gap for a day, and the change against that day shows "—", although the connection was fine.
Root cause: nobody opened `/prod` that day, and `board:prod-snapshot` (scheduled 23:55 in `board.timezone`) never ran. `composer run dev` starts the server, queue worker, pail and Vite, **not the scheduler**.
Fix: run `php artisan schedule:work` beside `composer run dev` (owner chose doc-only, no `composer.json` change; SB-18, `74e1732`, ADR-031). To fill today by hand, run `php artisan board:prod-snapshot`. Past days cannot be back-filled: production is read as of now.
Log trail: no `board.prod_snapshot_run` that night. If it ran but a project failed: `board.prod_snapshot_run failed>0` and that project's `board.prod_read_failed reason=<code>`.

## 2026-09-29 — Production dashboard tests: queued reads that never ran
Symptom: while writing SB-18's tests, rows stayed on the skeleton, a second read was silently dropped, or an in-order text assertion failed on text that was plainly on the page.
Root cause: three separate traps. (1) A `ShouldBeUnique` job run by hand (`$job->handle(...)`) never releases its unique lock, so the next dispatch for that project is dropped as a duplicate. (2) `dispatchSync` through a `Queue::fake()` never runs the job. (3) Livewire `call()` responses are JSON-escaped, so `assertSeeInOrder` on the response misses text with quotes, dashes or `<`.
Fix: the helpers `runProdReads()` / `workProdQueue()` run each pushed `ReadProductionMetrics` and then release its lock with `(new UniqueLock(app(Cache::class)))->release($job)` (`tests/Feature/Board/ProductionDashboardTest.php`, `tests/Browser/ProductionDashboardTest.php`; SB-18, `74e1732`). In-order checks use a regex on `->html()`. Apply the same lock release to any unique job a test runs by hand. (The job, the helpers and both files no longer exist: RD-9 deleted `/prod`. The lock lesson stands.)
Log trail: none for (2) and (3). For (1), no second `board.prod_read` after a Refresh in the test log.

## 2026-09-29 — A production metric using SLEEP() "succeeded" instead of timing out
Symptom: while writing SB-17's timeout test, `SELECT SLEEP(10)` on a session with `max_execution_time = 5000` came back after about 5 s with value `1` and no error. The metric looked fine.
Root cause: MySQL interrupts `SLEEP()` at the limit and returns 1. It does not raise error 3024 the way a real long-running SELECT does. A three-way cross join of `information_schema.COLLATIONS` finishes in about 2.5 s, so it does not trip the limit either.
Fix: `ProductionReader::read()` treats error 3024 **and** any result that took at least 5 s minus 50 ms as `timed_out`. The test uses a four-way `COLLATIONS` cross join, which does raise 3024 (SB-17, `c8bcfbe`). Test time limits with a genuinely heavy query, never with `SLEEP()`.
Log trail: `board.prod_metric_tested` with `result=timed_out`, `reason=timed_out` and `duration_ms` of about 5000.

## 2026-09-29 — Test fixture's MySQL users and database were never dropped
Symptom: after an SB-17 test run, `sb17_prod_*` databases and `sb17ro_/rw_/al_` users were still on the local server. The only sign was `"errors":1` in the agent reporter's summary. No test failed.
Root cause: `ProductionFixture::drop()` runs in `afterAll`, after the Laravel app is torn down. It read the root login with `config()`, which throws at that point, and the error was swallowed outside any test.
Fix: the fixture stores the board test DB's host, port and root login in its constructor while the app is up, and `drop()` uses those (`tests/Support/ProductionFixture.php`; SB-17, `c8bcfbe`). Any `afterAll` cleanup must hold what it needs itself, never call `config()`, `app()` or facades.
Log trail: none. `"errors":1` in the reporter, or `SELECT user FROM mysql.user WHERE user LIKE 'sb17%'` showing leftovers.

## 2026-09-29 — Production Test result vanished right after saving
Symptom: on `/projects`, saving a Production connection showed the check result for a moment, then the panel reloaded showing "Loading production settings…" and the result was gone. The panel could also show a connection as missing just after a save.
Root cause: to update the row's "Read-only · N metrics" summary, the parent `ManageProjects` was re-rendered. That re-mounted the lazy `ProductionSettings` child and reset its state. Separately, the parent's `Project` instance carried a cached `prodConnection` relation, so a freshly mounted child could read stale data.
Fix: the panel dispatches a `prod-summary` browser event that the row's Alpine applies, so the parent never re-renders. `ProductionSettings::mount()` takes `$project->withoutRelations()` (SB-17, `c8bcfbe`). Never refresh a parent to update a lazy child's surroundings. Use a browser event.
Log trail: none; `board.prod_connection_saved` is logged normally, the loss is client render state only.
## 2026-09-29 — Preflight tab 404s, or shows no runs, for a project that has a cost CSV
Symptom: during SB-16's real-data check, `/p/story-board/preflight` returned 404 even though `~/.claude/projects/-Users-mikefrancik-Code-story-board/preflight-cost.csv` holds 9 runs. Separately, a registered project whose path differs in case from Claude's folder showed the empty state.
Root cause: the 404 happens because story-board is not a registered project in the dev DB. `EnsureProjectIsShown` refuses any unregistered or disabled name, and the page has nothing to key on. The case mismatch happens because the registered path `/Users/mikefrancik/code/story-board` encodes to `-Users-mikefrancik-code-story-board`, while Claude named the folder after the path it saw, `…-Code-…`.
Fix: register the project (`/projects` → Add, or `board:project`). Folder matching in `ReadPreflightHistory::files()` is case-insensitive, so the case mismatch is handled (SB-16, `baeeeb7`). If runs are still missing, compare the empty state's "looked in" paths with `ls ~/.claude/projects | grep <name>`. Only the exact name, `--claude-plans`, and `--claude-worktrees-*` folders count.
Log trail: `board.project_page_refused` for the 404. `board.preflight_history_viewed` with `runs: 0`, and `board.preflight_history_unreadable` if the root itself is missing.

## 2026-09-29 — PHPStan rejects a story collection passed to `ReadProjectProgress::rollup()`
Symptom: `vendor/bin/phpstan analyse` failed on `ReadProjectStories` after `rollup()` was extracted. It reported an Eloquent collection where `iterable<stdClass>` was expected.
Root cause: grouping an Eloquent `Collection` of `Story` models gives nested model collections, and the rows built from them are plain objects, not models. PHPStan correctly refuses to treat that as `iterable<stdClass>`.
Fix: `->toBase()` before grouping in `ReadProjectStories::handle()`, and `rollup()` typed as `iterable<stdClass>` (SB-15). Any new caller must pass plain rows, for example a `toBase()` aggregate.
Log trail: none; static analysis.

## 2026-09-29 — Handbook lesson counts were one too high (17/6/3, not 16/5/2)
Symptom: SB-14's first real-data counts of `## L-<n>` entries (coins 17, client-dashboard 6, rent-track 3) were each one more than the ledgers actually hold.
Root cause: the kit's `docs/LESSONS.md` carries its entry format as a commented-out `## L-<n>` template inside `<!-- -->`. A line-based `^## L-` match counts it as an entry. A heading inside a code fence would be miscounted the same way.
Fix: `app/Actions/Board/ReadHandbook.php:parseLessons()` strips HTML comments and tracks ``` / ~~~ fence state before matching `## L-`. The story's counts were corrected to 16/5/2 before the build, with owner approval (`1e727bb`; SB-14, `95314c6`). Pinned by `ProjectHandbookTest` "ignores `## L-` headings inside HTML comments and code fences". Count kit ledgers with a parser that skips comments and fences, never with `grep -c '^## L-'`.
Log trail: none; a count mismatch. `board.handbook_viewed` with `section=lessons` marks the read.

## 2026-09-29 — Board tests read the developer's real `~/.claude/projects`
Symptom: once SB-11 added the Live now panel and the sidebar badge, every board page scanned the transcripts root. Existing suites that never mention sessions (home, project page, app shell) would have read the developer's real session files, so results depended on which terminals were open.
Root cause: `board.sessions_path` defaults to `$HOME/.claude/projects`, and the panel and badge render on every board page. Any test that renders a page reads the configured root.
Fix: `tests/TestCase.php:setUp()` sets `board.sessions_path` to an empty temp folder (`<tmp>/story-board-sessions/empty`) for every test. Tests that need sessions point it at a `Tests\Support\SessionFixture` root (SB-11, `76ec1eb`). Any future feature that reads outside the repo by default needs the same base-class override.
Log trail: `board.sessions_read` in a test run with a `files` count the fixtures did not create, or `board.session_ignored` lines naming real `cwd`s.

## 2026-09-29 — A blank Add project path would register the board itself
Symptom: submitting Add project with an empty Path would have registered story-board's own checkout as a project instead of refusing.
Root cause: `RegisterProject` resolves the path with `realpath()`, and PHP's `realpath('')` returns the current working directory. For the web app that is the board's own checkout, which is a git repository, so every check passed.
Fix: `AddProject::handle()` refuses a blank path (after trimming and `~` expansion) before calling `RegisterProject`, with reason `missing_path` and "Enter the folder of a git checkout." (SB-12, `617f555`). Pinned by `ManageProjectsTest` "refuses a blank or missing path with reason missing_path, expanding ~ first". Anything else that feeds user input to `realpath()` needs the same blank check.
Log trail: `board.project_add_refused` with `reason: missing_path` and an empty `path`. Without the fix you would see `board.project_registered` with the board's own path.

## 2026-09-29 — A project switched off mid-visit kept rendering its rollup
Symptom: with `/p/coins` open, switching coins off and then clicking a kind button still re-rendered its initiatives; a removed project 404'd the Livewire request with no log line (L-5).
Root cause: `EnsureProjectIsShown` is route middleware, and Livewire update requests (`/livewire/update`) never pass through the page's route middleware, so only the initial GET was guarded. Test trap: after `assertRedirect()`, Livewire's test harness still holds the previous HTML, so `assertDontSee('secret-rollup')` passes against stale markup and proves nothing.
Fix: `ProjectPage::hydrate()` re-runs `CheckProjectShown` on every update request, logs `board.project_page_refused` (`request: update`), redirects home, and `render()` returns an empty `<div>` (SB-10, `e9a2f6e`). The test proves the rollup is not read by mocking `ReadProjectProgress` with `shouldNotReceive('handle')`. Any Livewire page guarded by route middleware needs the same `hydrate()` re-check. Today that is `app/Livewire/Board/Concerns/GuardsProjectPage.php:hydrate()` (RD-1), on every kept project page; `ProjectPage` itself was deleted in RD-9.
Log trail: `board.project_page_refused` with `request: update` and `reason` `disabled` / `unknown`; before the fix, nothing after `board.project_viewed`.

## 2026-09-29 — A query-count test failed (15 vs 17) with no growth in the page
Symptom: SB-10's "same number of queries whatever the number of initiatives" test counted 15 queries before adding initiatives and 17 after.
Root cause: the fixture's What needs me cards started empty. An empty card skips its eager-load query, so the "after" run (which added open work) paid two queries the "before" run never made. Empty vs filled looked like growth.
Fix: the test starts with non-empty cards and an off-main row, so both runs take the same code paths (`tests/Feature/Board/ProjectPageTest.php`, SB-10; the file no longer exists, deleted with the page in RD-9). Any query-count test must fill every conditional branch in its baseline.
Log trail: none; compare `DB::getQueryLog()` of the two runs.

## 2026-09-29 — Browser test timed out clicking "Refresh this project" by its text
Symptom: in `tests/Browser/ProjectPageTest.php`, a click by the text "Refresh this project" timed out, and an assertion on a branch name failed Playwright strict mode.
Root cause: the text click likely resolved against the button's hidden `wire:loading` span beside the label (unconfirmed). The branch name appears on several off-main rows, so a text locator matched more than one element.
Fix: the button carries `data-refresh-project` and the test clicks that; the branch check is a script assertion over the rows (SB-10). Prefer `data-*` hooks to text locators for Livewire buttons with loading states. (The page and its test no longer exist, deleted in RD-9; the lesson stands.)
Log trail: none; Playwright timeout and strict-mode errors.

## 2026-09-29 — A Pest test helper collided with another file's helper
Symptom: a new test file's helper function clashed with one of the same name declared in another test file.
Root cause: helper functions defined at the top level of Pest files are global across the whole suite. A helper named `offMain` already existed.
Fix: SB-10's helpers have names unique to the file (`seedInitiatives`, `addOffMain`, `initiativeRows`, `offMainPanel`, ...). Name top-level Pest helpers after their file's subject, or grep `tests/` before adding one.
Log trail: none; a PHP redeclare error when the suite loads.

## 2026-09-29 — A project tile 500'd with "ViewErrorBag could not be converted to int"
Symptom: `/` returned 500 once the SB-9 tiles rendered `<x-board.status-chip :status :count />`, with `Object of class Illuminate\Support\ViewErrorBag could not be converted to int`.
Root cause: the chip's prop is named `errors`. When a caller does not pass it, the `@props` default of 0 does not apply: the name resolves to the `$errors` `ViewErrorBag` that Laravel shares with every view, and `$errors > 0` then fails.
Fix: `status-chip.blade.php` coerces it, `$errors = is_int($errors) ? $errors : 0` (SB-9, `a26153b`). Never name a Blade prop `errors`, or any other variable Laravel shares with views. Pinned by every tile render in `tests/Feature/Board/AllProjectsDashboardTest.php`.
Log trail: none; a Blade render exception in `storage/logs/laravel.log` with the request's `request_id`.

## 2026-09-29 — `@endif@if` back to back did not compile
Symptom: a view with `@endif@if (...)` written with nothing between them failed to compile.
Root cause: Blade only treats `@word` as a directive when the character before `@` is not a word character. In `@endif@if`, the `f` before the second `@` stops `@if` from being matched.
Fix: put each directive on its own line or after whitespace. `status-chip.blade.php` now splits them over lines, with a comment on why that whitespace does not show (the chip is `inline-flex`, spaced by `gap`) (SB-9).
Log trail: none; a compile or render error.

## 2026-09-29 — Escape then Back left the board for about:blank
Symptom: with the story modal open, pressing Escape (sometimes followed by Back) navigated off the board to `about:blank`. Intermittent; the browser test failed on some runs only.
Root cause: the modal relied on Livewire's `#[Url(history: true)]`, which pushes the history entry only after the server round trip. Escape pressed before the response landed stepped back one entry more than had been pushed.
Fix: the modal view pushes history itself, before calling the server, and counts the entries (`depth`). Close steps back through exactly those, and a modal opened straight from a URL closes with `$wire.close()` without navigating ([ADR-015](decisions/ADR-015-story-modal-history-is-driven-by-alpine.md), SB-8, `f601c01`). Pinned by `tests/Browser/StoryModalTest.php` "closes on Escape, drops ?story from the URL and returns focus to the row" (no longer exists: RD-9 deleted the modal and its test; push-before-round-trip still applies to any Alpine-owned history).
Log trail: none; client-side. `board.story_modal_opened` looks normal. The symptom is the browser URL.

## 2026-09-29 — An unknown project's page 404'd with no log line
Symptom: `/p/nope` returned 404, but no `board.project_page_refused` was logged, so the refusal could not be explained from the log.
Root cause: Livewire 4 binds `{project:name}` inside `SubstituteBindings`. That runs before route middleware, so an unknown name 404'd in the binding before the guard ran.
Fix: `bootstrap/app.php` calls `prependToPriorityList(SubstituteBindings::class, EnsureProjectIsShown::class)`, so the guard runs first and reads the raw route string ([ADR-013](decisions/ADR-013-project-refusal-runs-before-route-binding.md), SB-7). Pinned by `StoryPageTest` "refuses the story page of an unknown or disabled project and logs board.project_page_refused with the reason" (moved from the old `AppShellTest` in RD-9, when bare `/p/{project}` was deleted). Any guard that has to see an unbound name needs the same priority entry.
Log trail: none, and that absence was the symptom. The request's `request_id` had no board event at all.

## 2026-09-29 — The story page for a branch version said "@ origin/main"
Symptom: a story page opened with `?v=` for a branch version showed its path as `@ origin/main <sha>`, so it looked like the ref's copy.
Root cause: the metadata line printed `$project->ref` for every row. Off-main rows carry their own `branch` and commit `sha`.
Fix: the line prints the project ref for ref rows, `<branch> <sha>` for branch and worktree rows, and the `location` for untracked rows (`resources/views/livewire/board/story-page.blade.php`; SB-5). Pinned by `NotOnMainPageTest` "labels a branch version with its branch and commit, not the project ref".
Log trail: none; visual. `board.story_viewed` carries `version` (the row's `location`) to confirm which row was shown.

## 2026-09-29 — client-dashboard's whole off-main scan failed
Symptom: client-dashboard had no Not on main rows at all, and each refresh logged `board.offmain_failed`.
Root cause: one branch (`standards/feat/feature-skill`) shares no history with main. `git merge-base` exits non-zero, `GitReader` throws, and the exception ended the whole scan.
Fix: `IndexOffMain::branchRows()` catches `GitReaderException` per branch, logs `board.offmain_branch_skipped` and continues (SB-5). Pinned by `NotOnMainTest` "skips a branch with no shared history and still indexes the others".
Log trail: `board.offmain_failed` with a merge-base error for `project=client-dashboard`. Now it is one `board.offmain_branch_skipped` naming the branch, followed by `board.offmain_indexed`.

## 2026-09-29 — The off-main scan found 0 untracked files in tests
Symptom: the fixture test with an untracked `docs/mockups/X-1/option-a.html` produced no untracked row.
Root cause: the mockup path pattern required a kit-style ID (two or more capital letters), but the story's own acceptance criterion uses `X-1`. The same filter would also have hidden oddly named real folders.
Fix: `IndexOffMain::MOCKUP_FILE` accepts any directory name under `docs/mockups/` (SB-5). Serving still requires a well-formed ID (`ReadMockupFile`, the `stories.show` route).
Log trail: `board.offmain_indexed` with `untracked: 0`.

## 2026-09-29 — "Not on main" flooded with hundreds of rows
Symptom: coins' Not on main listed 903 branch rows, mostly stories that main had built long ago, shown as draft or new on old branches.
Root cause: each branch tip was compared with today's main. A branch that forked before main built a story "differs" on that story even though the branch never touched it.
Fix: a file counts only if the branch changed it since its merge-base with the ref and it still differs from the ref. Stacked branches are deduplicated by `path@blob`. Coins went to 148 branch rows (132 stories); see [ADR-010](decisions/ADR-010-off-main-is-what-a-branch-changed-since-its-merge-base.md) (SB-5). Pinned by `NotOnMainTest` "does not report what main changed after an old branch forked".
Log trail: `board.offmain_indexed` `branch` count for `project=coins`.

## 2026-09-29 — Every story page view made two extra mockup requests
Symptom: opening a story page served two more mockup files than it had option frames, even though the compare overlay was never opened.
Root cause: the compare overlay's two iframes were inside an `x-show` container. `x-show` renders the element and only hides it with CSS, so both frames loaded their `src` (two requests, two `git show`s) on every view.
Fix: the overlay was `<template x-teleport="body"><template x-if="compare">…`, so the frames were created only while it was open (SB-4, `e4c2d9e`). Use `x-if`, not `x-show`, for any hidden iframe.
Superseded 2026-10-11: RD-3 removed the compare overlay from the story page, so `story-page.blade.php` no longer has that markup and the `StoryPageTest` pin ("does not load the compare frames until the overlay opens") no longer exists. The `x-if` lesson still stands.
Log trail: per page view, more `board.mockup_served` (debug) lines than the story has options. Found by the preflight audit.

## 2026-09-29 — Horizontal scroll at 375 px on the home page
Symptom: `/` scrolled sideways on a 375 px viewport, although every row is `truncate`d.
Root cause: `status-chip`'s `sr-only` text ("has parse errors") is `position: absolute`. With no positioned ancestor, it was placed relative to the page instead of the truncated row, so it escaped the row's overflow clipping and widened the document.
Fix: the chip itself is `relative` (`resources/views/components/board/status-chip.blade.php`), so the `sr-only` span is clipped inside it. Any new `sr-only` inside a truncated row needs a positioned ancestor (SB-3).
Log trail: none; visual. Found by the 375 px browser screenshot.

## 2026-09-29 — Page hangs 8–50 s on load when a project is stale
Symptom: `/` took 8.3 s (up to 30 s+ with coins' off-main scan) whenever a project's snapshot was stale, against 0.09 s when fresh. A Playwright check timed out.
Root cause: the page-load refresh used `dispatchAfterResponse()`. `php artisan serve` (PHP's built-in server) cannot flush the response before after-response work runs, so the browser waited for the whole `git fetch` plus re-index.
Fix: `RefreshProjectJob` is `ShouldQueue` + `ShouldBeUnique` per project, run by the `queue:listen` that `composer run dev` already starts. A stale load now takes 0.37 s ([ADR-006](decisions/ADR-006-refresh-runs-on-the-queue.md), SB-3). If refreshes stop happening, check the worker is running.
Log trail: `board.home_viewed` then `board.refresh_started` / `board.refresh_finished` with the same `request_id`. Before the fix, they shared a process and the response time matched the refresh `ms`.
Note: if a refresh is killed mid-run (for example, the dev server is stopped), later attempts on that project log `board.refresh_skipped` until `RefreshProject::LOCK_SECONDS` (300 s) expires. This is the lock working as designed, not a bug.

## 2026-09-29 — Board shows fewer stories than `git grep '^Status:'` counts
Symptom: SB-2's browser-check oracle `git grep -h '^Status:' origin/main -- 'stories/**/*.md' | wc -l` gave 918 for coins; the board showed 916.
Root cause: the grep also matches `stories/app-store-launch/README.md` and `stories/import/README.md` (initiative READMEs, not stories). `bin/story-index` excludes READMEs by contract (`docs/KIT-REFERENCE.md` §story-index). The board is right.
Fix: none needed. Compare against `bin/story-index <repo> origin/main | jq length`, not a grep (SB-2).
Log trail: `board.refresh_finished` `count` for `project=coins`.
