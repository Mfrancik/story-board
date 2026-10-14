# MK-21 — Post maker: saved rules, then "Make 3/4/5" distinct ads with no date
Status: draft          Journey: marketing-post (step 11 of 14)
Source: owner 2026-10-14 (verbatim idea above): "instead of planning the post for each day, i think we can have a post
maker in one of the campaigns, and i define the rules of what i want to make, maybe 3 - 5 posts made at a time i can
iterate through each, give feedback, and work building those several till im ready, then we can just place them. ew
dont need to make 3 for each batch and approve from the 3, a campaign will always be similar so making even 30 ads
were comfortable with is fine, and just space them out on the timeline of the campaign" + "also i dont want to have to
turn a campaign on to generate posts."

## Story
As the owner, I want a post maker on each campaign where I save what I want made and press "Make 3/4/5", so that I get
that many different finished ads to work on without first planning which day each one is for.

## Why
Fill makes three candidates per date and throws two away, and refuses a paused campaign. A campaign's posts are all
alike, so making a stock of ads first and dating them later (MK-23) wastes no calls and needs no switch.

## In scope
- Route `GET /p/{project}/marketing/campaigns/{campaign}/maker` → new Livewire `Marketing\PostMaker` (project-scoped
  slug lookup exactly as `CampaignPage::mount()`, ADR-069). The campaign page header gains a **Post maker** link with
  the working/ready counts. Not offered in create mode (a campaign must exist).
- **Maker rules**, saved on the campaign: *What to make* (≤ 500), *Tone* (≤ 200), *Must-haves* (up to 10 lines,
  ≤ 120 each). Edited only in the maker; Save / Discard, with Alpine unsaved-changes like ADR-070. They are used
  **beside** the campaign's topics, idea menu, brief, photo mode and ad style, never instead of them.
- **Note for this batch** (≤ 300, optional, not saved on the campaign; stored on each post it shaped).
- **Make 3 / Make 4 / Make 5**: one Claude call returns exactly that many ads. Each is a `draft` post with
  `scheduled_for` **null**, `source=ai`, its `ad` spec via MK-19's `NormaliseAdSpec`, linted, and its render queued
  (`QueuePostRender`, `respec: false`). Works whether the campaign is **on or off**.
