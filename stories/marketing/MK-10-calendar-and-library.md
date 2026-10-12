# MK-10 — Calendar and Library (secondary views)
Status: approved        Journey: marketing-post (step 6 of 7)
Source: owner picks: calendar option A ("a for calandar works"), library option B ("i like B best"), then "library can
be grouped in campaigns" and calendar demoted to secondary

## Story
As the owner, I want a month calendar of every campaign's posts and a library of all posts grouped by campaign, so that
I can see the whole plan across campaigns and find any post again.

## Why
Campaign pages answer "this campaign"; these two answer "everything" — and both open the same studio.

## In scope
- `GET /p/{project}/marketing/calendar` — Livewire `Marketing\Calendar`: month grid with a week toggle; posts as chips
  (approved / drafts / posted / missed) and open occurrences as dashed ghosts; side tray listing campaigns with on/off
  and a link to each; 4+ day gap banner ("Nothing approved Sat 17 – Thu 22 Oct"). Clicking a chip opens a quick-peek
  (date, campaign, state, "Open campaign ›", "Open in studio"). Below 768px it becomes a two-week agenda list.
- `GET /p/{project}/marketing/library` — Livewire `Marketing\Library`, a calm archive (owner round 5):
  - **drafts never appear here** (they live on campaign pages and in the studio); kit posts live in Inspiration;
  - exactly two sections — **Coming up** (approved, date order, grouped by week: This week / Next week / Week of …)
    and **Posted** (grouped by month, newest first; months collapse, the current month open) — each with a count;
  - cards (`marketing/post-preview`) carry a campaign chip or "One-off";
  - controls: campaign filter chips (All + each), search over captions, grid/list toggle; no status chips;
  - "♡ Save to Inspiration" on each card (MK-18's action); a card opens the studio;
  - empty states: "Nothing approved yet. Open a campaign to fill dates." / "Nothing posted yet."
- Sub-nav gains Calendar and Library.

## Out of scope (do NOT build)
- Drag to reschedule (reschedule lives in the studio; drag later), starter-pack sequences, engagement on cards (phase 2).
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given posts across two campaigns in October, when I open the calendar, then each appears on its date with its state,
  and open occurrences show as ghosts.
- Given nothing approved for 4+ consecutive days ahead, then the gap banner names the range; given no such gap, then
  no banner.
- Given week view, then only that week's 7 days show; the toggle returns to month.
- Given a chip, when clicked, then the quick-peek shows and "Open campaign" links to that campaign page.
- Given drafts, approved and posted posts, then the library shows only approved (Coming up, grouped by week) and
  posted (grouped by month) posts, with correct counts; drafts and discarded posts never appear.
- Given a campaign filter, then both sections show only that campaign's posts; one-offs show a "One-off" chip.
- Given search "toning", then only matching posts are shown and the sections keep their grouping.
- Given no approved posts, then Coming up shows "Nothing approved yet. Open a campaign to fill dates.".
- Given a 375px viewport, then the calendar is an agenda list and the library is one column, no sideways scroll.

## Applicable standards
- Design: prototype Calendar + Library routes (MK-10 option A, MK-1 option B grouped); reuse MK-1/MK-9 components.
- Codebase/DB: occurrences via MK-1's `ListCampaignOccurrences`/schedule Action — no second schedule implementation;
  eager-load posts per month (no N+1).
- Logging: `marketing.calendar.viewed`, `marketing.library.viewed` (debug, counts).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-10/option-a.html, docs/mockups/MK-1/option-b.html, docs/mockups/MK-prototype/index.html
- Chosen option: calendar A + library B, reorganised into Coming up / Posted (prototype round 5), owner 2026-10-12
- Why I chose it: "a for calandar works"; "i like B best"; then "library needs organized better, not just a mix of all
  sorts of crap".

## Do NOT touch
- `config/`; the studio's behaviour (open it, don't fork it).

## Data & interfaces
- Schema: none.
- Routes `projects.marketing.calendar`, `projects.marketing.library`; Livewire `Marketing\Calendar`, `Marketing\Library`.

## Test plan
- Pest: failing tests first, frozen clock.
- Journey test: step 6 — the approved TrueView Tuesday post appears on the calendar on its date.
- Browser check: coins data — month, week, gap banner, library sections, search; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-1, MK-9
