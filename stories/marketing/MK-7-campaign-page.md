# MK-7 — Campaign page: criteria, future posts, past posts
Status: built           Journey: marketing-post (step 3 of 7)
Source: prototype rounds 1–3 + owner: "click and see the criteria of the campaign, future posts, etc… in the topics
section, i would have a place to add more topics or detail in my own words"

## Story
As the owner, I want one page per campaign showing its criteria, its next posts and its history, so that I can see
exactly what TrueView Tuesday will post and change its rules in place.

## Why
The campaign page is where the owner lives: it answers "what's coming and how far out are we approved" and is the
launch point for Fill and the studio.

## In scope
- Routes `GET /p/{project}/marketing/campaigns/new` and `/p/{project}/marketing/campaigns/{campaign:slug}`
  (`projects.marketing.campaign`), Livewire `Marketing\CampaignPage` (one component; create = edit mode).
- **Header**: name, schedule, on/off, the self-writing sentence ("Every Tuesday at 7pm, post about toned coins and
  TrueView, with a photo you add"), and the status line "Approved through Tue 20 Oct · 2 dates need approval ·
  3 open" (MK-1's runway Action).
- **Future posts**: timeline of the next 8 occurrences, each in its state — approved (preview + caption), drafts
  (count + thumbnails), open, missed, skipped — flagged with an amber edge when they need the owner. Clicking one
  opens the studio (MK-9; until then, no-op). Fill buttons arrive with MK-8.
- **Criteria**, edited in place with Save / Discard and an "Unsaved changes" marker:
  - name; days (multi-select weekdays) + time + timezone (default the app's);
  - topics: tick ideas from the brand's idea menu (by category) + **own topics** — an "Add a topic" input (Enter
    appends), each row editable inline with optional detail, removable;
  - brief (1–2 sentences);
  - photos: I'll add a photo per post / Pick from favourites / Text only / Let AI decide.
- **Past posts**: this campaign's posted posts, newest first.
- "+ New campaign" on the campaigns list (MK-1) opens the create mode; campaign rows link here.
- Changing a campaign's days/time leaves already-approved posts on their dates; open future occurrences follow the
  new rule. Disabling a campaign keeps its posts.

## Out of scope (do NOT build)
- Generating drafts (MK-8), the studio (MK-9), analytics strip (phase 2), deleting a campaign (later), starter packs.
- Retiring a page or feature? The per-campaign "Upcoming posts" modal from the prototype is superseded by this page
  except as the calendar's quick-peek (MK-10).

## Acceptance criteria (executable — these become the Pest test names)
- Given a campaign, when I open its page, then header, status line, 8 future occurrences and criteria render.
- Given I add own topics "Album toning" (detail "the rainbow came from the paper") and "Rate the rainbow", when I
  save, then both are stored with their detail and listed in order.
- Given I change the time without saving, then "Unsaved changes" shows; Discard restores the stored values.
- Given a name already used in this project, then validation fails; given no day selected, then validation fails.
- Given approved posts on Tue 20 and the campaign moves to Wednesday, then Tue 20 stays approved on Tue 20 and the next
  open occurrences fall on Wednesdays.
- Given posted posts for this campaign and another, then Past posts lists only this campaign's.
- Given create mode, when I save a valid campaign, then I land on its page and it appears on the campaigns list.
- Given a slug from another project, then 404.
- Given a 375px viewport, then the timeline and criteria stack with no sideways scroll.

## Applicable standards
- Design: prototype campaign route (criteria = MK-7 option A plain page, implied by list → detail); one form for
  create and edit; reuse `board/switch`, MK-1's runway/alert components.
- Codebase/DB: Actions `SaveCampaign`, `ListCampaignOccurrences`; no migration beyond MK-1's (topics json).
- Logging: `marketing.campaign.created`, `marketing.campaign.updated` (context: campaign_id, changed keys),
  `marketing.campaign.toggled`.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-prototype/index.html (campaign route); docs/mockups/MK-7/option-a.html (criteria form)
- Chosen option: prototype + MK-7 option A, owner 2026-10-12
- Why I chose it: "campaign should be a list view where i can click and see the criteria of the campaign, future posts,
  etc." and "a place to add more topics or detail in my own words".

## Do NOT touch
- `config/`; MK-1's runway rules (reuse, don't fork).

## Data & interfaces
- Schema: none new (MK-1 tables).
- Routes `projects.marketing.campaign`, `projects.marketing.campaign.create`; Livewire `Marketing\CampaignPage`;
  component `marketing/occurrence-row`.

## Test plan
- Pest: failing tests first, frozen clock, then build.
- Journey test: step 3 — open TrueView Tuesday from the list, add an own topic, save.
- Browser check: create "TrueView Tuesday", add two own topics, change the day, see the timeline move; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-1, MK-2 (idea menu)
