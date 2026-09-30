# SB-19 — Preflight columns picker
Status: built            Journey: none
Source: owner 2026-09-29, feedback on SB-16 at http://127.0.0.1:8018/p/coins/preflight: *"lets add a filter so i
can pick what to add and remove from the table. everything else looks great."* Owner answers: remember the picks
in this browser; build it in the existing filter style, no mockup round.

## Story
As the owner, I want to choose which columns the Preflight history table shows, so that I can keep only the
figures I care about in view while I scroll the timeline.

## Why
SB-16's ledger has twelve columns (When, Branch, Where, Mode, Wall, Turns, Tool calls, Tokens, Subagent, Pack,
Audit tier, and the greyed Tests column). Most checks need three or four of them; the rest is noise.

## In scope
- A **Columns** control in the existing filter bar on `/p/{project}/preflight`, beside the mode / branch / where
  filters, in the same style: a button that opens a list of checkboxes, one per column, in table order.
- Unticking a column hides its header and every cell of it; ticking brings it back. Alpine only, no server request.
- **When** is always shown (the timeline's key) and its checkbox is disabled with a tooltip saying why.
- The choice is remembered **in this browser** (localStorage, keyed per project), wrapped in try/catch: if storage
  is unavailable the page falls back to all columns and still works.
- A **Reset columns** link in the list restores every column.
- The button shows a count when columns are hidden, e.g. "Columns · 4 hidden".
- The trend strip and the mode / branch / where filters are unaffected.

## Out of scope (do NOT build)
- Reordering or resizing columns. Server-side or per-user (DB) saved views.
- Error / failure counts (SB-20, draft).
- Any change to `ReadPreflightHistory` or the CSV reading.

## Acceptance criteria (executable — these become the Pest test names)
- Given the Preflight page loads, then a Columns control lists every column except that When is disabled and
  always ticked.
- Given Tokens is unticked, then the Tokens header and every Tokens cell are hidden, without a server request.
- Given Tokens is ticked again, then the column shows again.
- Given two columns are hidden, then the control reads "Columns · 2 hidden".
- Given columns were hidden and the page is reloaded, then the same columns are still hidden.
- Given hidden columns for coins, when another project's Preflight page opens, then all its columns show.
- Given Reset columns is clicked, then every column shows and the saved choice is cleared.
- Given localStorage throws, then the page renders all columns and the control still hides and shows columns.
- Given a filter (mode = scoped) and a hidden column, then both apply together.
- Given a 375 px viewport, then the Columns list opens without horizontal page scroll.

## Applicable standards
- Design: match the existing SB-16 filter controls (same border, radius, text size, tokens); Flux/Alpine dropdown
  pattern already used on the board; tokens are law. `board/project-tabs` and `board/project-header` unchanged.
- Codebase/DB: no schema change; no PHP beyond markup. Alpine state lives in the existing
  `Alpine.data('preflightHistory')` (resources/js/preflight-history.js). localStorage is a per-viewer convenience
  only (never read by the server or tests of server behaviour).
- Logging: none new — a pure-UI toggle makes no server call (CLAUDE.md: never a round-trip for UI-only state).
  `board.preflight_history_viewed` unchanged.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: n/a — owner chose to build in the existing SB-16 filter style, no mockup round (2026-09-29).
- Chosen option: n/a (owner waiver)
- Why I chose it: "Build in existing style" — small control inside an approved layout (SB-16 option a).

## Do NOT touch
- `preflight.sh`, `bin/preflight-meter.py`, any `preflight-cost.csv`, `app/Actions/Board/ReadPreflightHistory.php`,
  `app/Services/GitReader.php`, `config/`.

## Data & interfaces
- Schema/migrations: none.
- Changed: `resources/views/livewire/board/project-preflight.blade.php` (control + a `data-col` key per th/td),
  `resources/js/preflight-history.js` (hidden-columns state + storage). `docs/UI-INVENTORY.md` row updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST. Column hiding, persistence and the
  no-request check are browser tests (`tests/Browser/ProjectPreflightTest.php`); a feature test asserts every
  th/td carries its column key.
- Journey test: none.
- Browser check: `/p/coins/preflight` → Columns → untick Turns, Tool calls, Pack → table narrows, button reads
  "Columns · 3 hidden" → reload → still hidden → Reset columns → all back.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — owner waiver recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard (none new)
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-16 · Related: SB-20 (draft)
