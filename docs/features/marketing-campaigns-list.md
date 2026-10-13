# Marketing: campaigns list
Status: active   ·   Last updated: 2026-10-12   ·   Stories: MK-1, MK-7

## Overview
`/p/{project}/marketing` is a project's Marketing tab, opening on a list of its recurring campaigns ("TrueView
Tuesday · Tue 19:00 weekly"). For each campaign it shows how far out posts are approved and what needs the owner.
The owner thinks in campaigns, so this is the first page of the marketing initiative (`stories/marketing/README.md`).
MK-1 also lays the generic schema (`marketing_campaigns`, `marketing_posts`) that MK-2, 7, 8, 9, 10 and 18 build on.
The page only reads. A row's name opens the campaign's page and "+ New campaign" opens it in create mode, where
campaigns are created, edited and switched ([campaign page](marketing-campaign-page.md), MK-7). The badges, "Start approving" and "+ One-off post" open the
[post studio](marketing-post-studio.md) (MK-9).

## How it works
**Route.** `routes/web.php` registers `GET /p/{project:name}/marketing` as `projects.marketing` behind
`EnsureProjectIsShown`, inside the page group. An unknown or disabled project is refused before mount (ADR-013).
Later Marketing pages must be named `projects.marketing.calendar|library|inspiration|settings` (see Sub-nav).

**Component.** `app/Livewire/Marketing/CampaignList.php` uses `GuardsProjectPage` (locked `$project`,
`hydrate()` re-check). `render()` loads the project's campaigns ordered by name, eager-loading posts through
`MarketingPost::scopeRunwayWindow($now)`, so the whole list costs one posts query. It builds one row per campaign:
`['campaign', 'runway' => BuildCampaignRunway::handle() | null, 'error']`. Rows split into **on** (`is_enabled`)
and **paused**. Only rows that are on and have a readable runway feed the banner. Nothing is held in public
properties.

**Dates: `app/Actions/Marketing/CampaignSchedule.php`.** `handle($campaign, $from, $count)` walks forward day by
day in the campaign's timezone and returns the next `$count` occurrences at or after `$from`, built at the
campaign's wall-clock `time`, so 19:00 stays 19:00 across DST. `zone()` is public because the runway needs it too.
A bad timezone, a day outside 1–7 or an unreadable time throws `App\Exceptions\CampaignScheduleException`.

**Runway: `app/Actions/Marketing/BuildCampaignRunway.php:handle()`.** For one campaign as of `$now`:
1. Takes the next `RUN_HORIZON` (26) occurrences.
2. Groups the campaign's dated, non-discarded posts by their **local date in the campaign's timezone**. A post
   belongs to a date by day, not exact time ([ADR-062](../decisions/ADR-062-marketing-posts-match-dates-by-local-day.md)).
3. Gives each date one state, `posted > approved > skipped > review (drafts only) > open`. Discarded posts are
   ignored ([ADR-063](../decisions/ADR-063-marketing-runway-rules.md)).
4. Returns:
   - `next`: the first date and its state.
   - `runway`: the first `RUNWAY_SIZE` (6) dates.
   - `approved`: how many of those 6 are approved or posted.
   - `approved_through`: the last date of the unbroken run from the next date. Approved, posted **or skipped**
     continue the run; anything else ends it, so an approved date after a gap does not extend it.
   - `to_approve`: upcoming dates whose posts are all drafts. One date counts once, however many candidates it has.
   - `needs_photo`: a `per_post` campaign with a draft awaiting approval that has no `asset_id`.
   - `missed`: the latest approved post that is past `scheduled_for` and not posted. It is derived on read, never
     stored, and has no age limit.
   - `open_dates`: dates among the 6 with no posts.
   - `thin`: `approved_through` is null or falls on or before Sunday of the current local ISO week.

**Page** (`resources/views/livewire/marketing/campaign-list.blade.php`):
- `board/project-header` with `current="marketing"`, then `marketing/sub-nav`.
- The **alerts banner** joins the parts that apply with " · ": "N posts need your approval", "<names> has/have
  nothing approved past this week" and "<name> missed Sun 11 Oct". A campaign that missed a post is named for the
  miss only, not also for running thin. When nothing applies, a green "All caught up" status shows instead.
- **Header**: "Campaigns" with its counts and a "+ New campaign" button (`data-new-campaign`) to
  `projects.marketing.campaign.create`.
- **Rows** (`partials/campaign-row.blade.php`): the name, a link (`data-campaign-link`) to
  `projects.marketing.campaign`, and `MarketingCampaign::scheduleLabel()`; the next post and
  its state; "Approved through Tue 20 Oct" (or "Nothing approved yet") over `marketing/runway-bar`; and
  `marketing/alert-badge` pills: Missed <day>, Needs a photo, N to approve, N open dates, "Nothing approved past
  this week" (only when something is approved). A row with no alerts reads "Nothing". Below `lg` (1024 px) a row
  stacks its fields with small labels; from `lg` it uses the prototype's 12-column grid.
