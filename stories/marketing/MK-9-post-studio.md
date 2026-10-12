# MK-9 — Post studio: big preview, flip, rebuild from feedback, approve & next
Status: approved        Journey: marketing-post (step 5 of 7)
Source: owner prototype round 2: "click fill, i want a modal to pop up with a big screen of the ad, and maybe 2 or 3 to
flip through, and a box i can respond with changes where AI rebuilds… review kind of becomes redundant"

## Story
As the owner, I want one large studio to see a date's drafts big, flip between them, tell the AI what to change,
add a photo and approve, so that I can approve several weeks ahead in one sitting.

## Why
It replaces the separate Review page: every pick-and-steer action happens in one place, opened from wherever the
owner sees a post.

## In scope
- Livewire `Marketing\PostStudio` modal, opened from: a campaign page date, the campaigns-list badges / Start approving,
  and later calendar chips and library cards (MK-10). Esc / backdrop closes; full-screen sheet on mobile.
- Header: campaign, date/time, state, "1 of 7 to approve".
- Big square preview in the project's brand (MK-2 values: font, colours, radius, logo) — the caption-over-photo or
  text card layout from the prototype; ← / → and dots flip the date's 3 drafts.
- **"What should change?"** box + chips → `ReviseDraft` (MK-8) → "Rebuilding…" → the draft updates in place with
  "v2 · Undo · Compare" (compare is a toggle).
- Thumbs up / down (small) with reason chips on a dislike (too wordy, wrong tone, boring, off-brand) → `marketing_feedback`.
- Caption editable directly; MK-8 lint warnings shown inline (warn only).
- Photo: "Upload a photo for this post" (stored as an asset, not a favourite), "Use a favourite", "Text only". A draft
  with no photo under photo mode `per_post` shows "Needs a photo" and Approve is held until one is chosen.
- **Approve for <date time>** and **Approve & next (N left)** — approving one draft discards the date's others.
- "♡ Save to Inspiration" on every candidate and on approved/posted posts (one click, toast with Undo; MK-18 action).
- Skip date, Regenerate all 3 (MK-8), "+ One-off post" (a manual post on a chosen date, no campaign).
- Approved post: Copy caption (clipboard), Download image (original file), Reschedule (date-time), Unapprove,
  **Mark as posted** with an optional Facebook post link (stored as the post's `facebook` network row, so phase 2
  can backfill engagement). Missed post: Post it now (copy + download + mark posted) and Reschedule.

## Out of scope (do NOT build)
- Publishing to Facebook (phase 2), the designer / templates / PNG render (phase 3), engagement numbers (phase 2).
- Retiring a page or feature? The planned Review page (MK-9 deck A) is replaced by this studio — owner OK; every deck
  action (flip, like/dislike, edit, confirm, skip, regenerate, one-off) has a home here.

## Acceptance criteria (executable — these become the Pest test names)
- Given a date with 3 drafts, when I open the studio, then the first draft shows big and ←/→ flips to the others.
- Given I type a change and submit, then `ReviseDraft` runs (faked), the draft shows v2, and Undo restores v1.
- Given I dislike with reason "too wordy", then a feedback row with vote -1 and that reason exists.
- Given photo mode `per_post` and no photo, then Approve is disabled with "Needs a photo"; after an upload it enables.
- Given I upload a 20 MB file, then it is rejected and nothing changes.
- Given I approve draft 2, then it is `approved` on that date, drafts 1 and 3 are `discarded`, and the campaign's
  approved-through advances (MK-1).
- Given 3 dates to approve, when I Approve & next, then the studio moves to the next date and the counter drops.
- Given an approved post, when I Mark as posted with a link, then status is `posted`, `posted_at` is set, and a
  `facebook` network row stores the link; an invalid URL is rejected.
- Given an approved post, when I Reschedule into the past, then it is refused.
- Given a 375px viewport, then the studio is a full-screen sheet with no sideways scroll and Approve stays visible.

## Applicable standards
- Design: prototype studio; modal per design-standards (detail quick-peek); reuse `board/confirm-modal` for Unapprove;
  brand preview as a component `marketing/post-preview` (reused by MK-10's library cards).
- Codebase/DB: Actions `ApprovePost`, `SkipOccurrence`, `ReschedulePost`, `MarkPostPosted`, `RecordFeedback`;
  Alpine for flipping and the compare toggle (no round-trip for UI state).
- Logging: `marketing.post.approved|unapproved|skipped|rescheduled|posted`, `marketing.feedback.recorded`
  (context: post_id, campaign_id, version).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-prototype/index.html (studio); docs/mockups/MK-9/option-a.html (earlier deck)
- Chosen option: the prototype studio, owner 2026-10-12 (supersedes the MK-9 deck A pick)
- Why I chose it: "a modal… with a big screen of the ad, and maybe 2 or 3 to flip through, and a box i can respond with
  changes where AI rebuilds".

## Do NOT touch
- `config/`; MK-8's prompt building (call it, don't change it).

## Data & interfaces
- `marketing_post_networks`: id, post_id (FK cascade), network (`facebook`), status (`posted`), external_id (null),
  url (null), engagement (json null), synced_at (null), timestamps. Unique (post_id, network).
- Livewire `Marketing\PostStudio`; component `marketing/post-preview`.

## Test plan
- Pest: failing tests first (Livewire component tests + Actions), AI faked.
- Journey test: step 5 — open the studio on TrueView Tuesday, revise, upload a photo, Approve & next.
- Browser check: real coins data — Fill, open studio, flip, revise, approve two dates, Copy caption; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-7, MK-8 · The Save-to-Inspiration button ships with MK-18 (hidden until then)
