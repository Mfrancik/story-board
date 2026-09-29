# ADR-009 — A mockup choice the kit parser cannot read is quoted as written, never guessed

Date: 2026-09-29 · Status: accepted

## Context

The story page marks the chosen mockup frame. The chosen letter comes from the kit parser
(`bin/story-index`, stored as `stories.mockups.chosen`). That parser misses the common bold form
`Chosen option: **D** (owner, …) — …` (backlog F-1), so stories that really have a pick come back
with `chosen = null`. On 2026-09-29 coins MOB-56 (D) and MOB-65 (B) were affected. The board could
read the letter itself.

## Decision

`app/Actions/Board/ReadMockupGate.php` quotes the `## Design mockup gate` section for display only:
`visual`, and the `Chosen option` and `Why I chose it` text as written. It never produces a letter.
When the parser has no letter but the story has written a choice, the story page shows that text in
an amber note ("Chosen, as written in the story"), explains that the parser could not read a letter
(F-1), and **marks no frame**.

Alternatives rejected:
- **Parse the letter in the board.** That makes a second parser that can disagree with the kit. The
  home page's "Awaiting a mockup pick" (kit parser) and this page (board parser) would then give
  different answers about the same story. The owner ruled that the fix belongs in the kit and the
  board stays unchanged.
- **Show nothing.** That hides a decision the owner has already recorded.

## Consequences

- Until F-1 is fixed in the kit, affected stories show a quoted choice with no marked frame. When
  the kit is fixed, they mark correctly on the next refresh with no board change.
- SB-4's browser check expected MOB-56's D to be marked. It is quoted instead, and this is
  intentional.
