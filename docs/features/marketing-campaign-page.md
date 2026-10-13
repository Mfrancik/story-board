# Marketing: campaign page
Status: active   ·   Last updated: 2026-10-12   ·   Stories: MK-7, MK-8

## Overview
`/p/{project}/marketing/campaigns/{slug}` is one campaign's page. It shows what the campaign will post and how far
out it is approved, lists what it has already posted, and lets the owner change its rules in place: name, days,
time, timezone, topics, brief and photo choice. The owner works from this page. It is also where MK-8's Fill and
MK-9's studio will start. `/campaigns/new` is the same page in create mode, so create and edit share one form.

## How it works
**Routes** (`routes/web.php`). There are two routes, both behind `EnsureProjectIsShown`:
`GET /p/{project:name}/marketing/campaigns/new` → `projects.marketing.campaign.create`, then
`GET /p/{project:name}/marketing/campaigns/{campaign}` → `projects.marketing.campaign`. `new` is registered first,
so it is never read as a slug, and `SaveCampaign` never gives a campaign the slug `new`. `{campaign}` is a plain
string, not `{campaign:slug}` binding. Slugs are unique only per project, and implicit binding is not scoped to the
project ([ADR-069](../decisions/ADR-069-marketing-campaign-url-is-a-project-scoped-slug.md)).

**Component: `app/Livewire/Marketing/CampaignPage.php`** (`GuardsProjectPage`; the campaign id is `#[Locked]`).
- `mount()` looks the slug up through `$project->marketingCampaigns()`. A slug this project does not have logs
  `marketing.campaign.not_found` and returns 404, and that includes another project's slug. In create mode the form
  starts blank with `timezone = config('board.timezone')`.
- `render()` loads the campaign's posts **once**, through `MarketingPost::scopeRunwayWindow($now)` with each post's
  `asset`. That one load feeds both:
  - the **status line**: `BuildCampaignRunway::handle()`. It gives "Approved through Tue 20 Oct · N dates need
    approval · N open", plus "missed <date>" and the runway bar. These are the same 6-date numbers as the campaigns
    list, so the two pages agree.
  - the **Future posts timeline**: `ListCampaignOccurrences::handle()`.

  **Past posts** is a second query: this campaign's posts with status `posted` or a `posted_at`, newest first.
- If the schedule cannot be read (`CampaignScheduleException`), the page logs `marketing.runway.failed` with
  `source: campaign_page` and shows the reason in place of the timeline. The criteria still render, so the owner can
  fix the problem.

**Timeline: `app/Actions/Marketing/ListCampaignOccurrences.php:handle()`.** It reuses MK-1's rules and does not
copy them ([ADR-068](../decisions/ADR-068-marketing-campaign-timeline-reuses-the-runway-and-moves-no-posts.md)):
1. `CampaignSchedule` supplies the next `COUNT` (8) dates.
2. `BuildCampaignRunway::day()` groups posts by local day, and `::state()` gives each date its state. These two
   methods were made public for this; their rules did not change.
3. An approved, unposted post whose time has passed turns its date's state into `missed`, which is the runway's own
   definition of a miss. A row is `flagged` (amber edge) when its state is `review`, `open` or `missed`.
4. Dates that still hold posts but are no longer on the schedule become extra rows with `on_schedule: false`, tagged
   "Off schedule". They show only while they matter: still upcoming and no later than the 8th date, or missed.
   Moving a campaign from Tuesday to Wednesday therefore gives 9 rows: the approved Tue 20 plus 8 Wednesdays.

Each row is drawn by `resources/views/components/marketing/occurrence-row.blade.php`. It shows the date and time, a
state pill, the approved post's preview and caption or the drafts' count and thumbnails, and the Off schedule tag.
An open row has Fill / Drafting… / Retry, and the header has "Fill N open dates" (MK-8, see
[marketing-ai-fill-and-revise.md](marketing-ai-fill-and-revise.md)). Rows open nothing yet: the studio arrives with MK-9.

