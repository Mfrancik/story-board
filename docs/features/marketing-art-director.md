# Marketing: art director
Status: active   ·   Last updated: 2026-10-13   ·   Stories: MK-19

## Overview
Fill's three drafts for a date come back as three whole ads, not three captions over one default image. In the
same Claude call that writes the captions, each draft also gets an `ad`: one of MK-5's five layouts, the words on
the image (headline, subline, call to action, badge) and the photo crop. The three drafts must use at least two
layouts, so the owner chooses between ads that actually differ. Revise and the studio's Rebuild return a new ad
too. Claude picks the **layout and words only**. The look (brand colours, font, and the campaign's ad style) stays
fixed ([ADR-095](../decisions/ADR-095-marketing-claude-picks-layout-campaign-keeps-style.md)).

It extends [AI fill and revise](marketing-ai-fill-and-revise.md) (MK-8) and feeds the
[ad renderer](marketing-ad-renderer.md) (MK-5). It adds no table, column or migration.

## How it works
**1. The prompt** (`app/Services/Marketing/MarketingPrompts.php`).
- `system()` now ends with `adMenu()`: every `MarketingPost::AD_LAYOUTS` entry with its owner-facing label
  (`MarketingPost::AD_LAYOUT_LABELS`) and its "use when" line (`MarketingPrompts::LAYOUT_USE_WHEN`). A layout whose
  `BuildAdHtml::MAX_HEADLINE` is below 60 states its own cap. Then come the rules for words on the image: the
  length caps, no hashtags, no emoji unless the voice asks for them, and the crop values. This is all fixed text,
  so it stays inside the cached system prefix.
- `draft()` appends `adLines($campaign)` **after** the taste, inspiration and photo blocks, which are unchanged. It
  names the campaign's ad style as context ("the style is fixed; you choose the layouts"), states the
  two-layout rule, and adds a photo-mode hint. With `per_post`, photo layouts suit any draft because the owner adds
  the photo later. With `favourites`/`ai_decides`, photo layouts are only for drafts given a photo. With
  `text_only`, photo layouts are not allowed.
- `revise()` appends `currentAd($post)`, which quotes the post's current layout and words and asks for the ad back,
  changed where the owner's note or the new caption needs it.
- `CAPTION_SCHEMA` (used by both draft and revise replies) now requires `ad` (`AD_SCHEMA`). `layout` and `crop` are
  enums. String lengths appear only in descriptions, because structured outputs do not enforce `maxLength`
  ([ADR-097](../decisions/ADR-097-marketing-ad-specs-are-fixed-and-logged-not-rejected.md)).

**2. Fill** (`app/Actions/Marketing/DraftOccurrence.php`).
- `drafts()` reads each candidate's `ad` with its caption. A reply is rejected as `invalid_reply` (MK-8's existing
  failure state with Retry, no posts written) only when all three drafts name the **same known** layout. Missing or
  unknown layouts do not count towards that rule, so an MK-8-shaped reply with no `ad` still fills.
- `artDirect($post, $ad)` stores two things on each new post
  ([ADR-096](../decisions/ADR-096-marketing-claudes-ad-choice-and-the-drawable-spec-stored-apart.md)):
  `generation_meta.ad`, which is Claude's choice as sent, limited to the schema's keys by
  `MarketingPrompts::adChoice()` (null when there was none); and `ad`, which is the drawable spec
  `NormaliseAdSpec` makes of it.
- Each draft's render is then queued with `QueuePostRender::handle($post, respec: false)`, so the stored spec is
  drawn as is.

**3. Normalising** (`app/Actions/Marketing/NormaliseAdSpec.php:handle($post, $ad)`). Turns whatever Claude sent into
a spec MK-5 can draw, checked against the post as it is now. Each fix is logged:
- No `ad`, or a layout not in `AD_LAYOUTS`: the post gets `BuildDefaultAdSpec`'s spec, as before MK-19.
- A photo layout (`PHOTO_LAYOUTS`) with no photo becomes `text_card`, unless the post is a per-post draft still
  waiting for the owner's photo (`needsPhoto()`). That draft is not rendered yet, and Claude's photo layout is the
  one wanted once the photo arrives.
