# ADR-077 — Skipping an open date writes a placeholder post, and Restore undoes a skip

Date: 2026-10-12 · Status: accepted · Story: MK-9

## Context

A campaign date's state is never stored. It is read from the posts on that local day
(`BuildCampaignRunway::state()`), and a skipped date counts as covered for approved-through (ADR-063). Skipping a date
that has drafts is easy: mark them `skipped`. But an open date has no posts to mark. The story also had no way back
from Skip. The owner asked for Restore during the build and kept it.

## Decision

- `SkipOccurrence` marks a date's drafts `skipped`. On an open date it creates one `skipped` post with an empty
  caption at the campaign's time (`source` manual). It refuses a date that has an approved or posted post.
- `RestoreOccurrence` turns that date's skipped drafts back into drafts and discards the empty-caption placeholder,
  so an open date reads as open again.

Alternatives rejected:
- **A `marketing_skipped_dates` table, or skipped dates on the campaign.** This would add a second source of truth
  for a date's state, which every reader of the runway would then have to merge.
- **Deleting a skipped date's drafts.** Restore could not bring them back.

## Consequences

- The skip is visible and survives reloads with no new schema. It shows on the runway and timeline like any skipped
  date.
- The placeholder is recognised only by its empty caption. Nothing else may save an empty caption, and
  `EditPostCaption` and `CreateOneOffPost` refuse one.
- Placeholders are real `marketing_posts` rows. A future reader that lists posts (MK-10's library) should filter out
  `skipped` posts with an empty caption.
