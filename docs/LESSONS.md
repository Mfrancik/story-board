# Lessons Ledger
Record OF lessons — never the enforcement itself (see /lesson).
Append-only, terse, one entry per root cause. >30 entries = promote some to checks.

<!-- Entry format:
## L-<n> — <date> — <short name>
Symptom: | Root cause: | Enforced at: <check|audit|rule> → <file/section>
Scope: PROJECT-ONLY | PROMOTE TO CENTRAL
-->

## L-1 — 2026-07-12 — Mockup approval gate for visual work
Symptom: visual/layout/styling decisions made ad hoc during implementation — no approved direction, no record of the choice or its reason.
Root cause: no gate forced a visual direction to be chosen and approved BEFORE code, so design intent was decided implicitly in code and drifted across projects.
Enforced at: rule → design-standards.md §Mockup approval gate; process+template → /story (step 5, Design mockup gate section, DoD checkbox); audit → /preflight Design pillar (WARN).
Scope: PROMOTE TO CENTRAL

## L-2 — 2026-07-17 — Docs restating machine-readable facts drift
Symptom: 22 of 47 story files said `Status: draft` while their features ran in production; nothing owned updating the copy and nothing compared it to git.
Root cause: a doc asserts a fact about the repo that a machine can already read (git, phpunit.xml, .env, migrations) with no owner and no check — the copy drifts from its source silently. Class, not just story status.
Enforced at: check → preflight.sh story-status gate (build commit on main vs `Status: built`); rule → codebase-standards.md §Docs assert facts; process+template → /story DoD checkbox, CLAUDE.md step 6 commit format, /preflight Phase 1 + report template. Vocabulary locked in stories/README.md §Status.
Scope: PROMOTE TO CENTRAL

## L-3 — 2026-08-22 — Cost facts asserted, not measured or kept
Symptom: the audit pack grew to 901 KB (93% the two index docs, `cat`'d whole under a comment reading "Index docs inline: small") and never once arrived in a single tool call; real audits ran 28–62 turns against an agent file that said "~6 further tool calls"; the cost label printed every run and a month of growth was invisible.
Root cause: a cost claim written as a comment, a doc number, or a one-line label is an unverified assertion with no owner — the same class as L-2, applied to cost. Nothing measured the pack against the harness cutoff, nothing kept the per-run numbers side by side, and the one thing measured to matter (the dispatch note: 2/2 vs 0/4 on a seeded CRITICAL) was not required.
Enforced at: check → preflight.sh `audit pack <= cap` (header measured against PF_PACK_MAX_BYTES, largest section + remedy named) and `docs rows are pointers` (warn-only, 300/600 B); check → `bin/preflight-meter.py hook-pre` refuses a `preflight` dispatch without a DISPATCH NOTE; record → `report` appends one row per run to `~/.claude/projects/<project>/preflight-cost.csv` (the label is a backstop; the CSV is the record); rule → codebase-standards.md §Docs assert facts "Index rows are pointers"; harness → `bin/preflight-ab.sh` (replay + seeded defects) is how a tooling change is proven before merge. Decision: docs/decisions/ADR-002.
Scope: PROMOTE TO CENTRAL

## L-4 — 2026-09-20 — Build artifacts created per agent, disposed of by nothing
Symptom: 586 worktrees (218.7 GB) and 3,491 leftover test databases (43 GB) on a volume 86% full; 248 of 1,145 test failures in three weeks were SQLSTATE exhaustion triaged as product bugs; `information_schema` queries over two minutes; the same project had already lost a disk to 0 bytes once.
Root cause: a provisioning step with no inverse. `bin/provision-worktree.sh` created a worktree and a database per agent; no merge step, gate, hook or test bootstrap ever removed either, and nothing reported the growth — so it was discovered at the disk, not before. The obvious fix (delete by age) was itself destructive: 58 branches were unmerged AND unpushed, their commits existing only inside a worktree.
Enforced at: check → `preflight.sh` `workspace growth` (warn-only, permanently — thresholds `PF_DB_WARN`/`PF_WT_WARN`/`PF_LOG_WARN_MB`); script → `bin/dispose-worktree.sh` (the four-condition conjunction, skip-and-report, `remove` without `--force`, `branch -d` never `-D`, 3b reading `--ignored` because `worktree remove` does not) and `bin/disposal-fixture.sh` (one asserted worktree per classification — the harness that must pass before these scripts change) and `bin/workspace-sweep.sh` (frozen `SHOW DATABASES` snapshot, prefix allow-list, kept droplist); rule → `docs/standards/disposal-standards.md`; process → `/build` merge step ("a merge that leaves its worktree behind is an incomplete merge"). Decision: docs/decisions/ADR-003.
Scope: PROMOTE TO CENTRAL

## L-5 — 2026-09-29 — Guards shipped without a test or a log line
Symptom: four preflight audits in one phase (SB-2..SB-5) flagged rejection paths — a bad stored ref, mockup 404s, disabled-project and malformed-ID 404s, a non-integer `?v=` — that had no test, no log line, or neither; each became a post-GO fix commit.
Root cause: stories enumerate the happy path and one or two failures; the builder never lists the guards the diff adds, so each guard's test and log is left to the audit to notice.
Enforced at: rule → codebase-standards.md §Testing "Every guard gets a test and a log line" (read by the audit's standards pillar on every run, so no new gate or audit bullet — owner asked for nothing that slows the loop).
Scope: PROMOTE TO CENTRAL

## L-6 — 2026-09-29 — Story checks and data rules never run against the real repo
Symptom: four story assertions were wrong on real data and found only while building — SB-2's `git grep` oracle (918 vs 916, it counted READMEs), SB-3's "any initiative README = parked" (coins has release/unstatused READMEs), SB-5's example mockup dir `X-1` (not a valid ID), SB-4's "D marked chosen" (the parser cannot read `**D**`).
Root cause: /story writes counts, rules and examples as facts without executing them — the L-2 class (an unverified assertion) applied to story authoring.
Enforced at: process → .claude/skills/story/SKILL.md step 7 "Run every fact the stories assert against the real project" + Test plan template line "Checked against real data".
Scope: PROMOTE TO CENTRAL

