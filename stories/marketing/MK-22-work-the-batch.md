# MK-22 — Work the batch: step, revise, vote, mark Ready or Drop
Status: draft          Journey: marketing-post (step 12 of 14)
Source: owner 2026-10-14 (verbatim idea above) — "i can iterate through each, give feedback, and work building those
several till im ready"; Ready representation decided in this story

## Story
As the owner, I want to step through the ads a batch made, change them with a note, like or dislike them, and mark
each one Ready or Drop it, so that I build up a stock of ads I'm happy with (30 or more is fine) before any are dated.

## Why
MK-21 makes the ads; this is where they become finished. Revise, votes and approval already exist (MK-8, MK-9) — the
maker calls them on undated posts instead of growing a second editing path.

## In scope
- In MK-21's `Marketing\PostMaker`, per the chosen mockup: tabs/sections **Working (N)** and **Ready (N)**, plus a
  collapsed **Dropped** list. Working = this campaign's undated `draft` posts; Ready = its undated `approved` posts;
  newest batch first, then `candidate`.
- A focused ad, big (1:1 render, 4:5 toggle as the studio), with caption, layout line, its batch note, topic, and
  "Inspired by" if any. **←/→** (and on-screen arrows / swipe) step through Working; keys are Alpine-only.
- **Revise**: "What should change?" + MK-8's chips → `ReviseDraft` (new version, re-render; old spec kept in the
  version). **Undo** the last revision → `UndoRevision`. An unchanged rebuild is explained with
  `ExplainUnchangedRevision`, as the studio does.
- **👍 / 👎** with MK-8's four dislike reasons → `RecordFeedback` (feeds taste for the next batch automatically).
- **Photo** (photo-per-post campaigns): Use a favourite / Upload / Text only → `SetPostPhoto` /
  `StoreMarketingAsset`, so "Needs a photo" can be cleared here.
- **Ready** (key `Enter` or `R`) → `ApprovePost`. **Ready = `approved` with `scheduled_for` null** (below). Refused
  while `needsPhoto()`. Focus moves to the next working ad.
- **Back to working** on a Ready ad → `UnapprovePost` (back to `draft`, still undated).
- **Drop** (key `D`) → new `DropMakerPost` sets `discarded`; an optional dislike reason is recorded through
  `RecordFeedback`. A toast offers **Undo** (back to `draft`) until the next action.
- **Make more** → MK-21's Make 3/4/5 with the current rules; the new batch joins Working.
- Ready count shown in the header and on the campaign page's Post maker link; no upper limit.
- Guards for undated Ready posts: `ReschedulePost` and `MarkPostPosted` refuse a post with `scheduled_for` null
  (`not_placed`) — only placing (MK-23) gives it a date.
- `MarketingPrompts::revise()` uses MK-21's undated campaign heading for an undated campaign post (today it would
  call it "A one-off post.").

