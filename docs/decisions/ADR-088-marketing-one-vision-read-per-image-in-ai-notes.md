# ADR-088 — One vision read per marketing image, stored in `ai_notes`

Date: 2026-10-13 · Status: accepted · Story: MK-18

## Context

MK-18 wants drafts to follow ads the owner likes, and Fill to pick favourite photos by what they show rather than
by file name. Claude has to see each image somehow. Embeddings and layout matching are out of scope.

## Decision

Each image is sent to Claude **once**, downscaled to 1024 px, by `app/Actions/Marketing/ReadMarketingAsset.php`
on the `ReadInspiration` job. The answer is kept in MK-2's `marketing_assets.ai_notes`: `headline`, `style[]` and
`topic` for an inspiration, `description` (≤ 120 chars) for a favourite photo. Every prompt after that reuses the
stored text. Images whose words are already known (kit images with their kit captions, posts saved to
Inspiration) are written with `status: read` and never sent.

Read state lives in `ai_notes.status` (`reading | read | failed`, absent = unread) and is read only through
`MarketingAsset::readState()`. A `reading` older than 10 minutes reads as `failed`, matching the job's 600 s unique
lock, so a lost job ends in Retry. `QueueAssetRead` is the one way in: it marks `reading` before dispatch and
refuses an image already reading.

Alternatives rejected:
- **Send the images with every draft.** Pays for vision on every Fill; the wall would cost more the more it is used.
- **Embeddings for similarity.** Out of scope, and a second provider for a few dozen images.
- **Read state in the cache (like MK-8's drafting state, ADR-071).** The read result has to live on the row anyway;
  keeping state beside it means one source and no cache dependency.

## Consequences

- One call per image, logged with tokens and cost. Copy and tone are what Claude can follow from this; layout waits
  for the designer.
- A failed read stays failed until the owner presses Retry (one attempt, as ADR-072).
