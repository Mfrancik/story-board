# ADR-091 — Save to Inspiration copies the photo, keeps text-only posts as text cards, and undoes inline

Date: 2026-10-13 · Status: accepted · Story: MK-18

## Context

Owner round 5: "if i generate an ad i like i should have a quick way to add to inspirations". One click from the
studio and the library makes a post a favourite inspiration, with Undo. A post may have a photo or be text only.

## Decision

`app/Actions/Marketing/SaveToInspiration.php`:
- **Copies** the post's photo to `marketing/{project}/inspirations/`. Deleting either the post's photo or the
  inspiration never removes the other's file.
- A post with no photo becomes a **text card**: `path = ''`, `mime = MarketingAsset::TEXT_CARD_MIME`, drawn with
  `marketing/post-preview` from the stored caption. `DeleteMarketingAsset` skips the disk delete for an empty path.
- Notes come from the caption (`source: post`, `post_id`), so no Claude call. `ai_notes.post_id` makes a second
  save a no-op and is what Undo (`RemoveSavedInspiration`) finds.
- Undo is an inline "♥ Saved · Undo" control on the card or in the studio, not a toast action.

Alternatives rejected:
- **Share the post's asset row.** Undo or a wall delete would null the post's photo (the FK is `nullOnDelete`).
- **Skip text-only posts.** Their copy is exactly what the examples are for.
- **Undo in a toast.** Flux toasts cannot hold a button.

## Consequences

- A saved photo is stored twice. Saved state is looked up by a JSON path (`ai_notes->post_id`), one query per page.
