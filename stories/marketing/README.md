# Marketing initiative (prefix `MK-*`)

Per-project Marketing area: brand kit, content library, AI-generated candidates, a review page,
calendar, and publishing to the project's social accounts. First project: coins (StackerMint) on Facebook.
Journey: `docs/journeys/marketing-post.md`.

**State (2026-10-12):** the phase-1 stories were written as `draft` from the approved clickable prototype
`docs/mockups/MK-prototype/index.html`. The owner approved the whole written set (MK-1, 2, 7, 8, 9, 10, 18) on 2026-10-12
("approve all, and start phase 1 as suggested"). Build phase 1 = MK-1, MK-2, MK-7; MK-8, 9, 10, 18 follow in the next batch.

## Owner decisions (story gate, 2026-10-12)
- **Networks:** the data model supports multiple networks from day one. Each post has one row per network
  (network, status, external id, link, engagement). Only the Facebook adapter is built now.
- **Approval:** nothing is posted unless the owner picked and confirmed it. The AI generates candidates and the
  owner steps through them, flips between designs and confirms. Confirmed posts show on the calendar with
  their send time.
- **Cadence:** recurring slots, e.g. "Coin of the week · Tue 10:00". The AI fills open slots with candidates.
- **Scheduler:** Story Board's own queue on the Mac, not Meta's native scheduling.
- **AI:** in this phase. It generates full suggestions (template + fields + caption) as drafts, never published
  directly.
- **Open:** if the Mac is asleep at send time, the recommended behaviour is (a): mark the post *missed* and
  offer Send now / Reschedule, never sending late on its own. Confirm this at the approval of the written stories.
- **Open:** split into phase 1 (MK-1..11, plan/generate/review/calendar) and phase 2 (MK-12..15,
  Facebook connect, publish, queue, engagement), or keep it as one phase.
- **Taste learning (owner, 2026-10-12, at the MK-1 pick):** "i want a way to also like and dislike proposed
  ones, that way over time it starts to learn my style and what i like so other ads are similar, and brand
  design, fonts, etc. all stay the same". Like/dislike on every candidate and variant, with an optional reason
  chip. Feedback is stored per project and fed into MK-8's generation as examples. Likes shape content, template
  choice and caption style only. Brand tokens are fixed inputs the AI can never vary. Liking a candidate is not
  confirming it. → new story **MK-16**.
- **Generic, not coin-shaped (owner, 2026-10-12):** "for coin of the week type stuff we shouldnt select badges or
  input grade, these need to be pulled from actual coins that exist... the marketing section should be
  customizable and fitting for any project over time". Each project defines its own **content item types**
  (fields + photos). Templates bind to item fields (`{title}`, `{grade}`, `{set.filled}/{set.total}`); the designer
  picks an item and does not type badges or grades. StackerMint's templates and brand are one project's
  *template pack* + *brand kit*.
- **Coin items source for now:** "I can upload a few". Manual entry and upload of items with photos. A
  read-only coins connector is a later phase.
- **Campaigns replace bare slots (MK-7):** name, schedule (weekly Tue 19:00, start/end), allowed templates,
  content source (an item filter, e.g. tagged *toned*, not featured in 90 days), brief, and how many weeks ahead
  to prepare. The first-two-weeks sequence becomes a campaign starter pack. Owner asked for a campaign-setup
  mockup.
- **Phase 1 re-scoped (owner, 2026-10-12, at the designer pick):** "A is nice but i think we should analyze the
  process a bit... maybe i give access to the DB or something. I think for now if we can skip this part, and we
  can start to load other types of AI generated content, and create a calendar". So the **designer (MK-4),
  PNG render (MK-5) and content items (MK-17) are deferred** to a later phase, to be re-analysed then (MK-4
  leaned A). Phase 1 = AI-generated posts that need no item data: the AI writes the caption from a campaign's
  topic pool (the idea menu categories) plus brief, and picks an image from the project's photo library (kit
  images + uploads) or goes text-only. Then review → calendar. Facebook publishing (MK-12..15) is phase 2.
- **Campaign topics + images (owner, 2026-10-12, at the MK-7 mockups):** "in the topics section, i would have a
  place to add more topics or detail in my own words. image stuff we're going to need to pull in from coins i
  think". Topics take free-text entries and detail written by the owner, alongside the idea-menu pool. Images
  should eventually come from coins: a source question still open, see the feasibility review. The MK-7 option
  is not picked yet.
- **Simple plan adopted (owner, 2026-10-12, after a feasibility review):** "i want it to be simple and easy to
  manage and use and intuitive, not super complicated, but still needs to do everything we need" → chose the
  simple 3-phase plan.
  - **Phase 1:** library (B), brand + photos (kit loaded by a seeder), campaigns lite, Generate (3 drafts per
    occurrence; copy rules as **warnings** plus likes/dislikes as few-shot examples), review deck (A) with thumbs,
    calendar (A) with **Copy caption + Download image**. The owner posts to Facebook by hand.
  - **Phase 2:** Facebook connect, publish, send on schedule (queue vs Meta-native to be re-decided then; the
    review recommends Meta-native because the Mac sleeps), and engagement.
  - **Phase 3:** a small read-only **coins API** (`/api/v1/marketing/items`, token, CDN image URLs; chosen over DB
    reads), content items, designer/render, starter packs, calendar drag.
  - **Cut from phase 1:** the starter-pack sequencer, photo tags and 90-day reuse rules, prepare-ahead config
    (convention: 2 weeks), and a taste summariser. Taste = the last ~10 likes / 5 dislikes with reasons, given as
    prompt examples.
  - **Campaign setup:** the owner wants to see simplified mockups before picking.
