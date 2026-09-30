# SB-20 — Preflight run errors
Status: draft          Journey: none
Source: owner 2026-09-29 + F-5. Owner, on SB-16: *"id like preflight history to show me number of errors on that
table."* Picked: failed hard gates, failing tests, audit findings. On the preflight-script change this needs:
*"write a story for that and ill revisit when im comfortable doing so. that story can sit in draft"* —
**parked in draft; do not approve or build until the owner reopens it.**

## Story
As the owner, I want each preflight run in the history table to show how many gates failed, which tests failed
and what the audit found, so that I can see when a project started going red and what broke.

## Why
SB-16 reads only the cost CSV, which records spend and duration but nothing about outcome. `preflight.sh` keeps
nothing after it exits, so failures are lost the moment the terminal scrolls.

## In scope
- **Kit change (the part the owner deferred):** `preflight.sh` writes one JSON record per run to
  `~/.claude/projects/<encoded path>/preflight-runs/<ts>.json`, outside the repo: `ts`, `branch`, `mode`, each gate
  with pass/fail/warn and seconds, Pest counts per suite (unit+feature, browser: passed / failed / total),
  failing test names (capped, e.g. 50), and — written by the /preflight skill after the audit — CRITICAL and WARN
  counts and the GO / NO-GO verdict.
- **Board:** `ReadPreflightHistory` joins each CSV row to its run record (by `ts` + `branch`, nearest within a
  small window) and the table gains columns: Gates failed, Tests failed, Audit (C / W), Verdict. SB-16's greyed
  "Tests · needs F-5" column is replaced. Hovering or expanding a row lists the failing gate and test names.
- Runs with no record (all history before this ships) show "—".
- The SB-19 column picker (if built) lists the new columns.

## Out of scope (do NOT build)
- Re-running or backfilling old runs. Storing records in the board DB. Alerts.
- Fixing the meter's audit-tier labels (F-3).

## Acceptance criteria (executable — these become the Pest test names)
- Given a preflight run with 2 failed gates, then its record lists both with fail and their seconds.
- Given a run where 3 browser tests fail, then its record holds browser failed = 3 and the three test names.
- Given the audit reports 1 CRITICAL and 2 WARN with NO-GO, then the record holds those counts and the verdict.
- Given a CSV row with a matching record, then the table shows Gates failed, Tests failed, Audit C/W and Verdict.
- Given a CSV row with no record, then those cells show "—".
- Given a malformed record file, then it is skipped, counted in "could not be read", and logged
  `board.preflight_history_unreadable`.
- Given a row with failing tests, when it is expanded, then the failing test names are listed.

## Applicable standards
- Design: SB-16 ledger (option a) and its tokens; danger tone for non-zero failures; no new chart.
- Codebase/DB: no schema change; reader stays read-only. Kit change proven with `bin/preflight-ab.sh` before
  it is pulled in (CLAUDE.md step 4).
- Logging: reuse `board.preflight_history_viewed` (add `records`), `board.preflight_history_unreadable`.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: to be produced when the owner reopens this story (new columns + expanded-row detail).
- Chosen option: —
- Why I chose it: —

## Do NOT touch
- `bin/preflight-meter.py`'s CSV format (the record is a separate file). `app/Services/GitReader.php`. `config/`.

## Data & interfaces
- Schema/migrations: none. New on-disk file per run: `preflight-runs/<ts>.json` (format to be fixed in this story).
- Changed: `preflight.sh`, the /preflight skill (writes audit counts + verdict), `ReadPreflightHistory`,
  `ProjectPreflight` view.

## Test plan
- Pest: fixture run records in a temp dir via `board.sessions_path`; never the real `~/.claude`.
- Kit: `bin/preflight-ab.sh` replay + seeded defects prove the record is written without changing gate results.
- Journey test: none.
- Browser check: run preflight once with a seeded failing test, open `/p/story-board/preflight`, see the run with
  Tests failed = 1 and the name on expand.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-16 · Folds in: F-5 · Related: SB-19, F-3
