# Marketing: review inbox
Status: active   ·   Last updated: 2026-10-13   ·   Stories: MK-20

## Overview
`/p/{project}/marketing/review` is one page listing every date that waits for the owner's pick, across all of a
project's campaigns plus its one-offs, soonest first. Each date shows its rendered ads side by side. With MK-5 and
MK-19 drafts are finished ads, so picking is a visual choice and a week can be done in one sitting instead of
opening each campaign and each date's studio. The [post studio](marketing-post-studio.md) stays for detail work;
the inbox is the fast path. It adds no table, column or migration.

## How it works
**1. The queue** (`app/Actions/Marketing/ListReviewQueue.php:handle($project, $now, $filter)`).
- Campaign dates come from `BuildCampaignRunway::datesToApprove()` unchanged: the campaign is on, the date is upcoming,
  it has drafts, and it has no approved, posted or skipped post. This is the same rule as the campaigns list and the
  studio's queue, so the Review badge and the Marketing tab's count agree on campaign dates
  ([ADR-098](../decisions/ADR-098-marketing-review-inbox-reuses-the-to-approve-rule.md)).
- One-offs are added only here. Each upcoming one-off whose `BuildCampaignRunway::state()` is `review` becomes its own
  date with one ad. `CountPostsToApprove` and the studio queue are unchanged, so **the tab count leaves one-offs out
  and the Review badge counts them**.
- Dates MK-8 is drafting, or failed to draft, are listed too, with no posts. They come from `DraftingStatus::states()`
  for each campaign's next 8 open dates (`ListCampaignOccurrences::COUNT`). These rows show in the queue but are not
  in `total` or `waiting`.
