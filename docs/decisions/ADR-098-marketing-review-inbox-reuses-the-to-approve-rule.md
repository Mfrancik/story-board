# ADR-098 — The review inbox reuses the "to approve" rule; one-offs are inbox-only

Date: 2026-10-13 · Status: accepted · Story: MK-20

## Context

MK-20's story describes the queue loosely: "every date (campaign or one-off) that has drafts and no approved post".
The board already has one rule for a campaign date that waits for a pick, `BuildCampaignRunway::datesToApprove()`
([ADR-076](ADR-076-marketing-to-approve-is-one-rule-for-list-and-studio.md)). It requires the campaign to be on, the
date upcoming, and drafts present with no approved, posted or skipped post. That rule drives the Marketing tab's
count, the campaigns list badges and the studio's queue. The looser wording would also take in past dates, paused
campaigns and skipped dates.

## Decision

- `ListReviewQueue` takes campaign dates from `datesToApprove()` as it is, without restating the rule. The Review
  badge and the tab count therefore always agree on campaign dates.
- One-offs are added by the inbox only. Each upcoming one-off whose `BuildCampaignRunway::state()` is `review` is its
  own date with one ad. `CountPostsToApprove` and the studio queue are unchanged.
- Dates that MK-8 is drafting or failed to draft (from `DraftingStatus`, for each campaign's next 8 open dates) are
  shown as rows, but are not counted. This keeps a rejected date in view until its new ads arrive.

Alternatives rejected:
- **Follow the story's wording literally.** It would be a second "undecided" rule, and the inbox would disagree with
  the tab about past, paused and skipped dates.
- **Add one-offs to the shared rule.** It would change the tab count, the list badges and the studio's queue, which
  this story was told not to touch.

## Consequences

- When one-offs wait, the tab count and the Review badge differ (the build's screenshot showed Marketing 3, Review 4).
  The difference is always the number of waiting one-offs.
- Any later change to "to approve" reaches the inbox automatically.
- A date filled from the campaign page also shows as "Drafting…" in the inbox, because the drafting rows are not
  limited to dates rejected from the inbox.
