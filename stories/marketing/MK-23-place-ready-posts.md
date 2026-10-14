# MK-23 — Place ready posts on the campaign's next open dates
Status: draft          Journey: marketing-post (step 13 of 14)
Source: owner 2026-10-14 (verbatim idea above) — "then we can just place them … just space them out on the timeline of
the campaign"; placing = auto-fill next open schedule slots from a chosen start date, owner's order, preview then
confirm (owner decision 2)

## Story
As the owner, I want to place N of my Ready ads onto the campaign's next open dates from a start date I pick, in the
order I choose, so that the dated queue fills itself without me assigning each day.

## Why
Ready ads (MK-22) have no date. The campaign already knows its days and time (`CampaignSchedule`), so placing is
arithmetic plus the owner's say on order and start; the dated queue, calendar and runway then work unchanged.

## In scope
- In `Marketing\PostMaker`: **Place ready posts** opens the placement preview (per MK-21's chosen mockup):
  start date (default: today in the campaign's timezone), how many (1 … Ready count, default all), and the Ready ads
  in order (default: oldest Ready first) — reorderable by drag and by ↑/↓ buttons (Alpine; keyboard-accessible).
- **Preview**: each ad beside the date and time it would get. Slots are `CampaignSchedule::handle()` dates from the
  start (never a time already past), keeping only **open** dates — no kept post of any status on that local day, by
  `BuildCampaignRunway::day()`/`state()`. Taken dates are listed as "Skipped — already has a post (approved / posted /
  skipped / drafts)". Last date shown ("Through Tue 22 Dec").
- **Confirm** → new `PlaceReadyPosts`: in one transaction (campaign row locked), re-check every slot is still open and
  future and every post still Ready, then set each `scheduled_for`. Posts stay `approved`, so they appear in the
  campaign timeline, runway, calendar and library as approved posts. Then queue renders for any with no render.
- **Undo placement** in the confirmation toast → new `UnplacePosts` returns every post of that placement still
  `approved`, unposted and at its placed time to Ready (date null); moved or posted ones are left and reported.
- **Unplace** for one post: the studio's approved, unposted campaign post gains "Back to Ready" → `UnplacePosts` with
  one id (the studio then shows the date as open).
- Works whether the campaign is on or off. Moving a placed post later is the existing studio **Reschedule** (opened
  from a calendar chip or timeline row).

## Out of scope (do NOT build)
- Retiring Fill / "Fill N open dates" (MK-24); auto-placing without a preview; placing onto off-schedule dates or
  several posts per date; changing a campaign's days from the preview; a calendar drag-and-drop.
- Persisting a Ready order between visits (the order lives in the preview).
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given a Tuesday 19:00 campaign with 4 Ready ads and no posts, when I preview from Mon 2 Nov, then the slots are Nov
  3, 10, 17 and 24 at 19:00 local, in the shown order.
- Given Nov 10 holds an approved post and Nov 17 a skipped one, then the preview skips both, lists why, and the four
  ads land on Nov 3, 24, Dec 1 and Dec 8.
- Given I move ad 4 to the top and confirm, then ad 4 is on Nov 3, every placed post is `approved` with that
  `scheduled_for`, and `marketing.maker.placed` logs post ids, first/last date and skipped dates.
- Given placement confirmed, then the campaign page timeline shows those dates as approved, the calendar shows
  approved chips on them, and the maker's Ready count drops by 4.
- Given I place 2 of 5 Ready, then only the first two in the order get dates and three stay Ready.
- Given a start date in the past, then it is refused (`past_start`) with a field error and logged; given today with
  the time already gone, today is not a slot.
- Given another tab placed a post on Nov 3 after my preview, when I confirm, then nothing is written, it is refused
  (`slot_taken`) and the preview reloads with the new slots.
- Given a post in the order that is no longer Ready (dropped, back to working, another campaign's, another
  project's), when I confirm, then it is refused with 404 and `marketing.maker.place.refused` is logged.
- Given a campaign with no days, then Place is disabled with "Pick the campaign's days first" and a forced call is
  refused (`no_days`); an unreadable timezone shows the schedule error and places nothing.
- Given I press Undo placement, then the posts still at their placed time return to Ready with no date and
  `marketing.maker.unplaced` is logged; one I rescheduled meanwhile stays and the toast says so.
- Given an approved, unposted placed post in the studio, when I press Back to Ready, then its date is null, it is in
  the maker's Ready list, and the date reads open; a posted post has no Back to Ready and the Action refuses it.
- Given the campaign is off, then placing works and the posts show on the calendar.
- Given no Ready ads, then Place shows "Nothing ready yet" and is disabled.
- Given a 375px viewport, then the preview is a full-screen sheet, reorder works with ↑/↓, and nothing scrolls
  sideways.

## Applicable standards
- Design: MK-21's chosen mockup (placement preview); reuse `marketing/ad-square` (thumbnail size), the toast, and
  `board/confirm-modal` styling only if the mockup asks. Alpine for reorder, count and focus; Livewire only for
  preview (dates come from the server) and confirm.
- Codebase/DB: no migration. New Actions `PlanPlacement` (read-only: slots for an ordered id list from a start),
  `PlaceReadyPosts` (re-plans inside the transaction with `lockForUpdate` on the campaign, so two tabs can't book a
  date twice), `UnplacePosts`. Reuse `CampaignSchedule`, `BuildCampaignRunway::day()/state()`, `QueuePostRender` — no
  second schedule or state rule. One query for the campaign's dated kept posts over the planned range.
- Logging: `marketing.maker.place.previewed` (debug: count, from, to, skipped), `marketing.maker.placed` (info:
  campaign_id, post_ids, from, to, skipped_dates), `marketing.maker.place.refused` (warning/info: reason),
  `marketing.maker.unplaced` (info: post_ids, kept_ids, source `undo|studio`).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Gate cleared by MK-21's pick: docs/mockups/MK-21/option-<chosen>.html (the placement preview).
- Chosen option: as recorded in MK-21.
- Why I chose it: as recorded in MK-21.

## Do NOT touch
- `config/`; `CampaignSchedule` and `BuildCampaignRunway` rules (call them); `ReschedulePost` behaviour beyond MK-22's
  guard; Fill (MK-24); the calendar and library (they read placed posts unchanged). The studio only gains Back to Ready.

## Data & interfaces
- Schema: none — placing writes `marketing_posts.scheduled_for`; unplacing sets it back to null.
- `PlanPlacement::handle(MarketingCampaign, list<int> $ids, CarbonImmutable $start, CarbonInterface $now):
  array{slots: list<{post_id, at}>, skipped: list<{date, state}>}`.
- `PlaceReadyPosts::handle(MarketingCampaign, list<int> $ids, CarbonImmutable $start, CarbonInterface $now):
  list<{post_id, at}>`; `UnplacePosts::handle(MarketingCampaign, list<{post_id, at}> $placed): array{unplaced, kept}`.
  Refusals throw `MarketingPostRefusedException` (`past_start`, `slot_taken`, `not_ready`, `no_days`, `nothing_ready`).
- `PostMaker`: `previewPlacement(string $start, int $count, array $order)`, `confirmPlacement()`, `undoPlacement()`
  (the placed list is `#[Locked]`). `PostStudio`: `backToReady(id)`.
- Test hooks: `data-place-open`, `data-place-start`, `data-place-count`, `data-place-slot="<id>"`,
  `data-place-skipped`, `data-place-confirm`, `data-place-undo`, `data-back-to-ready`.

## Test plan
- Pest: failing tests first, frozen clock, a campaign in a non-UTC timezone to prove local days.
- Journey test: new step 13 — place Ask the community's 2 Ready ads from step 12 starting Sun 25 Oct; they show as
  approved on the calendar on Oct 25 and Nov 1.
- Browser check: coins — place 6 Ready on TrueView Tuesday from next Monday with one date already approved; reorder,
  confirm, check the calendar, Undo, then place again; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above (MK-21's)
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-22, MK-7, MK-9, MK-10
Open question: the owner said "skip dates already holding an approved/posted post". This story also skips **skipped**
dates (Skip means "don't post that day") and dates holding **old Fill drafts** (placing there would hide or need to
discard them). Default: skip both; say if a placed post should replace leftover drafts instead.
