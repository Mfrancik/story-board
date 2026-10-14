# MK-20 — Review inbox: sift rendered ads across every campaign
Status: built          Journey: marketing-post (step 10 of 10)
Source: owner 2026-10-13 phase 3 batch 1 ("for me to sift through and pick and choose"); Reject all → redraft the
date (owner pick)

## Story
As the owner, I want one page that shows every date waiting for a pick, each with its three rendered ads, so that I
can work through a week of posts in one sitting.

## Why
Picking today means opening each campaign and each date's studio. With rendered ads (MK-5, MK-19) the choice is
visual and fast — it belongs in one queue.

## In scope
- `GET /p/{project}/marketing/review` — Livewire `Marketing\Review`; sub-nav gains **Review** with a count badge.
- The queue: every date (campaign or one-off) that has drafts and no approved post, soonest first; each shows its
  three rendered ads (1:1), caption under each, campaign chip, date.
- Per ad: **Keep** (approves it via MK-9's `ApprovePost` — the others for that date are discarded), 👍 / 👎 with
  MK-8's dislike reasons (via `RecordFeedback`), open in studio.
- Per date: **Reject all 3** → each gets a dislike (with an optional shared reason), then the date is redrafted
  (MK-8's Fill for that one date; owner pick) and shows "Drafting…" until the new three render.
- Keyboard: ←/→ move between dates, 1/2/3 keep that ad, R reject all; focus stays sensible after each action.
- Filters: campaign chips (All + each). Empty state: "Nothing to pick. Fill some dates on a campaign page."
- Still-rendering ads show "Rendering…"; a failed render shows MK-5's HTML fallback with Retry.

## Out of scope (do NOT build)
- Editing ads (MK-4), bulk approve without looking, scheduling changes (studio), Facebook publishing (phase 2).
- Retiring a page or feature? None retired — the studio stays for detail work; the inbox is the fast path.

## Acceptance criteria (executable — these become the Pest test names)
- Given two campaigns with drafts on three dates and one date already approved, when I open Review, then exactly the
  three undecided dates show, soonest first, each with its three ads, and the sub-nav badge reads 3.
- Given I Keep ad 2 of a date, then that post is approved, the other two are discarded, and the date leaves the queue.
- Given I dislike an ad with "too wordy", then a feedback row with that reason exists and taste examples include it.
- Given I Reject all 3 with reason "boring", then three dislikes are recorded, the three drafts are discarded, a draft
  job is queued for that date only, and the date shows "Drafting…".
- Given Claude fails on the redraft, then the date shows MK-8's failure state with Retry and stays in the queue.
- Given the campaign filter, then only that campaign's dates show and the count follows.
- Given no undecided dates, then the empty state shows and the badge is hidden.
- Given another project's post id in Keep, then it is refused (404) and logged.
- Given keyboard 3 on a focused date, then ad 3 is kept.
- Given a 375px viewport, then each date's ads stack or swipe with no sideways page scroll.

## Applicable standards
- Design: chosen mockup; reuse `marketing/sub-nav`, `marketing/post-preview` (fallback), board/confirm-modal only if
  Reject all needs a confirm; Alpine for keyboard and focus (no round-trip for UI state).
- Codebase/DB: reuse `ApprovePost`, `RecordFeedback`, MK-8's single-date Fill path — no second approve or draft path;
  one query for the queue with posts + renders eager-loaded (no N+1). No migration.
- Logging: `marketing.review.viewed` (debug, counts), `marketing.review.kept`, `marketing.review.rejected_all`
  (post ids, reason), `marketing.review.refused`.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-20/option-{a,b,c}.html — (a) grid: dates as rows of three; (b) one date at a time, big,
  with a filmstrip; (c) a swipe/stack variant for phone
- Chosen option: **A · Grid** — dates as rows of three ads, ←/→ move a focus ring between rows; on phone each row's
  ads swipe sideways inside the row (docs/mockups/MK-20/option-a.html).
- Why I chose it: owner pick 2026-10-13. Detail defaults (owner left unanswered → orchestrator defaults): Keep is
  allowed on a failed render (approval doesn't need the image); chips only for campaigns with dates waiting; no
  Undo toast (Keep is final from the inbox; the studio's Unapprove remains).

## Do NOT touch
- `config/`; `ApprovePost`, `RecordFeedback`, `FillCampaign` behaviour (call them, don't fork them); the studio.

## Data & interfaces
- Schema: none.
- Route `projects.marketing.review`; Livewire `Marketing\Review`; Action `ListReviewQueue`, `RejectDateDrafts`.

## Test plan
- Pest: failing tests first, Claude and renderer faked, frozen clock.
- Journey test: step 10 — open Review, keep one of the TrueView Tuesday ads, the date leaves the queue and appears on
  the calendar as approved.
- Browser check: coins — Fill a week, open Review, keep with keys 1/2/3, reject one date, watch it redraft; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-5, MK-19, MK-8, MK-9
