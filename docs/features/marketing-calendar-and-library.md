# Marketing: calendar and library
Status: active   ·   Last updated: 2026-10-12   ·   Stories: MK-10

## Overview
The campaign pages answer "how is this campaign doing". These two pages answer "what about everything". The
**calendar** (`/p/{project}/marketing/calendar`) is a month or week grid of every campaign's posts, with the open
dates still to fill and a banner over the first stretch with nothing approved. The **library**
(`/p/{project}/marketing/library`) is a calm archive of the posts that are approved or already went out, in two
sections (Coming up and Posted), filterable by campaign and searchable by caption. Both open the same post studio (MK-9), and they are the first door back into the
studio for a one-off post.

## How it works
**Routes.** Both live in `routes/web.php` under `EnsureProjectIsShown`, named `projects.marketing.calendar` and
`projects.marketing.library`. The marketing sub-nav (`components/marketing/sub-nav.blade.php`) lists a page once its
route exists, so adding the routes is what put Calendar and Library in it.

**Calendar, the data.** `app/Livewire/Marketing/Calendar.php:render()` works out the visible range (month view:
Monday before the 1st to the Sunday after the last day; week view: Monday to Sunday) and asks
`app/Actions/Marketing/BuildMarketingCalendar.php:handle()` for one range that covers the grid, the 14-day agenda
and the gap rule's 28-day horizon, so the page is built once. `handle()`:
1. Loads the project's campaigns with their runway-window posts (what the runway and occurrences read).
2. For each campaign: the tray row (`BuildCampaignRunway::handle()`, approved-through), and, if the campaign is on,
   its ghosts: `ListCampaignOccurrences::handle()` rows in state `open`, from today on. No second schedule
   implementation ([ADR-084](../decisions/ADR-084-marketing-calendar-composes-the-runway-rules-in-fixed-queries.md)).
   A campaign whose timezone can't be read logs `marketing.runway.failed` (`source: calendar`) and shows its error in
   the tray; its posts still draw, it just has no ghosts.
3. One query for every non-discarded post in the range (a day of slack each side, because a local day can straddle
   UTC days), then `addPosts()` groups them: a campaign's posts by local day (`BuildCampaignRunway::day()` in the
   campaign's timezone) into one chip, and each one-off into its own chip on its day in the owner's timezone
   (`config('board.timezone')`).
4. `chip()` takes the state from `BuildCampaignRunway::state()` and promotes `approved` to `missed` with
   `MarketingPost::isMissed()`. Skipped dates (and `open` dates, which already have a ghost) make no chip.

`BuildMarketingCalendar::gap()` then walks from today for `HORIZON_DAYS` (28) days and returns the first run of
`GAP_DAYS` (4) or more days with no `approved` or `posted` chip. A missed post does not cover its day. A run that
reaches the horizon comes back `open: true` and the banner adds "and beyond"
([ADR-085](../decisions/ADR-085-marketing-calendar-gap-rule.md)).

**Calendar, the page.** `resources/views/livewire/marketing/calendar.blade.php` renders, side by side:
- the grid (`data-calendar-grid`, `md:` and up): month cells or seven tall week columns, gap days tinted;
- the agenda (`data-calendar-agenda`, below `md`): the next 14 days as a list, with the gap as a row;
- the tray (`data-calendar-tray`): each campaign with its schedule, approved-through, a `board/switch` that calls
  `toggleCampaign()` (which reuses `ToggleCampaign`), and Open campaign.

Both the grid and the agenda are always in the HTML; Tailwind's `md:` classes decide which one shows
([ADR-087](../decisions/ADR-087-marketing-calendar-and-library-ui-state-is-alpine-and-css.md)). Each chip is
`components/marketing/calendar-chip.blade.php`. Clicking one fills the page's Alpine `peek` with the chip's own data
(time, campaign, state, caption, campaign URL, and what to send the studio), so the quick-peek costs no request.
"Open in studio" dispatches `open-studio` with `{campaign, date}` for a campaign chip or `{post}` for a one-off.
‹ / › / Today and Week / Month are Livewire calls, since they change which posts are shown. `mode` and `anchor` are
`#[Locked]`, so they only move through `setView()`, `previous()`, `next()` and `today()`.

