# Journey: marketing-post

From a recurring campaign to approved posts, ready to publish. Phase 1 ends at "approved + copied". Facebook
publishing and engagement extend this journey in phase 2.
Journey test: `tests/Browser/Journeys/MarketingPostTest.php` (Claude faked). It is created by MK-1 and grows one step
per story.

| # | Step | Story | Path | Status |
|---|---|---|---|---|
| 1 | Open a project's Marketing tab: campaigns list with approved-through and alerts | MK-1 | stories/marketing/MK-1-campaigns-list.md | built |
| 2 | Brand, favourites and copy rules in place (StackerMint seeded from the kit) | MK-2 | stories/marketing/MK-2-brand-kit-and-photos.md | built |
| 3 | Open TrueView Tuesday, add own topics, see future posts | MK-7 | stories/marketing/MK-7-campaign-page.md | built |
| 4 | Fill open dates: AI drafts 3 per date (Fill superseded by MK-24; Revise and taste stay) | MK-8 | stories/marketing/MK-8-ai-fill-and-revise.md | built |
| 5 | Studio: flip, "what should change?", add a photo, Approve & next | MK-9 | stories/marketing/MK-9-post-studio.md | built |
| 6 | See approved posts on the calendar and in the library | MK-10 | stories/marketing/MK-10-calendar-and-library.md | built |
| 7 | Drop past ads into Inspiration; drafts cite what inspired them | MK-18 | stories/marketing/MK-18-inspiration-dump.md | built |
| 8 | After Fill, every draft is a rendered on-brand ad; Download offers 1:1 and 4:5 | MK-5 | stories/marketing/MK-5-ad-renderer.md | built |
| 9 | The three drafts for a date come back as different whole ads (layout, headline, CTA, photo) | MK-19 | stories/marketing/MK-19-art-director.md | built |
| 10 | Review inbox: pick one of three rendered ads per date across campaigns; reject all redrafts the date (pick-of-3 and Reject all superseded by MK-24; the page becomes the batch view) | MK-20 | stories/marketing/MK-20-review-inbox.md | built |
| 11 | Make: on a campaign's Post maker, save rules and Make 3/4/5 distinct rendered ads with no date, campaign on or off | MK-21 | stories/marketing/MK-21-post-maker.md | pending (story draft) |
| 12 | Work: step through the batch, revise with a note, 👍/👎, mark Ready or Drop, Make more | MK-22 | stories/marketing/MK-22-work-the-batch.md | pending (story draft) |
| 13 | Place: put N Ready ads on the campaign's next open dates from a start date, in my order; preview, confirm, undo | MK-23 | stories/marketing/MK-23-place-ready-posts.md | pending (story draft) |
| 14 | Dated queue fed only by placing: per-date Fill, Regenerate and Reject all retired; Review shows maker batches | MK-24 | stories/marketing/MK-24-queue-fed-by-placing.md | pending (story draft) |

**Re-shaped 2026-10-14 (owner).** Steps 1–10 are the history of how the journey was built. From MK-24 on, posts reach
a date by **make → work → place → dated queue**: the owner makes batches on a campaign's Post maker (step 11), works
them to Ready (12), places them on the campaign's next open dates (13), and the dated queue (campaign page future
posts, calendar, library, studio) is unchanged downstream. MK-24 (14) retires the old way in: MK-8's per-date Fill
(step 4) and MK-20's pick-one-of-three and Reject all (step 10). Revise, taste, inspiration (7), rendering (8) and the
art director (9, its layout rule adapted to batches by MK-21) carry over into the maker. MK-24 rewrites the journey
test's Fill-based steps (4, 5, 7, 8, 9, 10) onto the maker; steps 11–13 are added by MK-21–23.

Phase 2 adds: connect the Page → publish / send on schedule → engagement and campaign analytics.
Open hole: what happens to a missed post. Phase 1 behaviour is that it waits for the owner (Post it now / Reschedule).
