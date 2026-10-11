# RD-10 — Preflight writes a run record (PARKED)
Status: draft          Journey: none
Source: owner 2026-10-10 + F-5: *"lets create a story for that for me to revisit, i dont want to make changes to
preflight just yet, we can build the framework and revisit that part later"*.

> **Parked.** Do not approve or build until the owner reopens it. RD-8 already reads the format below, so building
> this needs no board change.

## Story
As the owner, I want every preflight run, on the brain or on the Mac, to leave one small record of where it ran,
how long it took and how each gate went, so that the board can show it without reaching into the brain.

## Why
On 2026-10-10 nothing recorded where a run happened. coins' brain runs started by hand never come back to the Mac;
PRF-22 (unmerged, `coins/.claude/worktrees/prf-22`) adds `runner`/`mode`/`note` columns to coins'
`preflight-runs.csv`, but has no host or run-level wall time, and story-board's `preflight.sh` keeps no history at all.

## In scope
- **Kit `preflight.sh`** (and so every project, story-board included): at the end of a run, write
  `~/.claude/projects/<encoded path>/preflight-runs/<run_id>.json` atomically (temp file, then `mv`), in the RD-8
  format: `schema` 1, `run_id`, `project`, `branch`, `sha`, `runner` (`local`), `host` (`hostname -s`), `mode`,
  `started_at`, `finished_at`, `wall_s`, `verdict`, `gates[{name, result, seconds}]`.
- **coins' brain route** (`bin/runner/remote-run.sh`, after `record_history`): write the same record for a brain run,
  with `runner: brain` and `host` from brain's `runner.json`, built from brain's `verdict.json`.
- Proven with `bin/preflight-ab.sh` before it is pulled into the kit (CLAUDE.md step 4).

## Out of scope (do NOT build)
- Failing tests and audit findings (SB-20 extends this record later).
- Any board change (RD-8 already reads it).
- Routing runs to the brain (coins PRF-22).

## Acceptance criteria (executable — these become the Pest test names)
- Given a local preflight run, then exactly one record exists for it with `runner: local`, this Mac's short
  hostname, and a `wall_s` equal to finished minus started.
- Given a gate fails, then its entry has `result: FAIL` and the verdict is NO-GO.
- Given the run is killed before the end, then no partial record is left (atomic write).
- Given a brain run routed by PRF-22, then the Mac holds a record with `runner: brain` and brain's hostname.
- Given the A/B harness replay, then preflight's verdicts and costs are unchanged apart from the new file.

## Applicable standards
- Design: n/a.
- Codebase/DB: kit tooling; shell and Python only; no repo files written (the record lives outside the repo).
- Logging: n/a (preflight tooling logs nothing).

## Design mockup gate (visual stories only — else "n/a — non-visual")
n/a — non-visual

## Do NOT touch
- The cost CSV format and `preflight-meter.py` columns.
- Preflight's gates and verdict logic.

## Data & interfaces
- Schema/migrations: none. The file format is defined in RD-8's ADR.

## Test plan
- Pest: n/a; shell fixture tests in the kit, as `bin/preflight-fixture.sh` does.
- Journey test: none.
- Browser check: after one local run, the Preflight page row for it reads "Mac".

## Definition of done
- [ ] Acceptance criteria pass
- [ ] `bin/preflight-ab.sh` replay shows no change apart from the record
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-8 (format) · Related: SB-20 (parked), coins PRF-22