**Criteria form** (`resources/views/livewire/marketing/campaign-page.blade.php`). The inputs are deferred
`wire:model`s, so typing makes no request. "Unsaved changes" is Alpine: a `dirty` getter compares the normalised
inputs with `$wire.stored`, which is a locked snapshot built by `CampaignPage::snapshot()` with the same
normalisation. Only add or remove an own topic, Save, Discard and the on/off switch make a request
([ADR-070](../decisions/ADR-070-marketing-unsaved-changes-is-alpine-against-a-locked-snapshot.md)).
- **Days**: weekday pills. **Time**: HH:MM. **Timezone**: a select of `DateTimeZone::listIdentifiers()`.
- **Topics**: ideas ticked from the brand's idea menu (MK-2, ADR-065), grouped by category and stored as
  `"Category|Title"`. A ticked idea that is no longer on the menu (renamed or removed) shows under "No longer on the
  menu" so it can be unticked. **Own topics**: "Add a topic" appends on Enter. Each topic is editable inline, can
  have a detail, and can be removed.
- **Brief** (≤ 240 chars) and **photo choice**: one of `MarketingCampaign::PHOTO_MODES`. Pick from favourites shows
  the project's favourite count.
- **Save** validates, then calls `SaveCampaign`. In create mode it redirects to the new campaign's page. **Discard**
  reloads the stored criteria from the database (blank ones in create mode).

**Header.** It shows the name, `scheduleLabel()`, the `board/switch` on/off and the campaign in one sentence. The
switch calls `toggle()` → `ToggleCampaign` and leaves any unsaved criteria in the form. In create mode the switch
is hidden, because a new campaign starts on. The sentence comes from `MarketingCampaign::describe()`: a category
with three or more ticked ideas is named by its category, one own topic is quoted, and more own topics are counted.
A missing day or topic reads as a gap ("Every … (pick a day)"). On the create page the sentence updates only after
a round trip.

**Saving: `app/Actions/Marketing/SaveCampaign.php:handle()`.** It normalises the input: days become sorted unique
ints, time becomes HH:MM:SS, blank brief and detail become null, and blank own topics are dropped while the owner's
order is kept. Then it:
- **creates**: `is_enabled` true, and a slug from `freeSlug()`. The slug is fixed at creation and kept through
  renames. A collision becomes `-2`, `-3` and so on, and `new` is reserved, so a campaign named "New" gets `new-2`.
- **updates**: `days` and `topics` are compared ignoring key order before `fill()` (see Known limitations), so
  `changed` lists only the columns that really changed.

Saving never touches posts. Approved posts stay on their dates, and open dates follow the new schedule.

## Data model
No migration. It reads and writes MK-1's `marketing_campaigns` (`name`, `slug`, `days`, `time`, `timezone`,
`is_enabled`, `topics` `{"ideas": ["Category|Title"], "own": [{"text", "detail"}]}`, `brief`, `photo_mode`). It reads
`marketing_posts` and their `asset`, MK-2's brand `idea_menu`, and the favourite photo count. A campaign's name is
unique per project only through validation, which is case-insensitive through the database collation. There is no
unique index on `name`; `(project_id, slug)` is unique.

## Interfaces
- Routes: `projects.marketing.campaign.create` (`/campaigns/new`) and `projects.marketing.campaign`
  (`/campaigns/{slug}`). Both return 404 for an unknown or disabled project or an unknown slug.
- `ListCampaignOccurrences::handle(MarketingCampaign, CarbonInterface $now, int $count = 8): list<array{at, date,
  state, posts, on_schedule, flagged}>` throws `CampaignScheduleException`. It reads `$campaign->posts` if they are
  loaded.
- `SaveCampaign::handle(Project, ?MarketingCampaign, array $data): MarketingCampaign` (null creates). Its input is
  already validated. `RESERVED_SLUGS = ['new']`.
- `ToggleCampaign::handle(Project, MarketingCampaign, bool $on): MarketingCampaign`.
- `BuildCampaignRunway::state(?Collection $posts): string` and `::day(MarketingPost, DateTimeZone): string` are now
  public.
- `MarketingCampaign::sentence()`, `::describe(days, time, topics, photoMode)`, `PHOTO_MODES`.
- Validation (`CampaignPage::rules()`):
  - name: required, max 60, unique in the project.
  - days: at least one, each 1–7.
  - time: `H:i`.
  - timezone: a real timezone.
  - brief: max 240.
  - photo mode: one of four.
  - own topics: max 50; text required, max 120; detail max 1000.
  - An empty "Add a topic" gives "Type a topic first."