- **Paused** campaigns sit in a dashed, faded group and read "Paused" in place of their badges.
- A row whose schedule cannot be read shows the exception's reason across the row, and the rest of the page renders.
- **Empty:** "No campaigns yet" (`data-campaigns-empty`) with its own "+ New campaign" button.

**Marketing tab count.** `board/project-tabs` adds Marketing last, after Overview / Stories / Preflight /
Handbook / Map. When something needs approval it shows a count dot (`data-marketing-count`) plus sr-only text. The
count comes from `app/Actions/Marketing/CountPostsToApprove.php`, which sums `BuildCampaignRunway`'s `to_approve`
over the enabled campaigns, so the tab, the banner and the row badges use one rule. Every project page computes it
inside the tabs component. The Marketing page already has the total, so it passes `marketing-count` through
`board/project-header` and the count is not computed or logged twice
([ADR-064](../decisions/ADR-064-marketing-tab-count-reuses-the-runway.md)).

**Sub-nav.** `resources/views/components/marketing/sub-nav.blade.php` lists Campaigns · Calendar · Library ·
Inspiration · Settings, but only those whose route exists (`Route::has`). Today that is Campaigns and Settings (MK-2); the campaign page marks Campaigns current. A page
appears by itself once its story registers the `projects.marketing.<key>` route, so there are no dead links.

## Data model
Two migrations, `database/migrations/2026_10_12_000001_create_marketing_campaigns_table.php` and
`…_000002_create_marketing_posts_table.php`. Both are generic, with nothing coin-specific.
- `marketing_campaigns` (`app/Models/MarketingCampaign.php`): `project_id` (FK, cascade), `name`, `slug`
  (unique per project), `days` (JSON ISO weekdays), `time` (wall clock), `timezone`, `is_enabled`, `topics` (JSON,
  defaults to `{"ideas":[],"own":[]}`), `brief`, `photo_mode` (`per_post | favourites | text_only | ai_decides`,
  as `PHOTO_*` constants).