- **Per-campaign upcoming posts (owner, 2026-10-12):** "if im in a campaign like trueview tuesday, i want to see
  the next several posts of that one specifically in a modal I can pop up and view easily". A modal per campaign
  (opened from the tray, the campaign form and its calendar chips) lists the next ~6 occurrences with their
  state: confirmed, drafts to review, open (Fill), or missed. Confirmed posts get Copy/Download.
- **Campaigns-first redesign (owner, 2026-10-12, after clicking through the prototype):** "i dont want first page to
  be a calendar, id want first page to be my campaigns... campaign should be a list view where i can click and see
  the criteria of the campaign, future posts, etc.. if i have a post i want to edit instead of just regenerate, id
  like to give feedback about what to adjust and change.. library can be grouped in campaigns. it should be clear
  and very easy to go in and see, for the toner tuesday campaign, what are the future posts, how far out are we
  approved for".
  - **Landing:** a campaigns **list** (recurring campaigns) showing schedule, next post, "approved through
    <date>", and drafts waiting.
  - **Campaign detail page:** criteria, future posts (approved / drafts / open), approval runway, and past posts.
    Campaign editing follows MK-7 option A (plain page), implied by list → detail.
  - **Revise with feedback:** the owner writes what to change, and the AI revises that draft. Feedback also feeds
    taste. This is alongside Regenerate.
  - **Library:** grouped by campaign.
  - **Calendar:** becomes a secondary view.
- **Fill → studio modal; Review page dropped (owner, 2026-10-12, prototype round 2):** "when i go into a campaign
  and click fill, i want a modal to pop up with a big screen of the ad, and maybe 2 or 3 to flip through, and a
  box i can respond with changes where AI rebuilds or changes things up for me. review kind of becomes redundant
  if its done there in campaigns. in campaigns show me alert if needing to provide or approve some. photo
  library probably becomes not needed for coins specifically because we'll often be sharing new or different
  coins. i guess i could upload pics of some of my favorite coins though".
  - Fill or open a date → a large modal with a big preview, 2–3 candidates to flip through, a feedback box
    (AI rebuilds), and Approve.
  - **No separate Review page.** Campaign rows and pages show alerts ("2 dates need approval", "needs a photo").
  - The photo library is demoted: upload a photo per post inside the modal, plus an optional small "favourite
    coins" set. Real coin photos come from the coins API in phase 3.
  - **Supersedes:** the MK-9 deck A pick. Its flip / like-dislike / revise ideas move into the modal.
- **config/:** the Facebook and Anthropic keys need entries in `config/services.php`. Owner approval of the list
  covers this; secrets live only in `.env` or encrypted columns.

## Copy rules (enforced by MK-6, used to prompt MK-8)
Short and human, one or two lines. No filler ("tell us below, we read every one"). No cert-lookup claims until
the integration is everywhere. Sets are the centrepiece. A comparison pairs coins of genuinely similar value and
says where the price came from.

## Stories

**Phase 1** (written, `draft`, build in this order):

| ID | Story | File |
|---|---|---|
| MK-1 | Marketing tab: campaigns list, approval runway and alerts (+ schema) | MK-1-campaigns-list.md |
| MK-2 | Settings: brand kit, favourite photos, copy rules, StackerMint seed (absorbs MK-3) | MK-2-brand-kit-and-photos.md |
| MK-7 | Campaign page: criteria, future posts, past posts | MK-7-campaign-page.md |
| MK-8 | AI Fill and Revise: drafts per date, rebuild from feedback (absorbs MK-6 lint, MK-16 taste) | MK-8-ai-fill-and-revise.md |
| MK-9 | Post studio: big preview, flip, rebuild, photo, approve & next, Mark as posted (replaces the Review deck) | MK-9-post-studio.md |
| MK-10 | Calendar (A) + Library (B, grouped by campaign) as secondary views (absorbs MK-11's gap warning) | MK-10-calendar-and-library.md |
| MK-18 | Inspiration dump: past ads Claude reads once, used as examples (copy and tone now, layout later) | MK-18-inspiration-dump.md |

**Phase 2** (not written yet): MK-12 connect a Facebook Page · MK-13 publish now · MK-14 send on schedule (queue
vs Meta-native, re-decided then) · MK-15 engagement **+ campaign analytics** (30-day totals, campaigns compared,
per-campaign vs average; owner 2026-10-12 asked for it on the campaigns page; it needs Facebook data, and phase 1's
Mark as posted + link lets it backfill).

**Phase 3** (not written yet): coins read-only API (`/api/v1/marketing/items`), MK-17 content items, MK-4 designer +
MK-5 PNG render (then inspiration can match layouts), MK-11 starter packs, calendar drag.

**Merged / retired IDs:** MK-3 → MK-2 · MK-6 → MK-8 · MK-16 → MK-8 + MK-9 · MK-11 gap warning → MK-10 (starter packs →
phase 3).

Depends on RD-11 (adds the Overview tab to `board/project-tabs`, which MK-1 also extends).
Seed sources: `~/Downloads/StackerMint-Facebook-Kit/` (downscaled copy for mockups in `docs/mockups/MK-kit/`),
the live artifact https://claude.ai/artifact/8zbQFXawN5vUAkQDF2NxzN, and the swirl from coins `origin/main`
`resources/views/components/app-mark.blade.php` (not local main).
