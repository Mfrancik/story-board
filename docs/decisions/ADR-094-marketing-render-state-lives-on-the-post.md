# ADR-094 — A post's ad spec and render state live on the post, in `ad`

Date: 2026-10-13 · Status: accepted · Story: MK-5

## Context

Each post needs an ad spec (layout, headline, subline, cta, photo, crop, badge), which MK-19 will later write, and
a render state: none, rendering, rendered or failed. When rendered it also needs the two PNGs. The studio, library
and calendar all need the PNGs. MK-8's drafting state lives in the cache
([ADR-071](ADR-071-marketing-drafting-state-lives-in-the-cache.md)).

## Decision

Add one nullable JSON column, `marketing_posts.ad`:
`{layout, headline, subline, cta, asset_id, crop, badge, render: {status, square, portrait, reason, at}}`.
- `square` / `portrait` are ids of `MarketingAsset` rows with `purpose = render`. The library and calendar read the
  1:1 id straight off the post they already loaded and serve it through the existing `marketing.asset` route, with
  no extra query.
- `QueuePostRender` writes `status = rendering` (and `at`) **before** dispatching, because the sync queue finishes
  the job inside dispatch. It keeps the previous ids so a re-render still shows the old image.
- `MarketingPost::renderState()` reads a `rendering` older than `RENDER_STALE_MINUTES` (10) as `failed`, so a lost
  job ends in Retry, not a forever spinner. This is the same rule as MK-18's `ai_notes`
  ([ADR-088](ADR-088-marketing-one-vision-read-per-image-in-ai-notes.md)).
- `StorePostRenders` stores the new files before deleting the old ones, and deletes only this project's
  `purpose = render` rows.

Alternatives rejected:
- **Cache, as MK-8's drafting state.** Asset ids must outlive a cache flush. Drafting state is transient, but a
  render is a durable artefact.
- **A `marketing_post_renders` table.** That means one more join for every card and a history nobody asked for.
  Only the latest render matters.
- **A `post_id` on `marketing_assets`.** It would touch MK-2's schema and still need the state somewhere.

## Consequences

- Only the latest render is kept. Renders of drafts that Approve or Regenerate discard are not cleaned up, so the
  disk grows until a later story handles it.
- The spec and the render share one column, so every writer spreads the existing `ad` and replaces only its own
  keys.