- **Distinct** (MK-19's two-layout rule, adapted): the batch uses at least `min(N − 1, 3)` different known layouts
  (3 → 2, 4 → 3, 5 → 3), and no two ads share a headline or caption (case- and space-insensitive). Breaking either is
  `invalid_reply`: nothing written, "Making failed · Retry".
- Topics: N topics from MK-8's rotation (rotation and pool reused, generalised from 3 to N). Taste (MK-8) and
  inspiration (MK-18) blocks feed exactly as for Fill.
- Runs as a queued job (`MakeBatchJob`, unique per campaign, `tries = 1`, carries the request id). While it runs the
  maker shows "Making N ads…" and polls; failure shows "Making failed · Retry" (cache state, the ADR-071 pattern).
- The newest batches show as a grid of 1:1 renders (`marketing/ad-square`) with "Rendering…" / fallback + Retry, the
  layout line, and the batch's note. Working with them (step, revise, ready) is MK-22.

## Out of scope (do NOT build)
- Working the batch — step, revise, vote, Ready, Drop (MK-22); placing on dates (MK-23); retiring Fill (MK-24).
- Editing rules from the criteria form; per-ad hand editing of the spec (MK-4); new layouts.
- Batches larger than 5 or several calls per press.
- Retiring a page or feature? None retired here — Fill keeps working until MK-24.

## Acceptance criteria (executable — these become the Pest test names)
- Given a campaign with rules saved, when I press Make 4 and faked Claude returns four ads in three layouts, then four
  `draft` posts exist with `scheduled_for` null, each with an `ad` spec and a queued render, and
  `generation_meta.call` is `make` with one shared `batch` id and `batch_size` 4.
- Given the campaign is **off**, when I press Make 3, then the batch is made exactly as for an enabled campaign.
- Given Make 5 and a reply with five ads in only two layouts, then nothing is written, `marketing.maker.make.failed`
  logs `reason: invalid_reply`, and the maker shows "Making failed · Retry".
- Given a reply in which two ads share a headline, then the reply is rejected as `invalid_reply`.
- Given a reply with fewer ads than asked for, then it is rejected as `invalid_reply`; a reply with more keeps the
  first N.
- Given saved rules and a batch note, when the prompt is built, then it quotes What to make, Tone, each Must-have and
  the note after the brief and topics, gives no date line, asks for N ads, and the taste, inspiration, photo and
  ad-style blocks are unchanged.
- Given topics A, B, C, D and a Make 4, then each ad's `generation_meta.topic_key` is a different topic, in MK-8's
  rotation order.
- Given rules with a 501-character What to make or an eleventh must-have, when I save, then a field error shows and
  nothing is stored.
- Given a batch already making for this campaign, when I press Make again, then it is refused (`already_making`),
  logged, and no second job is queued.
- Given no `ANTHROPIC_API_KEY`, then the Make buttons are disabled with "Add ANTHROPIC_API_KEY to .env" and a forced
  call is refused (`not_configured`) and logged.
- Given a photo-per-post campaign, then the batch's ads show "Needs a photo" and are not rendered until they get one
  (MK-19's `needsPhoto()` rule, unchanged).
- Given the maker URL with another project's campaign slug, then it returns 404 and `marketing.campaign.not_found` is
  logged.
- Given a maker post, then it appears on no calendar, library, runway, campaign timeline or tab count (its date is
  null), and Fill still sees that campaign's dates as open.
- Given no batches yet, then the maker shows the rules and "Nothing made yet — set your rules and press Make."
- Given a 375px viewport, then rules, note, Make buttons and the batch grid stack with no sideways page scroll.

## Applicable standards
- Design: chosen mockup; reuse `marketing/sub-nav`, `marketing/ad-square` and the existing
  field styles from the criteria form. Alpine for unsaved-changes and the Make-count choice; no round-trip for UI
  state. New component `marketing/maker-batch` only if `ad-square` in a loop does not fit (justify in the build).
- Codebase/DB: one migration (below). New Actions `SaveMakerRules`, `MakeBatch` (the Claude call and writes, MK-8's
  all-or-nothing transaction), `QueueMakeBatch` (refusals + job dispatch); new `MakeBatchJob`; new cache service
  `MakingStatus` (`marketing.making.{campaign}`, TTLs as `DraftingStatus`). `MarketingPrompts` gains `make($campaign,
  $count, $note)` and an undated heading; the rotation takes a count. Reuse `NormaliseAdSpec`, `LintCaption`,
  `QueuePostRender` — no second spec or render path. Eager-load renders/campaign for the grid (no N+1).
- Logging: `marketing.maker.rules_saved` (info: campaign_id, changed), `marketing.maker.make.queued` (info: count,
  batch), `marketing.maker.make.started` (info: model, topics, skipped_ideas, count), `marketing.maker.make.succeeded`
  (info: post_ids, batch, layouts, tokens, cost_cents, duration_ms), `marketing.maker.make.failed` (error: reason,
  status, error_type, duration_ms), `marketing.maker.make.refused` (info: reason). Existing
  `marketing.ai.ad_spec_fixed`, `marketing.ai.invalid_asset`, `marketing.lint.warned`, `marketing.render.queued`.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-21/option-{a,b,c}.html — ONE set for the whole maker (MK-21, MK-22 and MK-23 build from it).
  Each option shows: rules + batch note + Make 3/4/5; a batch being worked (step ←/→, revise note, 👍/👎 reasons,
  Ready / Drop, Working · Ready counts, Make more); and the placement preview (start date, ordered list → dates,
  skipped dates, Confirm / Undo). Directions: (a) **Filmstrip** — one big ad with a strip of the batch below, rules in
  a collapsible side panel, placement as a modal; (b) **Board** — Working and Ready as two columns of cards, a detail
  drawer for the focused ad, placement as a full-width step; (c) **Phone-first stack** — one ad per screen, swipe,
  rules and placement as bottom sheets.
- Chosen option: <a/b/c — filled AFTER the owner picks; no build before this>
- Why I chose it: <owner's one-line reason — MK-22 and MK-23 honour it too>

## Do NOT touch
- `config/`; `FillCampaign`, `DraftOccurrence`, `DraftOccurrenceJob`, `DraftingStatus`, `RegenerateOccurrence`
  (Fill keeps working until MK-24); `PostStudio`; MK-8 taste and MK-18 inspiration selection (feed beside them);
  MK-5 templates; `SaveCampaign` and the criteria form; existing migrations.

## Data & interfaces
- Schema (one additive migration): `marketing_campaigns.maker_rules` json null, after `brief` —
  `{make: string|null, tone: string|null, must_haves: list<string>}`. Cast `array`, in `$fillable`. Why a column: the
  rules are the campaign's, persist between batches and are edited; `topics` has its own shape and normaliser.
- **Batch marker — no posts column.** A maker post is a campaign post with `scheduled_for` null. Every dated read
  already filters `whereNotNull('scheduled_for')` (`scopeRunwayWindow`, `BuildMarketingCalendar`, `ListLibraryPosts`,
  `DraftOccurrence`) or works on a date range, so the null date is itself the safe marker, and the existing
  `(campaign_id, scheduled_for)` index serves "this campaign's unplaced posts". The batch is
  `generation_meta.batch` (ULID) with `call: make`, `batch_size`, `candidate`, `note` — grouping is display-only.
  No other code path writes a campaign post without a date (checked: `DraftOccurrence`, `SkipOccurrence`,
  `CreateOneOffPost`, the kit seeder).
- Route `projects.marketing.campaign.maker`; Livewire `Marketing\PostMaker` (`make(int $count)`, `saveRules()`,
  `discardRules()`, `retryMake()`, `retryRender(id)`); `CampaignPage` header link only.
- `MakeBatch::handle(MarketingCampaign, int $count, ?string $note): Collection<MarketingPost>`;
  `QueueMakeBatch::handle(MarketingCampaign, int $count, ?string $note): string` (batch id);
  `SaveMakerRules::handle(MarketingCampaign, array $rules): MarketingCampaign`. Refusals throw
  `MarketingDraftRefusedException` (`not_configured`, `already_making`, `bad_count`).
- Test hooks: `data-maker`, `data-maker-rules`, `data-make="<n>"`, `data-making`, `data-make-failed`,
  `data-maker-batch="<batch>"`, `data-maker-ad="<id>"`, `data-maker-empty`.

## Test plan
- Pest: failing tests first, Claude faked (`fakeClaude`), renderer faked, frozen clock.
- Journey test: new step 11 — on Ask the community's maker (photo mode ai_decides, so renders at once), save a rule,
  Make 3 with fake Claude; three rendered ads in ≥ 2 layouts, none on the calendar.
- Browser check: coins with the real key — save rules on TrueView Tuesday with the campaign **off**, Make 5, compare
  the five ads; then 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-7, MK-8, MK-18, MK-5, MK-19
Open questions: (1) should a batch note also be saveable as a rule ("Keep this")? Default: no. (2) Cap on unplaced
working ads per campaign? Default: none (owner: "30 is fine").
