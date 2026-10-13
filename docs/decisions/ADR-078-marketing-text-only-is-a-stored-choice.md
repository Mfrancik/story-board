# ADR-078 — "Text only" is a stored choice on the post (`is_text_only`)

Date: 2026-10-12 · Status: accepted · Story: MK-9 · Owner-approved schema addition, 2026-10-12

## Context

Under photo mode `per_post`, a draft with no photo shows "Needs a photo" and Approve is held. The story also offers
"Text only". With only `asset_id` on the post, "no photo because none was chosen yet" and "no photo because the
owner wants the text card" look the same, so a text-only post would stay held forever.

## Decision

- Migration `2026_10_12_000010` adds `marketing_posts.is_text_only` (boolean, default false). The story did not list
  this column. The owner approved it during the build.
- `SetPostPhoto` writes both fields together: a photo sets `asset_id` and clears the flag, and Text only clears
  `asset_id` and sets the flag.
- `MarketingPost::needsPhoto()` is `asset_id` null, not text only, and campaign mode `per_post`. The studio's
  disabled button and `ApprovePost`'s `needs_photo` refusal both read it.

Alternatives rejected:
- **Treat "Text only" as switching the campaign's photo mode.** It changes every future post, when the owner meant
  this one.
- **A sentinel asset, or a value in `generation_meta`.** It hides a real choice in a place no query reads.

## Consequences

- The hold is enforced server-side, not only by the button.
- A text-only post has nothing to download until phase 3 renders the text card to an image.
