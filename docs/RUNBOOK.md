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
