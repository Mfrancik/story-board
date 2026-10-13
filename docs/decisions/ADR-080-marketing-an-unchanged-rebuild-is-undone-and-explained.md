# ADR-080 — A rebuild that changes nothing is undone and explained, not kept

Date: 2026-10-12 · Status: accepted · Story: MK-9 (fix `d415a49`)

## Context

While testing with a real key, the owner typed "id like an image of a nice toned morgan dollar here" into a draft's
"What should change?" box. The project had no favourite photos. MK-8's `ReviseDraft` only lets the model pick a
photo when the "Different photo" chip is chosen in a campaign whose photos come from favourites, so the model could
not add one. It returned the same caption, `ReviseDraft` still kept it as v2, and the studio showed "Rebuilt as v2"
with no explanation. The result was an identical v2 in Compare and Undo, and no hint about why nothing changed.

## Decision

`PostStudio::revise()` (`app/Livewire/Marketing/PostStudio.php`) compares the trimmed caption and `asset_id` before
and after `ReviseDraft`. If both are the same:

- **The version is undone** with the existing `UndoRevision`, so no version that changes nothing is kept.
- **The owner's note still counts.** The `marketing_feedback` row `ReviseDraft` wrote stays, as it does after any
  Undo (ADR-079). It is still taste feedback.
- **The studio stays open and the box keeps the text.** `revise()` returns false, which is the same signal a refusal
  sends, so the owner can edit the note and try again.
- **A warning under the box says why.** `app/Actions/Marketing/ExplainUnchangedRevision.php` picks the first reason
  it can know, checked in this order: `same_caption` (no photo was asked for, by chip or by a word like
  photo/image/picture), `text_only`, `no_favourites` (with an "Open Settings" link), `per_post`, `photo_chip`
  (a photo was asked for in words but the chip was not chosen), and `kept_photo`. It logs
  `marketing.ai.revise.unchanged` (info) with the `reason`.

Alternatives rejected:
- **Change `ReviseDraft` so it does not keep an identical version.** `ReviseDraft` is MK-8's and is on MK-9's
  Do-NOT-touch list. The studio is the only caller that shows the result, so the studio is where the outcome is
  judged.
- **Keep the no-op v2.** Compare would show two identical captions, Undo would undo nothing visible, and the owner
  would still not know why.
- **A generic "nothing changed" message.** The cause can usually be known from the campaign's photo mode, the chip
  and whether the project has favourites. Naming the cause tells the owner what to do next.

## Consequences

- An unchanged rebuild still makes and pays for one Claude call. Only the version is rolled back.
- `UndoRevision` logs `marketing.post.revision_undone` for these rollbacks too, so that log line no longer always
  means the owner pressed Undo. Read it next to `marketing.ai.revise.unchanged`.
- The warning is cleared by any later change (`changed()`), by `open()`, `close()` and Approve & next.
- The photo-word match is a simple English regex. A request phrased without those words is explained as
  `same_caption`.
