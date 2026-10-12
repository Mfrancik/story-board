# MK-1 — Marketing tab: campaigns list with approval runway and alerts
Status: approved        Journey: marketing-post (step 1 of 7)
Source: owner brain dump 2026-10-12 + prototype rounds 1–3 (stories/marketing/README.md)

## Story
As the owner, I want every project to have a Marketing tab that opens on a list of my recurring campaigns, so that
I can see at a glance how far out each campaign is approved and what needs me.

## Why
Campaigns are the unit the owner thinks in ("for the TrueView Tuesday campaign, what are the future posts, how far
out are we approved for"). This story lays the schema every later MK story builds on, plus the landing page.

## In scope
- Schema (generic; nothing coin-specific): `marketing_campaigns`, `marketing_posts` (see Data & interfaces).
- Models `MarketingCampaign`, `MarketingPost` with relations both sides; `Project` gains `marketingCampaigns()`.
- `CampaignSchedule` value/Action: given a campaign's days + time + timezone, list its next N occurrences from now.
- `BuildCampaignRunway` Action, per campaign:
  - next occurrence and its state;
  - **approved through** = the last date of the unbroken run of approved occurrences starting at the next one
    (an approved date after a gap does not extend it — owner may revisit);
  - a 6-occurrence runway (approved / drafts to approve / open / skipped);
  - alerts: `N to approve`, `Needs a photo`, `Missed <day>`, `N open dates`, `Nothing approved past this week`.
- **Missed** is derived, never stored: an approved post whose `scheduled_for` has passed without being marked posted.
- Route `GET /p/{project}/marketing` (`projects.marketing`), Livewire `Marketing\CampaignList`, behind
  `EnsureProjectIsShown` + `GuardsProjectPage` like every project page.
- **Marketing** tab added last in `board/project-tabs`; a count dot when anything needs approval.
- Marketing sub-nav component (Campaigns · Calendar · Library · Inspiration · Settings); items for unbuilt pages
  are omitted, not dead links.
- Campaigns list per the prototype: alerts banner ("3 posts need your approval · …") with Start approving; one row
  per enabled campaign (name, schedule, next post, approved-through + runway bar, badges); paused campaigns in a
  quieter group; whole row links to the campaign page (MK-7; until it exists the row is not a link).
- Empty state: "No campaigns yet" (the New campaign button arrives with MK-7).

## Out of scope (do NOT build)
- Creating/editing campaigns (MK-7), the post studio (MK-9), AI (MK-8), calendar/library (MK-10).
- The analytics strip (phase 2, MK-15) — leave no placeholder.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given an enabled project, when I open `/p/{project}/marketing`, then the campaigns list renders with the project
  header and the Marketing tab current.
- Given an unknown or disabled project, then the page is refused and `board.project_page_refused` is logged.
- Given a campaign on Tue 19:00 with Tue 13 and Tue 20 approved and Tue 27 open, when today is Mon 12 Oct, then the
  row reads "Approved through Tue 20 Oct" and the runway shows 2 approved, 1 open, …
- Given Tue 13 approved, Tue 20 open and Tue 27 approved, then approved-through is Tue 13 (unbroken run only).
- Given a date with drafts and no approved post, then the row shows "1 to approve" and the banner counts it.
- Given an approved post scheduled yesterday and not marked posted, then the row shows "Missed <day>"; and no
  stored status says missed.
- Given a disabled campaign, then it is listed under Paused and not counted in the banner.
- Given no campaigns, then the empty state shows.
- Given a campaign in another project, then it is not listed.
- Given a 375px viewport, then rows stack with no sideways scroll.

## Applicable standards
- Design: prototype Campaigns list; shell tokens; reuse `board/project-header`, `board/project-tabs`,
  `board/switch`, `board/state` — check `docs/UI-INVENTORY.md` first and add the new components to it.
- Codebase/DB: Actions in `app/Actions/Marketing/`; Livewire in `app/Livewire/Marketing/`; FKs with explicit
  `onDelete`; index `(campaign_id, scheduled_for)` and `(project_id, status)`.
- Logging: `marketing.campaigns.viewed` (debug, project, counts); `marketing.runway.failed` (warning) if a campaign's
  schedule cannot be computed (bad timezone), and the row shows an error state instead of breaking the page.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-prototype/index.html (Campaigns route); earlier rounds in docs/mockups/MK-1, MK-7, MK-10
- Chosen option: the prototype (rounds 1–3), owner 2026-10-12
- Why I chose it: "i want first page to be my campaigns… list view where i can click and see the criteria… how far
  out are we approved for" and "in campaigns show me alert if needing to provide or approve some".

## Do NOT touch
- `config/`; other project pages beyond adding the tab; RD-11's Overview work.

## Data & interfaces
- `marketing_campaigns`: id, project_id (FK cascade), name, slug (unique per project), days (json, ISO weekdays),
  time (time), timezone, is_enabled, topics (json: `{ideas: [keys], own: [{text, detail}]}`), brief (text null),
  photo_mode (`per_post|favourites|text_only|ai_decides`), timestamps.
- `marketing_posts`: id, project_id (FK cascade), campaign_id (FK null, nullOnDelete), scheduled_for (datetime null),
  status (`draft|approved|posted|skipped|discarded`), caption (text), asset_id (null; FK added by MK-2), source
  (`ai|manual|kit`), generation_meta (json null), posted_at (null), timestamps.
- Route `projects.marketing`; Livewire `Marketing\CampaignList`; components `marketing/sub-nav`,
  `marketing/runway-bar`, `marketing/alert-badge`.

## Test plan
- Pest: failing tests from the criteria first (frozen clock `Carbon::setTestNow('2026-10-12 11:00')`), then build.
- Journey test: create `tests/Browser/Journeys/MarketingPostTest.php` — step 1 opens `/p/coins/marketing` and sees
  a seeded campaign's approved-through line.
- Browser check: open `/p/coins/marketing` with factory data → banner, rows, runway; resize to 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: RD-11 (also edits `board/project-tabs`)