- Text is collapsed to one line and cut at the last whole word with trailing punctuation removed (`cut()`). The
  limits are headline `min(HEADLINE_MAX 60, BuildAdHtml::MAX_HEADLINE[layout])`, subline 120, cta 24 and badge 24.
  An empty headline falls back to the default spec's headline. A bad crop becomes `center`. `asset_id` is always
  the post's own photo.

**4. Revise** (`app/Actions/Marketing/ReviseDraft.php`). The reply's `ad` (or, if it sent none, the previous choice)
becomes the new `generation_meta.ad`. The normalised spec is **merged over** the old `ad`, so the previous render ids
stay and the old image shows until the new render lands. The kept `MarketingPostVersion` copies the old
`generation_meta`, so it holds the old ad without any schema change. `revise.succeeded` logs the new `layout`.

**5. Re-speccing later** (`app/Actions/Marketing/QueuePostRender.php`). Every re-spec (`respec: true`) after a photo
change (`SetPostPhoto`), Undo (`UndoRevision`, which restores the version's `generation_meta`) or a caption edit
(`EditPostCaption`) rebuilds the spec from `generation_meta.ad` through `NormaliseAdSpec`. Only a post Claude never
art-directed (one-offs, pre-MK-19 drafts) falls back to `BuildDefaultAdSpec`. This keeps Claude's layout through
Undo and photo changes, and lets a photo added later restore its photo layout.

**6. Studio** (`resources/views/livewire/marketing/post-studio.blade.php`). Under each draft's ad there is one quiet
line, `Layout: Photo + headline · CTA: Show us yours`, with the CTA part only when there is one (`data-ad-spec`).
It is built as one PHP string in the `@php` block, not with inline `@if` (see RUNBOOK 2026-10-13). It shows before
any render too, for example on a draft waiting for its photo.
`PostStudio::revise()`'s "unchanged rebuild" check now also compares `generation_meta.ad`. Otherwise a rebuild
that changed only the layout ("make it a question") would be undone as a no-op. The comparison is loose (`==`)
because MySQL JSON reorders keys.

## Data model
No migration. It uses MK-5's `marketing_posts.ad` (the drawable spec plus render state) and MK-8's
`marketing_posts.generation_meta` / `marketing_post_versions.generation_meta`, which gain an `ad` key:
`{layout, headline, subline, cta, crop, badge}` as Claude sent it (schema keys only), or null.
`MarketingPost::AD_LAYOUT_LABELS` is new: the owner-facing layout names.

## Interfaces
- `NormaliseAdSpec::handle(MarketingPost $post, mixed $ad): array`, keyed as `MarketingPost::AD_SPEC_KEYS`.
  Constants `HEADLINE_MAX`, `SUBLINE_MAX`, `CTA_MAX`, `BADGE_MAX`.
- `MarketingPrompts::AD_SCHEMA`, `LAYOUT_USE_WHEN`, `adChoice(mixed): ?array`. `CAPTION_SCHEMA` requires `ad`.
- `DraftOccurrence::handle()` throws `MarketingAiException('…', 'invalid_reply')` for three drafts in one known
  layout.
- Test hook: `data-ad-spec="<post id>"` on the studio's layout line.

## Configuration
None. The caps are constants in `NormaliseAdSpec`, and the per-layout headline caps are MK-5's
`BuildAdHtml::MAX_HEADLINE`.

## Observability
- `marketing.ai.ad_spec_fixed` (info: `post_id`, `campaign_id`, `field`, `reason`), once per fix. `reason` is one of
  `missing` (no ad), `unknown_layout`, `no_photo` (photo layout became text card), `empty` (headline fell back),
  `too_long` (cut) or `invalid` (non-string text, bad crop). A steady stream of `too_long` or `unknown_layout`
  means the prompt needs tightening, not the code.
- `marketing.ai.draft.succeeded` gains `layouts` (the three stored layouts). `marketing.ai.revise.succeeded` gains
  `layout`.
- A same-layout rejection is MK-8's `marketing.ai.draft.failed` with `invalid_reply` and the message "…drafts in
  one layout…".
- A healthy Fill trace is `draft.started`, `draft.succeeded` with two or three distinct `layouts`, then three
  `marketing.render.queued` with matching layouts, all under one `request_id`.

## Testing & verification
- `tests/Feature/Marketing/ArtDirectorTest.php`: one `it()` per acceptance criterion (named as in the story), plus
  an MK-8 reply with no ad, the rest of the clean-up (empty headline, over-long cta/subline/badge, bad crop, a
  layout's smaller headline cap), a per-post draft keeping its photo layout until the photo comes, Undo restoring
  the previous spec, a layout-only rebuild being kept, and the same-layout rejection from `DraftOccurrence`
  directly. Claude is faked.
- `tests/Feature/Marketing/AiFillAndReviseTest.php`: MK-8's "nothing about the look" prompt check no longer bans
  the word "layout". Colours, fonts, typeface, design and hex values are still banned.
- `tests/Browser/Journeys/MarketingPostTest.php` step 9: the three TrueView Tuesday drafts have at least two layouts,
  and the studio shows each one's layout line (`journeyStep('marketing-post', 'art director', …)`).
- Browser check with the real key (owner, **not yet done**): Fill one coins date, compare the three rendered ads,
  then Revise one with a note.

## Key decisions & tradeoffs
- Claude picks layout and words. The ad style is the campaign's (the owner's MK-5 pick), named in the prompt only
  as context → [ADR-095](../decisions/ADR-095-marketing-claude-picks-layout-campaign-keeps-style.md).
- Claude's raw choice (`generation_meta.ad`) and the drawable spec (`ad`) are stored apart, and every re-spec
  starts from the choice → [ADR-096](../decisions/ADR-096-marketing-claudes-ad-choice-and-the-drawable-spec-stored-apart.md).
- Bad specs are fixed and logged, not rejected. Lengths are enforced in code, not in the schema. Only "all three in
  one known layout" rejects the reply →
  [ADR-097](../decisions/ADR-097-marketing-ad-specs-are-fixed-and-logged-not-rejected.md).
- `badge` (≤ 24) is in the schema although the story did not list it, because MK-5's owner pick includes the coin
  spotlight's tag.

## Known limitations & gotchas
- **A hand-edited caption no longer changes the on-image headline.** Under MK-5 alone the headline followed the
  caption's first line. Now a caption edit re-specs from Claude's recorded choice, so the image keeps Claude's
  words. Revise is the way to change them. Hand-editing the spec is MK-4's job.
- A photo added later to a `favourites`/`ai_decides` draft that Claude gave a photo layout (normalised to text card
  because it had no photo) should return to the photo layout on re-spec. That path is untested.
- The two-layout rule checks Claude's layouts *before* normalising. Drafts can still render alike if fallbacks
  or photo-to-text-card fixes collapse them ([ADR-097](../decisions/ADR-097-marketing-ad-specs-are-fixed-and-logged-not-rejected.md)).
- Inspiration informs words and layout choice only through the prompt. Matching an inspiration ad's *layout* is a
  later batch.
- Changing the campaign's ad style still does not re-render existing posts (MK-5).
- `Str::limit(..., preserveWords: true)` is not safe for these cuts (RUNBOOK 2026-10-13). Use `NormaliseAdSpec::cut()`.

## Change history
2026-10-13 — Claude art-directs each draft: an `ad` per caption in Fill and Revise, the two-layout rule,
`NormaliseAdSpec`, re-spec from `generation_meta.ad`, and the studio's layout line (MK-19, `c21a4d7`)