- Component `marketing/occurrence-row`. `board/switch` and `marketing/runway-bar` are reused.
- Test hooks: `data-campaign-page`, `data-campaign-header`, `data-campaign-on`, `data-sentence`, `data-status-line`,
  `data-occurrence="<Y-m-d>"`, `data-occurrence-state`, `data-off-schedule`, `data-future-posts`,
  `data-timeline-error`, `data-past-posts`, `data-past-post`, `data-criteria`, `data-days`, `data-day`,
  `data-idea-menu`, `data-idea-category`, `data-off-menu`, `data-new-topic`, `data-add-topic`, `data-own-topics`,
  `data-own-topic`, `data-own-detail`, `data-remove-topic`, `data-photo-mode`, `data-savebar`, `data-unsaved`,
  `data-saved`, `data-save`, `data-discard`. On the list: `data-new-campaign`, `data-campaign-link`.

## Configuration
`board.timezone` is the default timezone of a new campaign. There are no flags.

## Observability
- `marketing.campaign.created` (info): `project`, `campaign_id`, `slug`.
- `marketing.campaign.updated` (info): `project`, `campaign_id`, `changed` (the column names). It is logged on every
  Save, so `changed: []` means the owner saved without changing anything.
- `marketing.campaign.toggled` (info): `project`, `campaign_id`, `is_enabled`. It is kept apart from `updated`.
- `marketing.campaign.not_found` (info): `project`, `slug`. Look for it when a campaign link returns 404.
- `marketing.runway.failed` (warning) with `source: campaign_page`: `campaign_id` and `reason`. The page shows the
  same reason in place of the timeline.
- `board.project_page_refused` (info): from the route middleware or from `hydrate()`.
- Healthy: one `updated` per Save, listing only the fields the owner touched. If `changed` contains `topics` when the
  topics were not edited, the key-order comparison has regressed (RUNBOOK 2026-10-12).

## Testing & verification
- `tests/Feature/Marketing/CampaignPageTest.php` (Livewire, clock frozen). It has one `it()` per acceptance
  criterion, plus cases for: a disabled project; toggle keeps posts and logs; `updated` lists only the changed keys
  and a rename keeps the slug; ideas stored as `Category|Title`; removing a topic and refusing an empty one; a bad
  time, timezone, weekday or photo choice; free slugs (never `new`); missed, skipped and drafts in the timeline; the
  schedule-error path; off-menu picks; and the list's New campaign button and row links. Topics are asserted with
  `toEqual`, because MySQL's JSON key order is not the order the values were written in.
- `tests/Browser/Marketing/CampaignPageTest.php`: Unsaved changes and Discard; creating "TrueView Tuesday" with two
  own topics, moving its day and watching the timeline move; and 375 px with no sideways scroll.
- `tests/Browser/Journeys/MarketingPostTest.php` step 3: open TrueView Tuesday from the list, see "Approved through
  Tue 20 Oct" and 8 dates, add "Album toning" and save.
- Browser check: run the same flow by hand at 1280 px and 375 px.

## Key decisions & tradeoffs
- The timeline reuses the runway's `day()` and `state()` (visibility change only), and a reschedule moves no posts:
  off-schedule dates stay as their own rows → [ADR-068](../decisions/ADR-068-marketing-campaign-timeline-reuses-the-runway-and-moves-no-posts.md).
- The URL is a project-scoped slug lookup. The slug is fixed at creation and `new` is reserved →
  [ADR-069](../decisions/ADR-069-marketing-campaign-url-is-a-project-scoped-slug.md).
- "Unsaved changes" runs in Alpine against a locked server snapshot, and Discard reloads from the DB →
  [ADR-070](../decisions/ADR-070-marketing-unsaved-changes-is-alpine-against-a-locked-snapshot.md).
- On/off is its own Action, `ToggleCampaign`, with its own log event, so a pause never reads as a criteria edit.

## Known limitations & gotchas
- **A campaign can be saved with no topics.** The prototype requires a topic and the story does not. Fill (MK-8) then
  drafts from the brief alone.
- **JSON key order.** MySQL returns JSON object keys in its own order (`own` before `ideas`), and Eloquent compares
  JSON casts strictly. Any new JSON column compared on save needs `SaveCampaign::canonical()` or something like it.
- Past posts are not paginated. The timezone select lists every identifier (about 420). The create page's sentence
  updates only after a round trip, not while typing.
- Timeline rows and their badges are not controls until MK-9 (studio); only an open row's Fill is.
- Off-schedule dates disappear from the timeline once they pass, unless they were missed. Posted ones then show only
  under Past posts.
- A campaign cannot be deleted yet (out of scope).

## Change history
2026-10-12 — Campaign page: criteria edited in place, future and past posts, create mode, on/off (MK-7, `b5deb57`)
2026-10-12 — Fill per open date and "Fill N open dates" with Drafting / Retry states (MK-8, `e694a73`)
