# ADR-055 — One JSON record per preflight run, matched to the cost CSV by branch and time

Date: 2026-10-11 · Status: accepted · Story: RD-8 (reader) · Writer: RD-10 (parked)

## Context

The owner wants the Preflight page and the home tiles to say where each run happened, on the brain or on the
Mac. Nothing records that today. The cost CSV (`preflight-cost.csv`, written by `bin/preflight-meter.py report`)
has no runner column, and its format is out of scope. `preflight.sh` keeps no run history. coins' brain routing
is unmerged. The owner asked for the reading side now and the writing side later, so the format has to be fixed
first. RD-10 then writes against it and the board needs no change.

## Decision

**Where.** One file per run at `<board.sessions_path>/<project folder>/preflight-runs/<run_id>.json`. The
`<project folder>` follows the CSV's rule (ReadPreflightHistory::folders): the project's encoded path, any
`--claude-worktrees-<name>` folder of it, or its `--claude-plans` folder, compared case-insensitively. A run
writes its record into the folder of the checkout it ran from, beside that checkout's CSV row.

**Format, schema 1.** A JSON object. Every field is required:

| Field | Type | Meaning |
|---|---|---|
| `schema` | int | `1`. Any other value is skipped, not guessed at. |
| `run_id` | string | Unique per run. Also the file name. |
| `project` | string | Project name. Informational: the folder decides the project. |
| `branch` | string, non-empty | The branch the run checked. Used for matching. |
| `sha` | string | The commit the run checked. |
| `runner` | `"brain"` \| `"local"` | Where the gates ran. `local` is the owner's Mac and displays as "Mac". |
| `host` | string | The machine's host name. |
| `mode` | string | `scoped` or `full`, as the meter writes it. |
| `started_at` | string | ISO-8601 with `Z` or an offset. |
| `finished_at` | string | Strict ISO-8601 with `Z` or an offset, the same rule as the CSV's `ts`. Used for matching. |
| `wall_s` | number | The runner's own wall time. |
| `verdict` | string | `GO` or `NO-GO`. |
| `gates` | list of `{name, result, seconds}` | One entry per gate. SB-20 may read these later. |

**Skipped, counted, logged.** `ReadPreflightRuns` skips a file and counts it into the page's "could not be read"
notice when the file cannot be read or is not a JSON object, when `schema` is not 1, when any field is missing,
when `runner` is not `brain` or `local`, when `branch` is empty or not a string, or when `finished_at` is not a
strict ISO date. Each skip logs `board.preflight_run_record_skipped` (warning: project, file, reason). The board
never writes, moves or deletes a record.

**Matching.** A CSV row and a record match when they are on the same branch and the record's `finished_at` is
within 10 minutes of the row's `ts`, inclusive. Pairs are taken nearest first, and each row and each record is
used once. If two runs on one branch fall minutes apart, they cannot both claim one record. A row with no match
reads "—" and counts as Unknown. The match runs on every page load. Nothing is stored.

**What the board shows.** The Preflight page shows a Ran on column (Brain · Mac · —) and a Where it ran filter
(All · Brain · Mac · Unknown). When the 30-day window holds both brain and Mac runs, it also shows each runner's
median wall time. That median uses the CSV row's `wall_s`, the same figure as the strip's other median. The home
tile adds "on brain" or "on mac" to the last preflight line, and nothing when that run is unmatched.

## Alternatives rejected

- **A `runner` column in the cost CSV.** This would change the CSV format and the meter, both out of scope. Old
  rows would also have an empty column forever.
- **Match on `run_id` carried in the CSV.** This has the same cost: it needs a new CSV column. Branch plus a time
  window needs nothing new on the meter's side.
- **Each row takes its nearest record independently.** This is simpler, but two quick runs on one branch would
  both read the same record's runner.
- **Use the record's `wall_s` for the per-runner medians.** Unmatched rows have no record, so the strip would mix
  two different measures. The CSV's wall time is the one every row has.
- **Accept unknown schemas leniently.** A later schema may change what a field means. Showing "—" is honest, and
  a guess would not be.
