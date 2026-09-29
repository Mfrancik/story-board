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

## 2026-09-29 — Board shows fewer stories than `git grep '^Status:'` counts
Symptom: SB-2's browser-check oracle `git grep -h '^Status:' origin/main -- 'stories/**/*.md' | wc -l` gave 918 for coins; the board showed 916.
Root cause: the grep also matches `stories/app-store-launch/README.md` and `stories/import/README.md` (initiative READMEs, not stories). `bin/story-index` excludes READMEs by contract (`docs/KIT-REFERENCE.md` §story-index). The board is right.
Fix: none needed. Compare against `bin/story-index <repo> origin/main | jq length`, not a grep (SB-2).
Log trail: `board.refresh_finished` `count` for `project=coins`.
