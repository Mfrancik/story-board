# RD-8 — Preflight "Ran on" (the reading side)
Status: built            Journey: none
Source: owner 2026-10-10: *"all should have a preflight run which shows stats from each preflight such as where it
ran (brain or mac), speed, errors, etc."* and *"i dont want to make changes to preflight just yet, we can build the
framework and revisit that part later"*. The writing side is RD-10 (parked); errors are SB-20 (parked).

## Story
As the owner, I want the Preflight page and the home tiles to show where each run happened, brain or mac, so that
once runs go to the brain I can see which ones did and how their speed compares.

## Why
Nothing records where a run happened today. coins' brain routing (PRF-22) is unmerged, and story-board's
`preflight.sh` keeps no run history. Building the reader now, against a fixed record format, means the column fills
in as soon as RD-10 starts writing records, with no board change needed.

## In scope
- **The run record format** (written by RD-10, read here), one JSON file per run at
  `<board.sessions_path>/<project folder>/preflight-runs/<run_id>.json`. Fields: `schema` (1), `run_id`,
  `project`, `branch`, `sha`, `runner` (`brain` | `local`), `host`, `mode`, `started_at`, `finished_at`, `wall_s`,
  `verdict`, `gates[{name, result, seconds}]`. Recorded in an ADR, so RD-10 builds against it.
- **`ReadPreflightRuns`:** reads those files for a project (the same folder rules as `ReadPreflightHistory`),
  skipping and counting a file that is unreadable, has an unknown `schema` or is missing a required field.
- **Matching a cost row to a record:** same branch, and the record's `finished_at` within 10 minutes of the CSV
  row's `ts`. The nearest record wins. No match → unknown.
- **Preflight page:** a **Ran on** column (Brain · Mac · —; `local` reads as "Mac"), part of the existing columns
  picker; a **Where it ran** filter (All · Brain · Mac · Unknown, with counts) in the existing filter bar; and, when
  both brain and mac runs exist in the window, median wall time for each in the trend strip.
- **Home tile** (RD-7): the last preflight line adds "on brain", "on mac", or nothing when unknown.

## Out of scope (do NOT build)
- Writing records, or any change to `preflight.sh`, the meter or coins' runner (RD-10).
- Gate errors, failing tests and audit findings (SB-20).
- Contacting the brain over ssh or the network. The board only reads local files.

## Acceptance criteria (executable — these become the Pest test names)
- Given no `preflight-runs/` folder, then the Preflight page renders as today with every Ran on cell "—", and the
  filter shows "Unknown" with every run.
- Given a record with `runner: brain` on the same branch, finishing 4 minutes after a cost row, then that row reads
  "Brain".
- Given a record with `runner: local`, then the matched row reads "Mac".
- Given a record 25 minutes away from any cost row, then no row matches it and every row stays "—".
- Given a record with `schema: 2` or no `runner` field, then it is skipped, counted in the "could not be read"
  notice, and logged.
- Given the Brain filter, then only Brain rows show, without a server request.
- Given 3 brain runs and 3 mac runs in the window, then the trend strip shows each one's median wall time.
- Given the latest run matched to a brain record, then that project's home tile reads "… on brain".

## Applicable standards
- Design: RD-2 tokens; the column and filter in the existing SB-16/SB-19 styles (Alpine, no round-trip).
- Codebase/DB: one new read-only action; files are read, never written; strict ISO timestamps as in
  `ReadPreflightHistory`. No migrations.
- Logging: `board.preflight_run_record_skipped` (warning: file, reason); `board.preflight_history_viewed` gains
  `matched`/`unmatched` counts.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: none. One column and one filter in the approved Preflight layout ("this layout is good, we just need to
  also know if it was ran on brain").
- Chosen option: existing Preflight layout plus the Ran on column and filter (owner, 2026-10-10)
- Why I chose it: "this layout is good, we just neeed to also know if it was ran on brain".

## Do NOT touch
- `preflight.sh`, `bin/`, `.claude/`, and anything in `/Users/mikefrancik/Code/coins`.
- The cost CSV format.

## Data & interfaces
- Schema/migrations: none. A file format (above), recorded in an ADR.
- New `ReadPreflightRuns` action; `ProjectPreflight` and the home tile action read it.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build (fixture JSON files under a temp
  `sessions_path`, as the preflight history tests do).
- Journey test: none.
- Browser check: put one hand-written brain record beside story-board's CSV; the matched row reads Brain, the
  filter narrows to it, and the home tile reads "on brain". Remove the file afterwards.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-2, RD-7 · Fed by: RD-10 (parked) · Related: SB-20 (parked)