## Out of scope (do NOT build)
- Placing (MK-23); hand caption editing in the maker (Revise covers it; `EditPostCaption` stays studio-only); bulk
  Ready/Drop; reordering Ready (MK-23's preview orders them); the cross-campaign view (MK-24 repurposes Review).
- Opening maker posts in the studio — the studio reads an undated campaign post as a one-off; the maker is their only
  door.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given a batch of 4 working ads, when I open the maker, then Working shows 4, Ready 0, and the newest batch's first
  ad is focused.
- Given I press → then ←, then the focus moves to ad 2 and back to ad 1 with no server request.
- Given I revise ad 2 with "make it a question" and faked Claude changes the layout, then ad 2 is version 2, its old
  spec is kept in a version, a re-render is queued, and it is still undated.
- Given Claude fails on a revise, then the ad is unchanged and MK-8's revise failure message shows.
- Given I dislike ad 3 with "too wordy", then one feedback row with that reason exists and the next Make's prompt
  quotes ad 3 under disliked taste.
- Given I mark ad 1 Ready, then it is `approved` with `scheduled_for` null, Working shows 3, Ready 1, and
  `marketing.maker.marked_ready` is logged.
- Given a photo-per-post ad with no photo, when I press Ready, then it is refused with "Add a photo first…", it stays
  working, and the refusal is logged; after Use a favourite, Ready succeeds.
- Given a Ready ad, when I press Back to working, then it is a `draft` again with no date and can be revised.
- Given I Drop ad 4 with reason "boring", then it is `discarded`, a dislike with "boring" exists, and Undo returns it
  to Working as a draft.
- Given 30 Ready ads, then the maker lists all 30 and the header reads "Ready 30".
- Given a Ready (undated) post, when `ReschedulePost` or `MarkPostPosted` is called on it, then each refuses with
  `not_placed`, logs it, and writes nothing.
- Given another project's post id, or a post of another campaign, or a dated post, in Ready / Drop / Revise / vote /
  photo / Back to working, then it is refused with 404 and `marketing.maker.refused` (reason `not_in_maker`) is logged.
- Given an empty Working list with Ready ads, then Working shows "All worked through — Make more or place your ready
  ads."; with no ads at all, MK-21's empty state shows.
- Given a 375px viewport, then the focused ad fills the width, actions sit below it, and nothing scrolls sideways.

## Applicable standards
- Design: MK-21's chosen mockup; reuse `marketing/ad-square`, the studio's chip, vote and reason styles (extract a
  partial only if the studio's markup can be shared without changing the studio's output), `board/confirm-modal`
  not needed (Drop has Undo). Alpine for focus, keys and the 1:1/4:5 toggle.
- Codebase/DB: no migration. Reuse `ReviseDraft`, `UndoRevision`, `ExplainUnchangedRevision`, `RecordFeedback`,
  `ApprovePost`, `UnapprovePost`, `SetPostPhoto`, `StoreMarketingAsset`, `QueuePostRender` — no second path. New
  `DropMakerPost`, `RestoreDroppedPost` (or one Action with `restore`), and a `PostMaker::makerPost($id)` guard that
  checks project, campaign and `scheduled_for` null. One query for the lists with renders, latest vote and versions
  eager-loaded.
- Logging: `marketing.maker.marked_ready` (info: post_id, campaign_id, batch), `marketing.maker.back_to_working`
  (info), `marketing.maker.dropped` (info: post_id, reason), `marketing.maker.drop_undone` (info),
  `marketing.maker.refused` (warning: reason, post_id). `marketing.post.refused` gains `not_placed` from
  `ReschedulePost`/`MarkPostPosted`. Existing revise, feedback, approve and render events unchanged.

## Ready representation — and every query that assumed "approved ⇒ dated"
Ready is `status = approved`, `scheduled_for = null`. Placing then only sets a date, so a placed post *is* an
approved dated post and every existing reader treats it correctly. Checked:
- Safe as is (filter `whereNotNull('scheduled_for')` or a date range): `MarketingPost::scopeRunwayWindow` → so
  `BuildCampaignRunway::handle()/datesToApprove()`, `CountPostsToApprove`, `ListDatesToApprove`, `ListReviewQueue`,
  `ListCampaignOccurrences`, `CampaignPage` timeline; `BuildMarketingCalendar` (own `whereNotNull`); `ListLibraryPosts`
  (own `whereNotNull` — Ready posts are not "Coming up" until placed); `FindOccurrencePosts`; `DraftOccurrence`;
  `MarketingPost::isMissed()` (null-safe).
- Works and is wanted: `ApprovePost` (null date → no siblings to discard), `UnapprovePost`, `SetPostPhoto`,
  `EditPostCaption`, `QueuePostRender`, `QueueMissingRenders` (renders Ready ads too), `MarketingRenderController`
  (file name omits the date).
- Must change (this story): `ReschedulePost` and `MarkPostPosted` — refuse `not_placed`; `MarketingPrompts::revise()`
  heading; `PostStudio::open(post)` would show an undated campaign post as a one-off — not changed, the maker never
  sends one there (see Out of scope).
- `CampaignPage` "Past posts" reads `posted`/`posted_at` only — unaffected.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Gate cleared by MK-21's pick: docs/mockups/MK-21/option-<chosen>.html (the "work the batch" panel).
- Chosen option: as recorded in MK-21.
- Why I chose it: as recorded in MK-21.

## Do NOT touch
- `config/`; `PostStudio` and its view; `ReviseDraft`/`RecordFeedback`/`ApprovePost`/`UnapprovePost` behaviour (call
  them; the only Action edits are the `not_placed` guards in `ReschedulePost` and `MarkPostPosted`); Fill (MK-24);
  MK-8 taste and MK-18 inspiration selection.

## Data & interfaces
- Schema: none.
- `PostMaker` gains `revise(id, feedback, chips)`, `undo(id)`, `vote(id, vote, reasons)`, `ready(id)`,
  `backToWorking(id)`, `drop(id, ?reason)`, `undoDrop(id)`, `useFavourite(id, asset)`, `textOnly(id)`, uploads.
- Added: `DropMakerPost::handle(MarketingPost, ?string $reason, bool $restore = false)`. Changed: `ReschedulePost`,
  `MarkPostPosted` (guard), `MarketingPrompts::revise()` (heading).
- Test hooks: `data-maker-working`, `data-maker-ready`, `data-maker-dropped`, `data-maker-focus="<id>"`,
  `data-ready="<id>"`, `data-drop="<id>"`, `data-back-to-working="<id>"`, `data-ready-count`, `data-make-more`.

## Test plan
- Pest: failing tests first, Claude and renderer faked.
- Journey test: new step 12 — on Ask the community's maker (batch from step 11), revise ad 2 with a note, dislike ad
  3 with "boring" and Drop it, mark ads 1 and 2 Ready; Ready reads 2.
- Browser check: coins with the real key — work a batch of 5 with keys only (→, R, D), revise one, then 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above (MK-21's)
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-21, MK-8, MK-9
Open question: should Ready also be allowed on a failed render (MK-20 allowed Keep on one)? Default: yes — placing
re-queues the render.
