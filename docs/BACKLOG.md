# Backlog — captured ideas

Raw, unrefined ideas caught via `/feature`. **Not stories:** nothing here has
acceptance criteria, approval, or a commitment behind it. Dumps are captured
verbatim and are never edited.

This file's main job is to answer one question at `/story` time: *am I describing
something I already logged?* That only works if it stays readable — so it holds
one-line entries, and any worked-out spec lives beside it in `docs/backlog/F-<n>.md`.

An entry leaves `## Open` exactly two ways:

- **It ships** — a story named it in `Source: F-<n>`, and `/document` removed it
  on completion. Its trace lives in that story file and in git. A preflight gate
  to pin this is written at `wip/backlog_sync_gate.sh` but is not yet installed.
- **It's cut** — moved to `## Cut` with a reason, via `/feature cut` or at retro.

It does not leave because it got scheduled, and it does not leave because someone
tidied up.

Swept twice: by `/story` when a brain dump arrives (same / partial / overlap), and
by `/lesson retro` at phase end (promote, cut, or explicitly keep). `/feature list`
prints what's open on demand. If `## Open` passes ~25 entries,
that's a signal — either capture is outrunning the build loop, or half of this is
aspiration nobody will build.

Entry format:

```
### F-<n> — <date> — <short title>
Area: <feature/page/surface, or unknown>
Scope: PROJECT | PROMOTE
Spec: docs/backlog/F-<n>.md   ← only once spec'd
Story: <story-id>             ← only once folded into a story

<the dump, verbatim>
```

IDs are never recycled — F-12 always means the same F-12, including after it's
cut or shipped.

## Open

### F-2 — 2026-09-29 — Timeline of what was built, in order
Area: home page (SB-3)
Scope: PROJECT

id like to see a timeline view of whats been built in what order too.  ew can also build this later

### F-3 — 2026-09-29 — Preflight meter mislabels the audit tier
Area: kit tooling (bin/preflight-meter.py)
Scope: PROMOTE

Retro finding (owner-approved capture): three times this phase the cost label read "audit opus" or "opus+sonnet" when the preflight audit ran on Sonnet. The meter counts every subagent that ran in its window, so a documenter subagent (session model) was reported as the audit tier. It should count only `preflight`-type subagents toward the audit tier. Fix in the kit, proven with bin/preflight-ab.sh.

### F-4 — 2026-09-29 — Per-initiative story breakdown with built crossed out · Story: SB-15
Area: single-project dashboard (SB-10) · Progress by initiative
Scope: PROJECT

I would like to see a breakdown of all similar stories somewher, so for instance all the brand stories in a section and i can go through and see whats built (crossed out), pending, etc...

## Cut

### F-1 — 2026-09-29 — Kit parser misses bold Chosen option picks
Area: story-index parser (kit, SB-1) · home "Awaiting a mockup pick"
Scope: PROMOTE
Cut: 2026-09-29 — PROMOTE-scoped; moved to the central (kit) backlog at the phase-1 retro.

Kit parser (bin/story-index, SB-1) reads `Chosen option: **B** (owner, 2026-09-25) — …` as no pick: parse_chosen strips the leading `**` but CHOSEN_RE then rejects the `*` after the letter. On 2026-09-29 this put 6 already-picked stories (coins MOB-44, MOB-65; asset-track CV-1, TS-3; rent-track MT-4b, MT-4c) into the board's "Awaiting a mockup pick", which should hold 3 (coins AUC-17, AUC-21; client-dashboard SS-17), and 42 built stories carry the same shape. Fix belongs in the kit (SB-1 follow-up), not in story-board.
