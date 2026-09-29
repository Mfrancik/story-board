# ADR-002 — The audit pack is a header that lands in one call; the cost is a record, not a label

Date: 2026-08-22 · Status: accepted · Supersedes the pack shape in ADR-001 §2–3

## Context

ADR-001 moved the audit into a read-only Sonnet subagent and gave it
`./preflight.sh audit-pack` — "one call that replaces the audit's exploration
turns". After ~300 stories a downstream project measured what that call had become:

1. The pack was **901 KB**; 93% was `docs/INDEX.md` and `docs/UI-INVENTORY.md`,
   `cat`'d whole under a comment reading *"Index docs inline: small"*. The indexes
   grew ~2.7 KB per story because `/document` appended prose to rows instead of
   moving it into the feature doc. The only cap (`PF_PACK_MAX_BYTES=60000`) was on
   the diff — the one part that does *not* grow with story count.
2. The harness **persists any tool result over its cutoff** to a file and returns
   a 2 KB preview. The downstream session measured the cutoff at ~50 KB; probed in
   this repo the same day it was ~28 KiB in the main session and 30–39 KiB in a
   subagent. Either way the pack had *never* arrived in one call: every audit
   transcript showed the subagent `head`/`tail`/`sed`-slicing it and then
   exploring — 28–62 turns against the agent file's "~6 further tool calls",
   1.3–4.0M cached-input tokens per audit.
3. A six-run A/B on a real story with a known CRITICAL (a licence gate bypassed
   in one untouched view): the auditor found it **2/2** times when the dispatch
   said *"is the withholding actually centralised, or are there paths that bypass
   `<predicate>`? A leak here is CRITICAL"*, and **0/4** with a generic note — one
   of those runs wrote a false PASS for the very file without opening it.
   Projecting the index cells to 110 chars changed neither catch rate nor cost
   (±7%). No run used the inventory for duplication detection; it only checked
   that new components were listed.
4. `bin/preflight-meter.py` printed one label per run and persisted nothing; the
   month of growth was invisible.

## Decision

**1. The pack is a header, capped and gated.** `audit-pack` prints SCOPE, FILES
CHANGED with per-file diff bytes, FETCH DIFF (batched `git diff <base>...HEAD --
<files>` commands chunked under the cap, app code first), STORY FILES, a
DUPLICATION CANDIDATES scan, the four mechanical scans, and the two indexes
*projected* to their pointer columns (`Feature | Status | Updated | Doc`;
`Component | Type`, full row when the diff names the component), cells capped
character-aware (`PF_PACK_CELL_MAX`, default 110). No inline diff. The cap is
`PF_PACK_MAX_BYTES`, **default 28000** — the largest value that landed in every
context measured — with `bin/preflight-ab.sh probe` to measure a harness and the
env var to match it. If the projected indexes would overflow the cap they degrade
to rows the diff names plus a count and the command that prints the rest; if the
header still overflows, the pack prints a banner *first* (so it is what a 2 KB
preview shows) and the new hard gate `audit pack <= cap` fails the run naming the
largest section and a section-specific remedy. Sections can be fetched singly.

Rejected: keeping the indexes whole and raising the cap — the cutoff is a harness
property that moved by 2× between two sessions on one day, and index growth is
unbounded. Rejected: dropping the indexes from the pack entirely — the A/B showed
the *prose* was unused, not the names; the inventory's component names are what
the duplication scan matches against.

**2. The dispatch note is required, and the requirement is a check.** `/preflight`
Phase 2 writes a `DISPATCH NOTE` (story in two sentences; what is forbidden and
where a leak is CRITICAL; 3–5 "eye on" items each tied to a standards section)
before spawning. A `PreToolUse` hook on the Agent tool refuses a `preflight`
dispatch whose prompt lacks the markers (exit 2 with the reason). It is the only
change in the set with a measured effect on catch rate, and it costs a few hundred
tokens from context the main session already holds.

**3. The agent's turn budget is rewritten to the measured shape**: one call for
the header, the diff via the header's commands (never sliced), standards once, a
measured further-call budget (start 15) with `NOT AUDITED` as the overrun outcome.
The "~6" is deleted.

**4. The cost is a record.** `preflight-meter.py report` appends one row per run
— `ts, project, branch, mode, wall_s, turns, tool_calls, tokens_in, tokens_out,
subagent_tokens, pack_bytes, audit_model` — to
`~/.claude/projects/<project>/preflight-cost.csv`: per project, beside the
transcripts it is derived from, outside the repo so it is never committed.
Exactly once per run (`report`, or the Stop hook on the run's final clear). The
label stays as the per-turn backstop; `pack_bytes` is read from the transcript
(inline size, or the size a persisted-output notice names).

**5. Index rows are pointers** (codebase-standards §Docs assert facts): `name |
one sentence ≤ 200 chars | status | date | link`, edited in place keyed by link;
longer content is *moved* to the feature doc, never deleted. `/document` and the
documenter carry the rule; `preflight.sh` warns on rows over 300 B / 600 B.
Warn-only by decision: a gate that reds every existing project on its first pull
teaches people to ignore gates; the flip is a later, separate change.

**6. Tooling changes are proven, not argued.** `bin/preflight-ab.sh` builds
detached worktrees for a replay commit and a seeded-defect commit with the pack
base pinned, and `measure` parses the audit transcripts into turns / tool calls /
cached tokens / pack inline-vs-persisted / per-defect hit. The seeded-defect
pattern is one plant per pillar plus one detectable only through inventory
content. `bin/preflight-fixture.sh` builds a throwaway downstream repo from the
kit's files verbatim, which is also the test that a project pulling the kit needs
no manual edits.

## Consequences

- The header is `O(diff-file-count + diff-named index rows)`, not `O(stories)`;
  on the fixture at 300 stories it is 2.7 KB. A diff too large to chunk under the
  cap is reported as the story's problem (split it), which it is.
- The auditor spends its turns on the diff and the note's "eye on" files rather
  than on slicing a blob. The 15-call budget is a start value; the CSV is how it
  gets tuned, and the A/B harness is how any retuning is justified.
- The cutoff is measured per harness, not assumed: `PF_PACK_MAX_BYTES` is the one
  number a downstream project is expected to set after `probe`.
- Unchanged: what the gates run and their verdicts; `model: sonnet`; the
  standards' substance beyond the one index-row rule.