- Every post is read in one query (`runwayWindow($now)`, with each post's latest vote) and given to each campaign as
  its loaded `posts`. The query count stays fixed however many dates or campaigns wait. A campaign whose schedule
  cannot be read is skipped and logged.
- It returns `dates` (filtered rows), `waiting` (filtered rows with drafts), `total` (all rows with drafts: the
  badge) and `chips` (All, then each campaign by name, then One-off). `count()` is `total`.

**2. The page** (`app/Livewire/Marketing/Review.php`, `resources/views/livewire/marketing/review.blade.php`).
- Each row has the date, time and campaign chip, then the date's ads. Each ad is the 1:1 render
  (`resources/views/components/marketing/ad-square.blade.php`) with its caption and Keep, 👍/👎 and open-in-studio
  buttons below it. A campaign row also has **Reject all**.
- `keep(id)` calls MK-9's `ApprovePost`, which approves the post and discards the date's other drafts. Keep is final
  here: there is no Undo, and the studio's Unapprove remains. A draft still waiting for its photo has Keep disabled.
  A failed render can still be kept, because approval doesn't need the image.
- `vote(id, vote, reasons)` calls MK-9's `RecordFeedback`, using MK-8's four dislike reasons.
- `rejectAll(campaign, date, reason)` calls `RejectDateDrafts` (step 3). `retryDraft()` runs `FillCampaign` again
  for one date. `retryRender()` calls `QueuePostRender` with `respec: false`.
- Every id or date sent by the client is checked against the current queue (`queuedPost()` / `queuedDate()`) under
  the All filter. Another project's post, a post that is no longer waiting, an unknown campaign, a malformed date
  or a date not in the queue is logged as `marketing.review.refused` and returns a 404.
- The page polls every 2 s (`wire:poll.2s`) while any date is drafting or any ad is rendering. The studio sits on the
  page unchanged, and its `marketing-changed` event re-renders the queue.

**3. Reject all** (`app/Actions/Marketing/RejectDateDrafts.php:handle()`).
1. Refuse (`nothing_to_reject`) unless the date has drafts and nothing approved or posted.
2. Refuse before writing anything when there is no API key or the campaign is paused. Discarding three drafts and then
   failing to redraft would leave the date empty for a reason the owner can't fix from this page.
3. In one transaction: one dislike per draft through `RecordFeedback` (with the optional shared reason), then all
   drafts set to `discarded`.
4. **After** the transaction: `FillCampaign::handle($campaign->withoutRelations(), $now, $date)`, which marks the date
   drafting and queues its `DraftOccurrenceJob`, the same path as the campaign page's per-date Fill
   ([ADR-099](../decisions/ADR-099-marketing-reject-all-checks-first-and-fills-after-commit.md)).

The date then shows "Drafting…" until the new ads arrive, or MK-8's failure state with Retry.

**4. Keys and focus** (`resources/js/review-inbox.js`, Alpine `reviewInbox`). These are UI-only and make no server
call. ←/→ move a focus ring between rows. 1/2/3 click the focused row's Keep button for that slot. R opens the Reject
all confirm (`board/confirm-modal`) with an optional reason. Keys are ignored while typing, with a modifier held, or
while the confirm or the studio is open. Focus is a row index. After each Livewire commit it is repainted and
clamped, so after a Keep the next date moves into the ring.

**5. Sub-nav** (`resources/views/components/marketing/sub-nav.blade.php`). Review comes after Campaigns, with a count
badge that is hidden at 0. The Review page passes `reviewCount` itself. Every other marketing page has the sub-nav
call `ListReviewQueue::count()`.

## Data model
No migration. It reads `marketing_posts` (drafts, `ad` render state), `marketing_feedback` (votes), and MK-8's
drafting state from the cache. It writes only through `ApprovePost`, `RecordFeedback`, `FillCampaign` and
`QueuePostRender`, plus the discard in `RejectDateDrafts`.

## Interfaces
- Route `projects.marketing.review` (`GET /p/{project:name}/marketing/review`), behind `EnsureProjectIsShown`.
- `ListReviewQueue::handle(Project, CarbonInterface $now, string $filter = ALL): array{dates, waiting, total, chips}`.
  A row is `{key, campaign|null, date, at, posts, drafting: null|'drafting'|'failed'}`. `key` is `<slug>/<Y-m-d>` or
  `one-off/<post id>`. Filters: `ALL`, `ONE_OFF`, or a campaign id. `count(Project, $now): int`.
- `RejectDateDrafts::handle(MarketingCampaign, string $date, ?string $reason, CarbonInterface $now): list<int>`
  (the rejected ids). Throws `MarketingPostRefusedException` (nothing to reject, or an unknown reason) and
  `MarketingDraftRefusedException` (`not_configured`, `paused`, or Fill refusing).
- Livewire actions: `keep(id)`, `vote(id, vote, reasons)`, `rejectAll(campaign, date, reason)`, `retryDraft(campaign,
  date)`, `retryRender(id)`. Property `filter`: a value that isn't one of the chips falls back to All.
- `<x-marketing.ad-square :post :brand :project :label>`: the 1:1 ad card in every render state. Its Retry and
  Render-it buttons call the page's `retryRender(id)`.
- Test hooks: `data-review-row="<key>"`, `data-review-ad`, `data-keep`, `data-keep-slot`, `data-reject-all`,
  `data-reject-confirm`, `data-review-filter`, `data-review-empty`, `data-review-waiting`, `data-review-count`.

## Configuration
None of its own. Reject all and Retry need MK-8's `ANTHROPIC_API_KEY`, a queue worker and the shared cache. Renders
need MK-5's worker setup.

## Observability
- `marketing.review.viewed` (debug: `dates`, `drafting`, `filter`). Logged on a full page load only, not on Livewire
  updates.
- `marketing.review.kept` (info: `post_id`, `campaign_id`, `date`), after `ApprovePost`'s own lines.
- `marketing.review.rejected_all` (info: `campaign_id`, `date`, `post_ids`, `reason`), then MK-8's
  `marketing.ai.fill.queued`.
- `marketing.review.refused`: warning, with `reason` one of `not_in_queue`, `unknown_campaign`, `bad_date`,
  `nothing_to_reject` or `bad_filter`; or info from `RejectDateDrafts` with `not_configured` or `paused`.
  `RejectDateDrafts`' own nothing-to-reject refusal logs `marketing.post.refused`.
- `marketing.runway.failed` with `source: review` means a campaign's schedule couldn't be read. The sub-nav logs this
  on every marketing page render, beside the list's own line (which has no `source`).
- A healthy Reject all trace: `rejected_all` → `fill.queued` → MK-8's `draft.started` / `draft.succeeded` → three
  `marketing.render.queued`.

## Testing & verification
- `tests/Feature/Marketing/ReviewTest.php`: one `it()` per acceptance criterion (except the two browser ones),
  plus: refusals as logged 404s, a missing key keeping the drafts, an unknown reason, the Action refusing directly,
  a bad filter, one-offs, paused/past/skipped dates left out, render states (Rendering…, fallback + Retry, still
  keepable), a draft waiting for its photo, votes, a fixed-query-count check, the sub-nav on other pages, and an
  unreadable schedule. Claude is faked. The fail-then-retry case uses `Http::sequence` (see RUNBOOK).
- `tests/Browser/Marketing/ReviewTest.php`: key 3 keeps ad 3, and at 375 px rows swipe with no sideways page scroll.
- `tests/Browser/Journeys/MarketingPostTest.php` step 10: open Review, keep a TrueView Tuesday ad. The date leaves the
  queue and shows as approved on the calendar.
- Real-key browser check by the owner, **not yet done**: Fill a coins week, keep with 1/2/3, reject one date and
  watch it redraft, then try 375 px.

## Key decisions & tradeoffs
- One "undecided date" rule, reused rather than restated. One-offs are inbox-only, so the tab count and the badge can
  differ → [ADR-098](../decisions/ADR-098-marketing-review-inbox-reuses-the-to-approve-rule.md).
- Reject all checks key and pause first, writes votes and discards in one transaction, then runs Fill after commit →
  [ADR-099](../decisions/ADR-099-marketing-reject-all-checks-first-and-fills-after-commit.md).
- Mockup A (Grid) was the owner's pick. The owner left the detail questions open, so these defaults apply: Keep on a
  failed render is allowed, there is no Undo toast, and there are chips for campaigns that have rows.
- The Reject all confirm reuses `board/confirm-modal`, so focus lands on Cancel (the mockup focused the confirm
  button). Campaign colour dots were dropped because campaigns have no colour field. `marketing/ad-square` is new
  and used only here: the studio had no shared partial to reuse, and the studio was not to be touched.

## Known limitations & gotchas
- **Reject all can strand a date.** If Fill refuses for a reason not checked in advance (for example an off-schedule
  date), the drafts are already discarded and the date is left open. Fill it from the campaign page.
- **Tab count ≠ Review badge** whenever one-offs wait (e.g. Marketing 3, Review 4). This is by design (ADR-098).
- Drafting and failed rows cover any open date in a campaign's next 8. A date filled from the campaign page also
  shows "Drafting…" here.
- A campaign whose only rows are drafting still gets a chip, with count 0.
- One-offs have no Reject all: there is no campaign brief to redraft from.
- Every marketing page's sub-nav builds the whole queue for its badge (fixed queries plus a cache read per campaign).
- Pass `withoutRelations()` when handing a queue campaign to `FillCampaign`; see RUNBOOK 2026-10-13.

## Change history
2026-10-13 — Review inbox: queue, Keep, votes, Reject all with redraft, keys, campaign chips, and the Review sub-nav
badge (MK-20, `667592d`)