**Library.** `app/Livewire/Marketing/Library.php` holds `filter` (`all`, `one-off`, or a campaign id) and `search`, and
calls `app/Actions/Marketing/ListLibraryPosts.php:handle()`. That is one query:
- approved posts not yet posted and not yet due (`scheduled_for >= now`), plus anything posted (`posted` status, or
  a `posted_at` on a post that isn't discarded, the query form of `MarketingPost::isPosted()`);
- narrowed by campaign (`whereNull('campaign_id')` for One-off) and a caption `LIKE`, with `%` and `_` escaped so they
  match literally.

The results split into **Coming up**, grouped by Monday-first week ("This week", "Next week", "Week of 26 Oct"),
and **Posted**, newest first and grouped by month, where only the current month starts open (`<details>`). Weeks and
months are in the owner's timezone. Drafts, skipped and discarded posts never appear, and neither does a missed post
([ADR-086](../decisions/ADR-086-marketing-library-is-a-calm-archive.md)). An unknown filter value is logged
and falls back to All (`updatedFilter()`).

Cards are `marketing/post-preview` with a campaign chip or a dashed "One-off" chip. A card dispatches `open-studio`
with `{post: id}`; the studio opens a campaign post on its date and a one-off on its own. The grid / list toggle is
Alpine (`layout` on `<main>`, read by `group-data-[layout=list]` classes).

**Staying fresh.** Both pages mount `<livewire:marketing.post-studio wire:key="post-studio">` and have an empty
`#[On('marketing-changed')] refreshFromStudio()`, so a write in the studio re-renders the page underneath (as on the
MK-9 hosts; keep the explicit `wire:key`, see RUNBOOK).

## Data model
None of its own. Reads `marketing_campaigns` and `marketing_posts` (with `asset` and `campaign`). The tray switch
writes `marketing_campaigns.is_enabled` through `ToggleCampaign`.

## Interfaces
- `BuildMarketingCalendar::handle(Project, CarbonImmutable $from, CarbonImmutable $to, CarbonInterface $now)` →
  `{days: [Y-m-d => list<Chip>], campaigns: list<{campaign, runway|null, error|null}>}`. A chip is `{key, date, at,
  state (approved|review|posted|missed|open), campaign|null, post|null, drafts}`.
- `BuildMarketingCalendar::gap(array $days, CarbonImmutable $today)` → `{from, to, open}` or null. Constants
  `GAP_DAYS`, `HORIZON_DAYS`.
- `ListLibraryPosts::handle(Project, CarbonInterface $now, string $filter = ALL, string $search = '')` →
  `{coming, posted: list<{label, open, posts}>, counts: {coming, posted}}`. Constants `ALL`, `ONE_OFF`. The caller
  checks a campaign id belongs to the project.
- `Calendar`: `setView($view)`, `previous()`, `next()`, `today()`, `toggleCampaign($campaign)` (404 for another
  project's campaign). Constants `VIEWS`, `AGENDA_DAYS`.
- `Library`: public `filter`, `search`.
- Component `<x-marketing.calendar-chip :chip :project>`.
- Outgoing event `open-studio`; listens for `marketing-changed`.
- Test hooks: `data-calendar-grid`, `data-calendar-day`, `data-calendar-agenda`, `data-agenda-day`,
  `data-agenda-gap`, `data-calendar-range`, `data-calendar-next`, `data-gap-banner`, `data-gap-day`, `data-chip`,
  `data-chip-date`, `data-chip-state`, `data-peek`, `data-peek-campaign`, `data-peek-studio`, `data-calendar-tray`,
  `data-tray-campaign`, `data-tray-error`, `data-marketing-library`, `data-library-section`, `data-library-empty`,
  `data-campaign-chip`.

## Configuration
`config('board.timezone')` is the owner's timezone: "today", the calendar's days, one-off chips, and the library's
week and month labels. Campaign posts are placed by their campaign's own timezone.

## Observability
- `marketing.calendar.viewed` (debug): `project`, `view`, `from`, `to`, `chips`, `ghosts`, `gap`. Logged on the page
  load only, not on Livewire updates.
- `marketing.library.viewed` (debug): `project`, `coming_up`, `posted`. Page load only.
- `marketing.calendar.view_refused` (warning): `view`, a forged Week/Month value. The view stays as it was.
- `marketing.calendar.campaign_refused` (info): `campaign_id`, a tray switch for a campaign not in this project; 404.
- `marketing.library.filter_refused` (warning): `filter`, an unknown campaign filter; falls back to All.
- `marketing.runway.failed` (warning, `source: calendar`): a campaign's schedule can't be read. The tray shows why.
- The tray switch logs `marketing.campaign.toggled` through `ToggleCampaign`.
- "A post is missing from the calendar": check its status (skipped and discarded make no chip), whether the campaign
  is paused (no ghosts), and which local day it falls on in the campaign's timezone.

## Testing & verification
- `tests/Feature/Marketing/CalendarTest.php`: one `it()` per calendar acceptance criterion (named as in the story),
  plus one-offs, paused campaigns, the open-ended gap, stepping, the refusals, the tray, the agenda, an unreadable
  schedule, the debug log, the sub-nav, and a query count that stays fixed as campaigns are added.
- `tests/Feature/Marketing/LibraryTest.php`: one `it()` per library acceptance criterion, plus literal `%`/`_`
  search, the filter chips, the refused filter, a card opening the studio, the debug log, a fixed query count and
  the sub-nav.
- `tests/Browser/Marketing/CalendarTest.php` and `LibraryTest.php`: quick-peek and Open campaign, Open in studio,
  week toggle, search and filter, grid/list with no request, cards opening the studio (campaign and one-off), and
  375 px (agenda list, one-column library, no sideways scroll).
- `tests/Browser/Journeys/MarketingPostTest.php` step 6: from the sub-nav to the calendar, › to November, and the
  TrueView Tuesday post approved in step 5 is an `approved` chip on Tue 3 Nov.
- Feature tests read markup with `htmlQuery()` in `tests/Pest.php`, which uses PHP 8.4's `Dom\HTMLDocument`.
- Browser checks in a worktree need a fresh Vite build first, or the new Tailwind classes are missing (RUNBOOK,
  2026-10-12 "New Tailwind classes unstyled").

## Key decisions & tradeoffs
- The calendar composes `ListCampaignOccurrences`, `BuildCampaignRunway::state()/day()` and `isMissed()` in a fixed
  number of queries, rather than querying per campaign or re-implementing the schedule →
  [ADR-084](../decisions/ADR-084-marketing-calendar-composes-the-runway-rules-in-fixed-queries.md).
- Gap: from today, 28-day horizon, first run of 4+ days with nothing approved or posted; missed does not count →
  [ADR-085](../decisions/ADR-085-marketing-calendar-gap-rule.md).
- The library leaves out drafts and missed posts; a missed post is the calendar's and campaign page's to flag →
  [ADR-086](../decisions/ADR-086-marketing-library-is-a-calm-archive.md).
- Quick-peek and grid/list are Alpine; the 375 px agenda is rendered beside the grid and CSS picks →
  [ADR-087](../decisions/ADR-087-marketing-calendar-and-library-ui-state-is-alpine-and-css.md).
- Cards and one-off chips open the studio with `{post: id}`, which closes MK-9's gap of a one-off having no way back
  in. The studio itself is unchanged (Do-NOT-touch).
- Skipped dates show nothing on the calendar, and a paused campaign shows its posts but no ghosts. Both are on purpose.

## Known limitations & gotchas
- **No "♡ Save to Inspiration" on library cards yet.** The story lists it, but it is MK-18's action; it is left out
  rather than drawn as a dead button.
- **`htmlQuery()` needs PHP 8.4** (`Dom\HTMLDocument`), while `composer.json` allows `^8.3`. Local is 8.4 and CI is
  8.5, so it runs today; a PHP 8.3 machine would fail these tests.
- No drag to reschedule (rescheduling stays in the studio). No engagement on cards (phase 2).
- Ghosts are only drawn from today on; a past open date is not shown, and a past approved post that never went out
  is a `missed` chip.
- The gap rule only looks 28 days ahead, so a gap further out is not bannered.
- One-offs are placed by the owner's timezone, so changing `board.timezone` can move one to a different day.
- The agenda always lists the next 14 days from today, whatever month the grid is on.
- Library search is a plain caption `LIKE` with no index; fine at phase-1 volumes.

## Change history
2026-10-12 — Calendar (month/week grid, ghosts, gap banner, tray, quick-peek, 375 px agenda) and library (Coming up
by week, Posted by month, campaign filter, search, grid/list, cards open the studio) (MK-10, `80be063`)
