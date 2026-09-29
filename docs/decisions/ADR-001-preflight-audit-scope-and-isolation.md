# ADR-001 — Preflight audit runs isolated and diff-scoped, with a per-phase full sweep

Date: 2026-07-30 · Status: accepted

## Context

`/preflight` runs before every story and gets more expensive as a project grows.
Measured in a live project (13 sessions): 21 turns per run, ~220k context re-read
per turn, ~2.7M cumulative input, 605s wall clock. Two terms were compounding:

1. **Per-run**: cost ≈ `turns × context_per_turn`, and both grow with the codebase,
   because the audit sampled the whole tree across six pillars.
2. **Across runs**: everything the audit read stayed in main context permanently
   (+30–50k per run), so the ninth preflight of a phase paid for the previous
   eight. This is the term that made it feel like a sudden cliff — it was the
   phase accumulating, not the codebase crossing a threshold.

Phase 1 (`preflight.sh`) was never the token problem: a green run emits ~885 B.
It was the wall-clock problem — serial gates, with sub-second greps queued behind
a 578s test run.

## Decision

**1. The audit runs as a read-only subagent** (`.claude/agents/preflight.md`, tools
`Bash, Read, Grep, Glob`). Its context dies with it, so main grows only by the
report (~2.5k). This kills the across-runs term: run N no longer pays for run N−1.
Read-only also converts "do not fix things silently during preflight" from a rule
into a structural guarantee.

**2. Scoped by default, full once per phase.** Per story the audit sees
`git diff --name-only main...HEAD` plus the small index docs (`UI-INVENTORY.md`,
`INDEX.md`, `docs/journeys/`). Once per phase, alongside `/lesson retro`, it runs
unscoped.

Rejected: diff-scoping *everything*. Of the 15 audit checks, 10 are answerable from
a diff and 5 need whole-codebase scope — and those 5 include both CRITICAL checks
(the duplication sweep, journey regression). A diff-scoped auditor cannot see that
a second create-user form exists three directories away; it reports PASS. Worse,
those 5 are precisely the checks whose value *grows* with project size, so
diff-only retires them exactly as they start to matter. Hence the per-phase sweep:
amortised, one full audit per ~6 stories instead of nine.

The audit must report `NOT AUDITED (needs full sweep)` rather than PASS for
anything its scope cannot see. A PASS you could not see retires a check silently.

**3. Deterministic checks move down into `preflight.sh`.** A scripted check costs
wall clock and ~0 tokens; the same check in the audit costs tokens on every run and
grows with the codebase. Newly demoted: journey regression (was "re-run ALL journey
tests by hand"), journey reference resolution, UI-inventory currency. This is the
lesson ladder's rung 1 applied to cost, not just to correctness.

Journey regression was *deleted* in the source project's version. Rejected — it is
the only guard against a later story breaking an earlier link in a flow, and that
risk grows with journey count. It belongs in Phase 1, not in the bin.

**4. Wall clock**: gates reordered cheap-first, `pint`/`phpstan` backgrounded
alongside the suite, test suite split (unit+feature `--parallel`, Browser strictly
serial — Playwright misses paratest's per-worker DB suffix), `db:monitor` instead
of `db:show --json` (51 B vs 1.87 MB), per-run `mktemp -d` scratch instead of a
shared `/tmp/pf.out`, byte-capped failure output.

**5. The cost is labelled.** Every preflight turn opens with
`preflight — <time> — <tokens> tok`, from `bin/preflight-meter.py`, enforced by a
`Stop` hook. The meter reads the main transcript *and* `<session>/subagents/*.jsonl`
— under this ADR most of the spend is in the subagent, and a meter reading only the
main transcript would report a few thousand tokens and call an expensive run cheap.

## Consequences

- Per-run cost becomes roughly independent of how many runs preceded it.
- Audit cost becomes `O(diff + indexes)` instead of `O(codebase)`, with one
  `O(codebase)` sweep per phase.
- The indexes become load-bearing: an un-inventoried component is invisible to a
  scoped audit. Mitigated by the new `UI inventory current` hard gate.
- Wall-clock gains are a constant factor (÷~3.3) on an `O(features)` quantity.
  Test-suite growth will resurface; the structural fix is seeder/fixture cost per
  test, not more cores.
- `docs/journeys/README.md` asks each journey to record "build status", which
  conflicts with LESSONS L-2 (docs must not restate machine-readable facts).
  Deliberately not resolved here — flagged for a `/lesson` call.
