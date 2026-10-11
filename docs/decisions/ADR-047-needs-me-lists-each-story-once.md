# ADR-047 — Needs me lists each story once; In flight keeps the owner's definition

Date: 2026-10-11 · Status: accepted · Story: RD-5

## Context

The overview's Needs me merges SB-3's three groups into one list: mockup sets awaiting a pick, drafts awaiting
approval, approved stories ready to build. The groups overlap. A story awaiting a pick is, by `awaitingPick()`'s
own predicate, also a draft or approved, so a plain union lists it twice. The headline row also has an **In flight**
figure, which the story defines as "approved stories not yet built". That is nearly the same set as Needs me's
"ready".

## Decision

- `ListNeedsMe::handle()` runs the predicates in the order pick, draft, ready and keys rows by story ID with
  `??=`, so each story appears **once**, under the first kind that matches. A pick outranks draft and ready
  because the pick is what blocks the story. "Ready" therefore means approved minus those awaiting a pick, and the
  "2 picks · 1 draft · 3 ready" split always adds up to the row count.
- Rows sort by `dated_on` (the date in the story's `Source:` line), undated last. Ages read `today`, `Nd` or
  `undated`. Only a date is stored, so there are no hours to show.
- **In flight** stays every approved story on the ref of an enabled project. The owner ruled on 2026-10-11 to keep
  it as written, even though it overlaps Needs me's ready set (it equals ready plus approved stories awaiting a pick).
- The three predicates move to `ListNeedsMe` as public static query narrowers, and `ListWhatNeedsMe` calls them, so
  the old dashboard and the overview cannot drift apart before RD-9.

Alternatives rejected:
- **List a story under every kind it matches.** It double-counts the headline figure, and the owner would see two
  rows for one decision.
- **Redefine In flight as approved minus Needs me's ready** (or drop it). It is not what the owner asked for; the
  figure is meant to say how much approved work is queued, whatever blocks it.
- **Age by hours since a status change.** Not stored. Only the `Source:` date is in the snapshot.

## Consequences

- One row is one decision. A story that is approved and awaiting a pick shows as a Pick until it is picked, then
  as Ready.
- In flight ≥ Needs me's ready count, always. A reader comparing the two figures sees the overlap; that is accepted.
