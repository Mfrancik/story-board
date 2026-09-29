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

## 2026-09-29 — An unknown project's page 404'd with no log line
Symptom: `/p/nope` returned 404, but no `board.project_page_refused` was logged, so the refusal could not be explained from the log.
Root cause: Livewire 4 binds `{project:name}` inside `SubstituteBindings`. That runs before route middleware, so an unknown name 404'd in the binding before the guard ran.
Fix: `bootstrap/app.php` calls `prependToPriorityList(SubstituteBindings::class, EnsureProjectIsShown::class)`, so the guard runs first and reads the raw route string ([ADR-013](decisions/ADR-013-project-refusal-runs-before-route-binding.md), SB-7). Pinned by `AppShellTest` "404s /p/nope and logs board.project_page_refused with reason unknown". Any guard that has to see an unbound name needs the same priority entry.
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
Fix: the overlay is `<template x-teleport="body"><template x-if="compare">…`, so the frames are created only while it is open (`resources/views/livewire/board/story-page.blade.php`; SB-4, `e4c2d9e`). Use `x-if`, not `x-show`, for any hidden iframe. Pinned by `StoryPageTest` "does not load the compare frames until the overlay opens".
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