- `marketing_posts` (`app/Models/MarketingPost.php`): `project_id` (FK, cascade), `campaign_id` (FK, null on
  delete, so a deleted campaign's posts survive as one-offs), `scheduled_for`, `status`
  (`draft | approved | posted | skipped | discarded`; `STATUSES` deliberately has no "missed"), `caption`,
  `asset_id` (no FK until MK-2 adds the assets table), `source` (`ai | manual | kit`), `generation_meta`,
  `posted_at`. Indexes: `(campaign_id, scheduled_for)` and `(project_id, status)`.
- `MarketingPost::isPosted()` is true for status `posted` **or** a non-null `posted_at`.
- `scopeRunwayWindow($now)` selects dated, non-discarded posts from one day before `$now` (slack for timezones
  ahead of UTC), plus every approved, unposted post however old (a possible miss).
- `Project` gains `marketingCampaigns()` and `marketingPosts()`. Factories: `MarketingCampaignFactory`
  (`named()`, `on(days, time)`, `paused()`) and `MarketingPostFactory` (`forCampaign($c, $at)`, `approved()`, `posted()`, `skipped()`, `discarded()`).

## Interfaces
- Route: `GET /p/{project:name}/marketing` → `projects.marketing`. Refused (404) for an unknown or disabled project.
- `CampaignSchedule::handle(MarketingCampaign, CarbonInterface $from, int $count): list<CarbonImmutable>` and
  `zone(MarketingCampaign): DateTimeZone`, both throwing `CampaignScheduleException`.
- `BuildCampaignRunway::handle(MarketingCampaign, CarbonInterface $now): array{next, approved_through, runway,
  approved, to_approve, needs_photo, missed, open_dates, thin}`, throwing `CampaignScheduleException`. Reads
  `$campaign->posts` if it is loaded; otherwise it lazy-loads the same window.
- `CountPostsToApprove::handle(string $project, CarbonInterface $now): int`. It never throws; a failing campaign
  is logged and left out.
- Components:
  - `marketing/sub-nav` (`project`, `current`).
  - `marketing/runway-bar` (`runway`, `approved`, `legend`); the key and the bar share one colour map.
  - `marketing/alert-badge` (`tone`: missed, photo, approve, open, thin, quiet). It is a label, not a control.
  - `board/project-header` and `board/project-tabs` gain an optional `marketingCount`.
- Test hooks: `data-campaign-row="<slug>"`, `data-approved-through`, `data-runway-state`, `data-runway-key`,
  `data-alert="<tone>"`, `data-marketing-alerts`, `data-paused-campaigns`, `data-campaigns-empty`,
  `data-runway-error`, `data-marketing-subnav`, `data-marketing-page`, `data-marketing-count`, `data-new-campaign`,
  `data-campaign-link`.

## Configuration
None. Each campaign carries its own `timezone`, and `app.timezone` plays no part in the runway.

## Observability
- `marketing.campaigns.viewed` (debug): `project`, `campaigns`, `enabled`, `paused`, `to_approve`, `failed`. It is
  logged on page loads only, not on Livewire requests.
- `marketing.runway.failed` (warning): `project`, `campaign_id`, `reason` (the exception message, e.g. `Unknown
  timezone "Mars/Olympus".`). From the tab count it carries `source: tab_count`, and that path runs on **every**
  project page render, so one broken campaign repeats this line across the whole project (see Known limitations).
- `board.project_page_refused` (info): from `EnsureProjectIsShown` at load, or from `hydrate()` with `request: update`.
- Healthy: one `marketing.campaigns.viewed` per visit with `failed: 0`. If a row shows the red "can't be worked
  out" text, look for `marketing.runway.failed` with that `campaign_id` and fix the stored timezone, days or time.

## Testing & verification
- `tests/Feature/Marketing/CampaignListTest.php`, with the clock frozen at Mon 12 Oct 2026 11:00. It has one `it()`
  per acceptance criterion, plus cases for: the bad-timezone error row and its log; the viewed log; the tab count on
  other project pages; a sub-nav with no dead links; open-date and photo badges; a mid-visit refusal;
  `CampaignSchedule` skipping a time already passed today; Los Angeles Tue 19:00 matching a Wed-UTC post; and
  malformed days or time.
- `tests/Browser/Marketing/CampaignListTest.php`: at 1280 px the banner, column rows, runway and tab count; at
  375 px the rows stack and there is no sideways scroll.
- `tests/Browser/Journeys/MarketingPostTest.php`: step 1 of the marketing-post journey, which opens
  `/p/coins/marketing` and sees the approved-through line.
- `ProjectOverviewTest` and `ReskinKeptPagesTest` only gained `marketing` in their pinned tab lists.
- Browser check: seed campaigns with the factories, open `/p/coins/marketing`, check the banner, rows, runway and
  Paused, then resize to 375 px. Run `npx vite build --emptyOutDir=false` first if new classes look unstyled
  (RUNBOOK 2026-10-12).

## Key decisions & tradeoffs
- Posts match occurrences by local date, not exact time →
  [ADR-062](../decisions/ADR-062-marketing-posts-match-dates-by-local-day.md).
- Approved-through counts skipped dates as covered (from the prototype), with a 26-date horizon, display
  precedence posted > approved > skipped > drafts, one "to approve" per date, and a definition of "thin" →
  [ADR-063](../decisions/ADR-063-marketing-runway-rules.md).
- The tab, banner and badges share one count, and the Marketing page hands its count down →
  [ADR-064](../decisions/ADR-064-marketing-tab-count-reuses-the-runway.md).
- Missed is derived, never stored, so it cannot disagree with the clock (story rule).
- Deliberate deviations from the prototype:
  - No per-row on/off switch: the switch lives in the campaign page's header (MK-7).
  - The runway header reads "Runway · next 6 dates", not "6 weeks", because a campaign can post on several days a week.

## Known limitations & gotchas
- **The tab count runs on every project page.** `CountPostsToApprove` runs one campaigns query and one posts query
  per render of any project page with tabs, Livewire re-renders included. A campaign with a bad timezone therefore
  logs a `marketing.runway.failed` warning on each of those renders.
- "Needs a photo" is the builder's reading of the prototype. Revisit it when MK-2 adds assets and MK-9 adds the photo step.
- `to_approve` only counts drafts scheduled at or after now. A drafts-only date earlier today whose time has
  passed drops out of the count but still shows as "drafts to approve" on the runway.
- Skipped dates continuing approved-through is taken from the prototype, not the story text, and is not pinned by
  a test. Only approved and open dates are tested.
- `EnsureProjectIsShown`'s docblock list of guarded pages does not yet mention Marketing.
- Paused rows still show their approved-through line and runway bar, faded.
- The badges are buttons wrapped around `marketing/alert-badge` (which stays a label): "N to approve" and "Needs a
  photo" open the campaign's first date to approve, "Missed" its missed date, "N open dates" its first open date.

## Change history
2026-10-12 — Marketing tab: campaigns list with approval runway and alerts; marketing schema (MK-1, `1f54714`)
2026-10-12 — Row names link to the campaign page; "+ New campaign" in the header and empty state (MK-7, `b5deb57`)
2026-10-12 — Badges, "Start approving" and "+ One-off post" open the post studio (MK-9, `9d71022`)
