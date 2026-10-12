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

2026-10-11 (redesign-phase retro, L-8, owner chose to defer the code): four more meter failures in one session. (1) The Stop hook fires when the turn ends while an async preflight subagent is still running, bounces, and clears the marker on the next stop, so `report` then says "no start marker". (2) A second preflight dispatch in the same session started no marker. (3) `report` run from a worktree printed "tokens unavailable (no transcript)": the transcript lookup keys on the cwd, not the primary checkout. (4) `csv_path()` keys on the cwd too, so the "one record" is ten per-worktree CSVs under ~/.claude/projects/. Fix together with the tier bug.

Retro 2026-10-11 (RD-9, phase end): PROMOTED — the third batch running (RD 3-4, 5-8, 9) lost its preflight record to the marker bug. Next kit fix, ahead of other kit work.

### F-5 — 2026-09-29 — Preflight run record: tests, failures, gates, verdict · Story: SB-20, RD-10
Area: kit tooling (preflight.sh) · feeds SB-16 preflight history
Scope: PROMOTE

Owner wants preflight history to show "the tests that ran, failures, which fialures, time it took to run". The cost CSV holds none of that and preflight.sh keeps nothing after it exits. Proposal: preflight.sh writes one JSON per run (gates with pass/fail and seconds, pest counts per suite, failing test names, verdict) to ~/.claude/projects/<p>/preflight-runs/. Deferred by owner 2026-09-29: "I dont want to make too many changes to the preflight scripts right now".

### F-13 — 2026-10-11 — One owner for story status labels and colours
Area: shared board primitives (status-chip, status-bar) · Stories, project page, story page
Scope: PROJECT

With RD-9: one owner for story status labels and colours. The built/approved/draft/cancelled → label/dot/tone maps are defined separately in status-chip.blade.php:26, project-stories.blade.php:18,19,29, status-bar.blade.php:14, project-page.blade.php:104 and story-page.blade.php:25-29. Fold them into StoryStatusOrder helpers (label(), dot(), tone()) or a board/status-key component. (Found by the redesign phase full preflight sweep, 2026-10-11.)

Retro 2026-10-11 (RD-9, phase end): PROMOTED — earmarked "with RD-9" but never reached the story. Build together with F-15 as one cleanup story; both touch status-chip, status-bar and project-tabs.

### F-14 — 2026-10-11 — One shared icon component
Area: all board/shell views
Scope: PROJECT

With RD-9: ~45 hand-pasted inline SVG icons (sidebars, the empty-state glyph in six views, search magnifier, warning triangle, chevron, spinner) plus per-view icon closures (mockup-viewer.blade.php:37 $icon, project-app-map.blade.php:30 $arrow, production-settings.blade.php:97-98 $tick/$cross, story-page.blade.php:36 $chevron). Replace with one <x-shell.icon name=… class=…> component; mockup-viewer's $icon closure is the closest starting point. (Found by the redesign phase full preflight sweep, 2026-10-11.)

### F-15 — 2026-10-11 — Dead code RD-9 left behind
Area: ListLiveSessions, ReadProductionProject, ReadMockupSets, partials/head, app.css, shared board components
Scope: PROJECT

RD-9 handoff (follow-ups the build left undone, not fixed): ListLiveSessions::handle() + its private helpers (links(), firstSentence()) + the `chosen` field are now uncalled; ReadProductionProject::outcome()/outcomeKey() are write-only (only the next write reads them); ReadMockupSets::countFor() is uncalled (found by /document); dead `@else @fluxAppearance` light branch in resources/views/partials/head.blade.php and the stale app.css:10-14 comment; legacy zinc/dark: classes in shared board components (status-chip, status-bar, project-tabs); .board-modal/.board-drawer usage unchecked; "Refresh the project from its dashboard" copy in handbook unread/failed and app-map views now points at a deleted page.

Retro 2026-10-11 (RD-9, phase end): PROMOTED with F-13 as one cleanup story. The "Refresh the project from its dashboard" copy is already fixed (f6b6376); the rest is still live.

### F-17 — 2026-10-11 — A list of off-main work
Area: home overview (RD-5) · old home's "Not on main" section
Scope: PROJECT

The old home's "Not on main" section had no successor in the redesign; RD-9 deleted it. Off-main work now shows only as counts on the project tiles and as versions on a story page. Owner, 2026-10-11 retro: log it.

## Cut

### F-7 — 2026-10-11 — Production item in the shell sidebar
Area: shell sidebar (RD-2) · /prod
Scope: PROJECT
Cut: 2026-10-11 — RD-9 deleted /prod, so there is no page to link; production figures live on the overview's project tiles (ADR-054).

Owner, 2026-10-11: the shell sidebar has no Production item, so /prod is reachable only from the old Home's sidebar until RD-9. Add a Production item with RD-5/RD-9.

### F-6 — 2026-10-11 — /story writes a one-line Summary: for the story page
Area: kit tooling (/story skill, story template) · feeds RD-3 story page
Scope: PROMOTE
Cut: 2026-10-11 — PROMOTE-scoped; moved to the central (kit) backlog at the redesign-phase retro.

Captured from RD-3's Out of scope: the short story page shows a story's `Summary:` line under its title, and falls back to the first sentence of `## Story` (logged `board.story_summary_fallback`, debug) when there is none. No story has one yet because the kit's /story does not write it. Have /story write a `Summary:` line of at most 200 characters in the header.

### F-1 — 2026-09-29 — Kit parser misses bold Chosen option picks
Area: story-index parser (kit, SB-1) · home "Awaiting a mockup pick"
Scope: PROMOTE
Cut: 2026-09-29 — PROMOTE-scoped; moved to the central (kit) backlog at the phase-1 retro.

Kit parser (bin/story-index, SB-1) reads `Chosen option: **B** (owner, 2026-09-25) — …` as no pick: parse_chosen strips the leading `**` but CHOSEN_RE then rejects the `*` after the letter. On 2026-09-29 this put 6 already-picked stories (coins MOB-44, MOB-65; asset-track CV-1, TS-3; rent-track MT-4b, MT-4c) into the board's "Awaiting a mockup pick", which should hold 3 (coins AUC-17, AUC-21; client-dashboard SS-17), and 42 built stories carry the same shape. Fix belongs in the kit (SB-1 follow-up), not in story-board.
