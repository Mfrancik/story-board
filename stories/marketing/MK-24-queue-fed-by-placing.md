# MK-24 — The dated queue is fed by placing: retire per-date Fill, Regenerate and Reject all
Status: draft          Journey: marketing-post (step 14 of 14)
Source: owner 2026-10-14 (verbatim idea above) — "That ui where we have the daily post queue is fine, but the process
of adding to that queue is what I want to change" (owner decision 1); "i dont want to have to turn a campaign on to
generate posts"; Review inbox repurposed to maker batches (assumption accepted by approving the list)

## Story
As the owner, I want the dated queue to be filled only by placing Ready ads, and the Review page to show every
campaign's batches waiting on me, so that there is one way to make posts and I never pick one of three for a day again.

## Why
After MK-21–23 two paths add posts to a date: Fill (3 per date, pick 1, campaign must be on) and make → place. Two
paths mean two sets of rules and buttons that disagree. The dated queue, calendar and runway stay exactly as they are.

## In scope
- **Retire per-date Fill**: the campaign page's row Fill / Drafting… / Retry and "Fill N open dates"; the studio's
  "Fill this date" and "Regenerate all 3"; Review's Reject all and draft Retry. Delete `FillCampaign`,
  `DraftOccurrence`, `DraftOccurrenceJob`, `RegenerateOccurrence`, `RejectDateDrafts`, `DraftingStatus`, and
  `MarketingPrompts::draft()` once nothing calls them (MK-21's `make()` keeps the shared prompt parts).
- An **open** date (timeline row, studio, "N open dates ›" badge) now says "Open — place ready posts" and links to the
  campaign's maker with the placement preview open (or "Make posts" when nothing is Ready).
- **On/off** gates nothing about making or placing. On = running: alerts, approved-through, the tab count, calendar
  ghosts and (phase 2) publishing. The switch's help text says so; "Turn it on to fill its dates" is gone.
- **Review repurposed** (`/marketing/review`, `ListReviewQueue`, `Marketing\Review`): rows are **maker batches with
  working ads**, across every campaign (on or off), newest batch first; each row = campaign chip, batch note, its
  working ads (1:1 renders). Same grid, focus ring and keys: ←/→ between batches, **1–5 = Ready** that ad (was Keep),
  **D** drop the focused ad, 👍/👎 with reasons, "Open in maker" (was open in studio). Making / failed batches show
  "Making…" / "Making failed · Retry" (MK-21's retry). Sub-nav badge = working ads across campaigns.
- Legacy dated drafts (from Fill before this story) and one-off drafts stay approvable in the studio as today
  ("N to approve ›", "Start approving", calendar chips). They are no longer in Review.

## Out of scope (do NOT build)
- Changes to the calendar, library, runway rules, studio Approve/Reschedule/Mark posted, one-off creation, MK-23.
- Migrating legacy dated drafts into the maker.
- **Retiring flows — every user action and its successor:**
  - Campaign page row **Fill** (`fillDate`) → maker Make 3/4/5 (MK-21) then Place (MK-23); the row links there.
  - Row **Drafting… / "Writing 3 drafts…"** and **"Drafting failed · Retry"** → maker's "Making…" / "Making failed ·
    Retry" (MK-21).
  - Future posts header **"Fill N open dates"** (`fillOpen`) → maker **Place N ready posts** (MK-23).
  - "Add ANTHROPIC_API_KEY to .env" line under Future posts → shown on the maker's Make buttons (MK-21).
  - Studio open-date **Fill this date** (`PostStudio::fillDate`) and its drafting poll → "Place ready posts" link.
  - Studio **Skip this date** / **Restore this date** → kept unchanged (placing skips skipped dates, MK-23).
  - Studio **Regenerate all 3** (`regenerate`) on a dated draft date → dropped — owner OK (listed for retirement);
    per-draft Rebuild, votes, Skip and Approve stay for legacy drafts.
  - Review **Keep** (button, keys 1/2/3) → **Ready** (button, keys 1–5), via `ApprovePost` on undated drafts.
  - Review **Reject all 3** (button, key R, optional shared reason; dislikes all and redrafts the date) → dropped —
    owner OK (listed for retirement); successor: 👎 with a reason and **Drop** (D) per ad, then **Make more**.
  - Review **Retry** on a failed draft row (`retryDraft`) → MK-21's Make Retry on a failed batch row.
  - Review **Retry render** (`retryRender`), **👍/👎 + reasons**, **←/→**, **campaign chips** → kept.
  - Review **One-off** chip and one-off rows → dropped from Review — owner OK needed (see question); successor: the
    calendar chip → studio Approve, unchanged.
  - Review **open in studio** → **Open in maker** for maker ads.
  - Campaigns list **"N open dates ›"** (opened the studio on the first open date, which offered Fill) → links to the
    campaign's maker Place. **"N to approve ›"**, **"Needs a photo ›"**, **"Missed ›"**, **Start approving** → kept.
  - Paused-campaign refusals (`paused` in Fill / Reject all) → gone; nothing is refused for being off.

## Acceptance criteria (executable — these become the Pest test names)
- Given an open campaign date, when I open the campaign page, then the row has no Fill button and links to the maker's
  placement ("Place ready posts"), and the header has no "Fill N open dates".
- Given an open date in the studio, then there is no "Fill this date", and "Place ready posts" opens the maker with
  the preview; given a date with legacy drafts, there is no "Regenerate all 3" and Approve still works.
- Given a paused campaign, when I make a batch, mark two Ready and place them, then all three succeed and nothing
  logs `paused`.
- Given two campaigns (one off) with working maker ads and one dated legacy draft, when I open Review, then exactly the
  two campaigns' batches show, newest first, the legacy draft does not, and the badge counts the working ads.
- Given I press 2 on a focused batch, then that batch's ad 2 is Ready (`approved`, no date) and leaves the row; when a
  batch has no working ads left, the row leaves the queue.
- Given D on a focused ad, then it is dropped with Undo, as in the maker.
- Given another project's post id in Ready / Drop / vote on Review, then it is refused with 404 and
  `marketing.review.refused` is logged.
- Given a failed batch, then its row shows "Making failed · Retry" and Retry queues MK-21's make.
- Given no working ads anywhere, then Review shows "Nothing waiting. Make a batch on a campaign's Post maker." and the
  badge is hidden.
- Given the retired Livewire methods (`fillDate`, `fillOpen`, `regenerate`, `rejectAll`, `retryDraft`) are called
  directly, then they do not exist (method-not-found) — no hidden path back.
- Given the code base, then no class references `FillCampaign`, `DraftOccurrence`, `RegenerateOccurrence`,
  `RejectDateDrafts` or `DraftingStatus` (arch test).
- Given a 375px viewport on Review, then each batch's ads swipe inside the row with no sideways page scroll.

## Applicable standards
- Design: n/a — reuses existing UI (MK-20's grid, MK-21's maker, existing link styles); copy changes only.
- Codebase/DB: no migration. `ListReviewQueue` is rewritten over one query (campaign posts, `scheduled_for` null,
  `draft`, with renders and latest vote), grouped by `generation_meta.batch`; `MakingStatus` for making/failed rows.
  **Before deleting any test file, sort every `it()`** (codebase-standards §Testing): port kept-code tests — Revise,
  taste, lint, topic rotation, inspiration ids, ad-spec normalising, invalid-asset, render queueing, the art-director
  layout rule (as MK-21's batch rule), Review votes/refusals/render states — to the maker/Review tests, and put the
  old → new mapping in the handoff. Remove copy that points at retired buttons (L-13).
- Logging: `marketing.review.viewed` (debug: batches, filter), `marketing.review.readied` (info: post_id, campaign_id,
  batch), `marketing.review.dropped`, `marketing.review.refused` (warning). Retired: `marketing.ai.fill.*`,
  `marketing.ai.draft.*`, `marketing.review.kept`, `marketing.review.rejected_all`.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- n/a — reuses existing UI (MK-20's chosen grid with batch rows; maker links). If the build finds a batch row cannot
  fit MK-20's grid, stop and raise a mockup.

## Do NOT touch
- `config/`; the calendar, library, `BuildCampaignRunway`, `CampaignSchedule`, `ListCampaignOccurrences` rules;
  `ApprovePost`, `ReviseDraft`, `RecordFeedback`, `ReschedulePost`; MK-21–23 behaviour (call it); one-off flow;
  existing migrations; ADR files (the documenter marks ADR-071/072/073/098/099 superseded).

## Data & interfaces
- Schema: none.
- Removed: `CampaignPage::fillDate/fillOpen`, `PostStudio::fillDate/regenerate`, `Review::rejectAll/retryDraft`,
  the five Actions/job/service above, occurrence-row Fill markup and `data-fill*` hooks.
- Changed: `ListReviewQueue::handle(Project, $now, $filter)` → `{batches, waiting, total, chips}`; a row is `{key:
  <slug>/<batch>, campaign, batch, note, posts, making: null|'making'|'failed'}`. `Review`: `ready(id)`, `drop(id,
  ?reason)`, `undoDrop(id)`, `vote(...)`, `retryMake(campaign)`, `retryRender(id)`. Hooks `data-ready`, `data-drop`,
  `data-review-row="<slug>/<batch>"`.

## Test plan
- Pest: failing tests first; port then delete (mapping in the handoff).
- Journey test: every step that used Fill drafts is rewritten onto the maker — step 4 makes a TrueView Tuesday batch
  and places one Ready ad (photo set directly) on Nov 3; step 5 opens that placed post in the studio (Reschedule,
  Copy, Back to Ready → place again; draft flipping/Rebuild now live in step 12); step 7 makes a batch after an
  Inspiration drop and checks `inspiration_ids`; step 8 checks Ask the community's batch renders (1:1 / 4:5
  download); step 9 checks the batch's layouts differ (MK-21's rule); step 10 Readies an ad from Review with key 1.
  Steps 11–13 (MK-21–23) stay. Keep step numbers stable; comment steps 4 and 10 "superseded by MK-24".
- Browser check: coins — campaign page has no Fill; open date links to Place; Review shows batches; keys 1–5 and D;
  pause a campaign and make + place on it; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: n/a (reuses existing UI)
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-21, MK-22, MK-23, MK-20
Open questions: (1) one-off drafts leave Review (their door is the calendar → studio) — OK? Default: yes. (2) the
Marketing tab count still counts only legacy dated drafts of campaigns that are on, so it decays to 0 while Review's
badge counts working ads — should the tab count switch to working ads? Default: no, tab count = dated work only.
