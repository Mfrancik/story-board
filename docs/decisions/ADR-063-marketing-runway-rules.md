# ADR-063 — Marketing runway rules: approved-through, date state, "to approve", "thin"

Date: 2026-10-12 · Status: accepted · Story: MK-1

## Context

The campaigns list answers "how far out are we approved, and what needs me" (owner). The story fixed the core
rule: approved-through is the unbroken run of approved dates from the next one. Several details were left to the
prototype (`docs/mockups/MK-prototype/index.html`) or to the builder. MK-7, MK-9 and MK-10 will show the same
states, so this ADR writes the details down once. All of them live in `app/Actions/Marketing/BuildCampaignRunway.php`.

## Decision

- **Date state precedence:** posted > approved > skipped > review (drafts only) > open. Discarded posts are
  ignored. A date with one approved post and two leftover drafts is approved, not "to approve".
- **Approved-through:** approved, posted **and skipped** dates continue the run, and anything else ends it. Skipped
  continues because the prototype treats it so: a skipped date needs nothing from the owner. The run is followed
  up to `RUN_HORIZON` = 26 occurrences (half a year of a weekly campaign).
- **Runway bar:** the first `RUNWAY_SIZE` = 6 dates, labelled "next 6 dates" rather than the prototype's "6 weeks",
  because a multi-day campaign's 6 dates are not 6 weeks.
- **"N to approve":** counts upcoming *dates* whose posts are only drafts. Three candidates on one date is 1,
  because the owner approves one post per date.
- **"Nothing approved past this week" (thin):** approved-through is null, or falls on or before Sunday of the
  current ISO week in the campaign's timezone. The row reads "Nothing approved yet" when null. The banner names a
  campaign for a miss *or* for running thin, never both.
- **"Needs a photo"** (builder's reading): a `per_post` campaign with a drafts-only upcoming date where a draft has
  no `asset_id`.

Alternatives rejected:
- **Skipped breaks the run.** The owner would be sent back to a date that was deliberately left empty.
- **Counting draft posts, not dates.** It would overstate the work three to one once MK-8 drafts three candidates
  per date.
- **Unbounded look-ahead.** The schedule walk needs a cap, and half a year is further out than the owner plans.

## Consequences

- Skipped-continues is not pinned by a test today (only approved and open are). Add one if this rule is ever questioned.
- An approved run longer than 26 dates reads as ending at the 26th.
- MK-7/9/10 should call `BuildCampaignRunway` or share its `state()` rule rather than re-deriving it.
