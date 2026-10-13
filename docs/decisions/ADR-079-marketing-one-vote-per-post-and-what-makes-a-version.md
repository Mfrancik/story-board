# ADR-079 — One vote per post; only a Revise makes a version, and Undo removes it

Date: 2026-10-12 · Status: accepted · Story: MK-9

## Context

MK-8 stores taste in `marketing_feedback` (append-only rows: a vote, or a Revise note) and earlier captions in
`marketing_post_versions`. MK-9 adds thumbs up/down, Undo after a Revise, and a caption box the owner edits directly.
Each of these could change the taste examples or the version history in more than one way.

## Decision

- **One vote per post.** `RecordFeedback` deletes the post's existing vote row and adds the new one in one
  transaction, and a vote of 0 clears it. Feedback rows are never edited in place. Revise notes (`vote` null) are
  left alone.
- **Undo** (`UndoRevision`) copies the latest kept version back onto the post (caption, photo, meta, version number),
  re-lints it, and deletes that version row, so the post row stays "the current version". The feedback given on the
  undone revision is kept, because it still describes the owner's taste.
- **Hand edits do not bump the version.** `EditPostCaption` saves and re-lints only. A version is something the AI
  produced from feedback.

Alternatives rejected:
- **Append every vote.** Changing a like to a dislike would count both, and taste would over-weight indecision.
- **Keep the undone version row.** The next Revise would collide on `(post_id, version)`, and "v2 · Undo" would
  point at a version the post is no longer on.
- **Version hand edits.** "v3" would appear for a typo fix, and Compare would mostly show the owner's own edits.

## Consequences

- `BuildTasteExamples` sees at most one vote per post.
- After Undo, the next Revise reuses the number of the version it undid. Version numbers are not unique over time,
  only at any one moment.
- A hand edit cannot be undone with Undo, which only undoes an AI rewrite.
