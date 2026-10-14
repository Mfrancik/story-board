# MK-19 — Claude as art director: drafts come back as whole ads
Status: built          Journey: marketing-post (step 9 of 10)
Source: owner 2026-10-13 phase 3 batch 1 ("generate and render ads for me to sift through"), approved list

## Story
As the owner, I want each of the three drafts for a date to come back as a complete ad — layout, words on the image,
call to action, photo and crop — so that the three options really differ and look like ads I'd post.

## Why
MK-5 renders whatever spec a post has, but its default spec just reuses the caption's first line. Claude already
writes three drafts per date in one call; asking for the ad spec in the same call costs almost nothing extra.

## In scope
- MK-8's draft reply schema gains, per draft, an `ad` object: `layout` (enum of MK-5's layouts), `headline`
  (≤ 60 chars), `subline` (≤ 120, nullable), `cta` (≤ 24, nullable), `crop` (`center|top|bottom|left|right`).
  `asset_id` stays where it is.
- Prompt: the layout menu (name + one-line "use when"), on-image copy rules (short, no hashtags, no emoji unless the
  copy rules allow), and the **three drafts must use at least two different layouts**. Inspiration style notes (MK-18)
  and taste (MK-8) inform the choice — added beside them, not changing them.
- Validation: unknown layout → MK-5's default for that post; over-long text is trimmed at a word boundary and logged;
  a photo layout with no photo falls back to the text card.
- The spec is stored on the post (`ad`), recorded in `generation_meta`, and MK-5's render job runs for each draft.
- **Revise** returns a new spec too (the owner's note can change words or layout); Rebuild likewise. Versions keep the
  old spec.
- Studio: under the rendered ad, a small line "Layout: Photo + headline · CTA: Start your collection".

## Out of scope (do NOT build)
- Hand-editing the spec (MK-4 designer), new layouts (MK-5 / later), matching inspiration *layouts* (later batch).
- Changing the number of drafts per date (stays 3) or MK-8's taste logic.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given a faked Claude reply with three drafts and ad specs, when a date is filled, then each draft post stores its
  spec and a render job is queued for each.
- Given a reply where all three drafts use the same layout, then the reply is rejected as `invalid_reply`, no posts are
  written, and the date shows MK-8's existing failure state with Retry.
- Given an unknown layout, then that draft gets the default spec and `marketing.ai.ad_spec_fixed` is logged.
- Given a 90-character headline, then it is trimmed to ≤ 60 at a word boundary.
- Given a photo layout and `asset_id` null, then the draft uses the text-card layout.
- Given a draft prompt is built, then it lists every MK-5 layout with its "use when" line and the copy rules, and the
  taste and inspiration blocks are unchanged.
- Given Revise with "make it a question", when the faked reply changes the layout, then the post's spec changes, a
  version keeps the old spec, and a re-render is queued.
- Given the studio shows a draft with a spec, then the layout name and CTA line are shown under the image.

## Applicable standards
- Design: one quiet text line in the studio; no new components.
- Codebase/DB: extend `MarketingPrompts` schema and `DraftOccurrence`/`ReviseDraft`; one `NormaliseAdSpec` Action;
  no migration (MK-5 added `ad`).
- Logging: `marketing.ai.ad_spec_fixed` (info: post, field, reason); existing `marketing.ai.draft.succeeded` gains
  `layouts` used.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- n/a — non-visual (renders through MK-5's chosen layouts; the studio change is one text line)

## Do NOT touch
- `config/`; MK-8's taste logic and MK-18's inspiration selection (feed beside them); MK-5's templates.

## Data & interfaces
- Schema: none.
- Changed: `MarketingPrompts::draft()/revise()` schemas and system text, `DraftOccurrence`, `ReviseDraft`,
  `PostStudio` view (spec line). Added: `NormaliseAdSpec`.

## Test plan
- Pest: failing tests first, Claude faked.
- Journey test: step 9 — the three drafts for the TrueView Tuesday date show at least two different layouts.
- Browser check: coins with the real key — Fill one date, compare the three rendered ads; Revise one with a note.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: n/a
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-5, MK-8, MK-18
