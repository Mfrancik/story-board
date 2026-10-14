# Marketing: ad renderer
Status: active   ·   Last updated: 2026-10-13   ·   Stories: MK-5, MK-19

## Overview
Every draft and approved post is rendered as a finished, on-brand ad image at 1080×1080 (1:1) and 1080×1350 (4:5),
so the owner judges and posts real ads instead of a caption plus a loose photo. Text-only posts become downloadable
too. The ads are Blade templates screenshotted by the Chromium that Playwright already installs: crisp, spelled
right, always in the brand, and free. An AI image generator was considered and rejected
([ADR-093](../decisions/ADR-093-marketing-ads-rendered-by-headless-chromium-over-file-urls.md)).

Each campaign picks one of three **ad styles** (gallery, vault, poster), all drawing the same five **layouts**
([ADR-092](../decisions/ADR-092-marketing-three-ad-styles-chosen-per-campaign.md)).

## How it works
**1. A render is asked for.** `app/Actions/Marketing/QueuePostRender.php:handle($post, $respec = true)` is the only
way in. It is called after:
- a draft is written: `DraftOccurrence` (Fill, Regenerate) and `CreateOneOffPost`;
- a rewrite: `ReviseDraft`, `UndoRevision` and `EditPostCaption`;
- a photo change: `SetPostPhoto`;
- `ApprovePost`, only when the post's render is `none` or `failed`;
- the studio's Retry or "Render it" (`PostStudio::retryRender()`) and the backfill `marketing:render {project}`
  (`QueueMissingRenders`). These three pass `respec: false`, which keeps a stored spec.

It refuses a post that is not a draft or approved post still to go out (`not_renderable`), and a draft still
waiting for its photo (`needs_photo`). Rendering that draft as a text card would show an ad it will not be, so the
photo change renders it instead. Otherwise it writes the **ad spec** (when re-speccing: Claude's recorded choice through
`NormaliseAdSpec` since MK-19, else `BuildDefaultAdSpec`) and
`ad.render.status = rendering` *before* dispatching `RenderPostAd`, because on the sync queue the job has finished by
the time dispatch returns. The previous render ids are kept, so the studio still has something to show.

**2. The default spec.** `app/Actions/Marketing/BuildDefaultAdSpec.php` is the spec for a post Claude never
art-directed (one-offs, pre-MK-19 drafts, a missing or unknown layout); every other spec comes from MK-19's
[art director](marketing-art-director.md). A post with a photo gets `photo_headline`, one without gets `text_card`. The headline is the caption's
first non-empty line and the subline is the rest, joined. `cta` and `badge` are null.

**3. The job.** `app/Jobs/RenderPostAd.php` is `ShouldBeUniqueUntilProcessing` per post (`uniqueFor` 600 s), with
`tries = 1` and a 120 s timeout, and it carries the request id. It is unique *until processing*, not until finished,
so a rebuild made mid-render queues its own job and the last change always gets drawn. It never calls Claude. It
builds both sizes' HTML, renders them in one browser run and hands the PNGs to `StorePostRenders`.

**4. The HTML.** `app/Actions/Marketing/BuildAdHtml.php:handle($post, $spec, $size)` returns one self-contained page
from `resources/views/marketing/ads/ad.blade.php`:
- **Style** is `$post->campaign?->adStyle()`, or gallery for one-offs. **Layout** is the spec's, but a photo layout
  (`MarketingPost::PHOTO_LAYOUTS`) whose photo is gone, unreadable or another project's is drawn as `text_card`.
- **Brand values** become CSS variables on `.ad` (`vars()`). Every colour is checked against
  `MarketingBrand::HEX_PATTERN` and the font against `FONT_PATTERN`, falling back to the defaults. `--soft` is not
  a brand field: `css/base.css` derives it from the accent (F-19).
- **CSS** is `css/base.css` plus `css/<style>.css`, read as plain files and inlined (see RUNBOOK 2026-10-13).
- **Fonts** are embedded as base64 `@font-face` rules from `public/build/fonts-manifest.json` (`fontFaces()`, cached
  per process). The photo is embedded as a data URL, and the logo is the sanitised SVG with its `xmlns` attributes
  stripped. The page then contains no URL of any kind.
- **Headline fit.** The headline is cut at a word with "…" to `MAX_HEADLINE[$layout]` characters. `BuildAdHtml`
  then measures it with `GLYPH_EMS`, a table of Instrument Sans advances at weight 600 measured in Chromium
  (`ems()`; an unlisted character uses its ASCII form's width, else 1em). The headline partial prints `--chars`,
  `--ems` (the headline's width in em) and `--word-ems` (the widest word plus a space, which is the most a line
  break can waste). The `.fit` rule in `base.css` tries 1 to 8 lines. For each line count it takes the smaller of
  the size the width allows (`N·bw / (ems + (N−1)·word-ems)`) and the size the height allows
  (`bh / (N·lh + .12)`). It keeps the largest of those sizes and clamps it to the layout's `--min`/`--max`. So a
  short headline stays one huge line, and one that has to wrap gets two smaller lines instead of spilling. Layouts
  set only their box (`--bw`/`--bh`), `--min`/`--max`, and their own letter-spacing (`--ls`) and line-height
  (`--lh`). There are no tuning constants any more.

Templates: `resources/views/marketing/ads/{gallery,vault,poster}/<layout>.blade.php`, sharing
`partials/{brand,headline,photo}.blade.php`. The styles are ported from `docs/mockups/MK-5/option-{a,b,c}.html`
(letters A/B/C in the CSS class names).

**5. The browser.** `app/Services/Marketing/AdRenderer.php` (bound to `AdRendererClient` in `AppServiceProvider`)
writes each page to `storage/app/private/ad-render/<uuid>/` and runs
`node bin/render-ad.mjs <in.html> <out.png> <w> <h> …` with fixed arguments (no shell string), within
`TIMEOUT_SECONDS` (60). The script launches Chromium once and aborts every request that is not `file:` or `data:`.
It waits for `document.fonts.ready`, screenshots each page at its exact size and prints `{"overflow":[…]}`. A page
counts as overflowing when any of its words (`.fit`, `.sub`, `.cta`, `.pill`) overflow their own box, or are drawn
past any clipping ancestor, the ad's own edge included. `collect()` checks that each PNG is exactly the size asked for. The temp directory is
always removed.

**6. Storing.** `app/Actions/Marketing/StorePostRenders.php:handle($post, $pngs)` stores each PNG through
`StoreMarketingAsset` as a `MarketingAsset` with `purpose = render`, named `post-<id>-1x1.png` / `-4x5.png`. If either
write fails, both new rows are deleted, so a post never has half a render. It then re-reads the post, points
`ad.render` at the new ids and deletes the old render assets and their files. That delete is limited to this
project's `purpose = render` rows, so a stray id can never delete a photo. `failed($post, $reason)` marks the post
failed and keeps any previous images.

**7. Showing it.**
- **Studio** (`resources/views/livewire/marketing/post-studio.blade.php`): once both ids exist, the rendered image
  replaces `marketing/post-preview`. A 1:1 / 4:5 switch is Alpine (`ratio`), with "Download 1:1" and "Download 4:5"
  links. Before the first render it shows the HTML preview with "Rendering the ad…". A re-render keeps the old image
  under a "Rendering…" badge. A failure shows "Couldn't render · Retry", and a post never rendered shows "No ad image
  yet · Render it". The studio polls every 2 s while any shown post is rendering. Approved and missed posts download
  the ad rather than the bare photo.
- **Download**: `app/Http/Controllers/MarketingRenderController.php`, route `marketing.render`
  (`/p/{project}/marketing/posts/{post}/ad/{size}`). It serves the PNG as `<brand>-<local date>-1x1.png` / `-4x5.png`.
- **Library cards and the calendar's quick peek** show the 1:1 render through the existing `marketing.asset` route,
  and the HTML preview until there is one.
- **Campaign form** (`campaign-page.blade.php`): an "Ad style" select. `SaveCampaign` stores gallery as null, so an
  untouched form never reads as changed.

## Data model
Two additive migrations, with no data rewrite:
- `2026_10_13_000001_add_ad_to_marketing_posts_table`: nullable JSON `marketing_posts.ad` =
  `{layout, headline, subline, cta, asset_id, crop, badge, render: {status, square, portrait, reason, at}}`
  ([ADR-094](../decisions/ADR-094-marketing-render-state-lives-on-the-post.md)).
- `2026_10_13_000002_add_ad_style_to_marketing_campaigns_table`: nullable `marketing_campaigns.ad_style`
  (`gallery | vault | poster`; null reads as gallery).

`marketing_assets.purpose` gains `render` (string column, no schema change). Files live under
`marketing/{project}/renders/` on the `local` disk.

Model additions:
- `MarketingPost`: `AD_LAYOUTS`, `PHOTO_LAYOUTS`, `AD_SPEC_KEYS`, `RENDER_SIZES`, `RENDER_*` states,
  `RENDER_STALE_MINUTES` (10); `adSpec()`, `renderState()` (a `rendering` older than 10 min reads as `failed`),
  `renderId($size)`.
- `MarketingCampaign`: `AD_STYLE_*`, `AD_STYLES`, `adStyle()`.
- `MarketingAsset`: `PURPOSE_RENDER`.

## Interfaces
- `QueuePostRender::handle(MarketingPost, bool $respec = true): bool` (false = skipped).
- `BuildDefaultAdSpec::handle(MarketingPost): array`. `BuildAdHtml::handle($post, $spec, $size): string`,
  `layoutFor($post, $spec)`, `MAX_HEADLINE`, `CROPS`.
- `AdRendererClient::render(array $pages): array<key, {png, overflow}>`, which throws `MarketingRenderException`
  with `reason` `timeout | process_failed | invalid_output`.
- `StorePostRenders::handle($post, $pngs)`, `failed($post, $reason)`, `LABELS`.
- `QueueMissingRenders::handle(string $projectName): int`, which throws `MarketingKitException` for an unknown
  project.
- Route `marketing.render` (GET, under `EnsureProjectIsShown`). Every refusal is a 404.
- Livewire: `PostStudio::retryRender($id)` (the post must be one the studio is showing); `CampaignPage::$adStyle`.
- Command: `marketing:render {project}`.
- Ad spec (for MK-19): `layout` ∈ `AD_LAYOUTS`; `headline` (cut to `MAX_HEADLINE`); `subline`; `cta`; `asset_id` (a
  photo of the same project); `crop` ∈ `CROPS`; `badge` (≤ 24 chars, coin spotlight).
- Test hooks: `data-ad-render`, `data-render-bar`, `data-ratio`, `data-download-ad`, `data-rendering`,
  `data-render-failed`, `data-retry-render`, `data-render-now`, `data-library-render`, `data-peek-image`,
  `data-ad-style-select`; on the ad page, `data-ad-style` and `data-ad-layout`.

## Configuration
No new config: the timeout and the binary are constants in `AdRenderer` (`config/` was out of bounds).
- **Node** must be on the queue worker's PATH (`NODE_BINARY = 'node'`), and the Playwright Chromium must be
  installed (`npx playwright install chromium`). `playwright` moved to `dependencies` in `package.json`.
- **Fonts** come from the gitignored `public/build/fonts-manifest.json`. A host that has not run the Vite build
  (`npm run build`) renders in Helvetica/Arial, with no error.
- A queue worker. On the sync queue, renders run inline, at about 0.6 s per post.

## Observability
- `marketing.render.queued` (info: post_id, campaign_id, layout).
- `marketing.render.skipped` (info: `not_renderable`, `needs_photo`, or `gone` for a post deleted before the job
  ran).
- `marketing.render.succeeded` (info: post_id, campaign_id, layout, `ms`, `bytes`, `overflow`). `overflow: true`
  means a headline, subline or CTA was clipped despite the CSS fit. Look first at the brand font (the glyph table
  is measured for Instrument Sans only), then at the layout's box (`--bw`/`--bh`) or `MAX_HEADLINE`.
- `marketing.render.failed` (warning: post_id, layout, `reason`, `message`). `timeout`, `process_failed` (message =
  the script's or Node's stderr line, e.g. Chromium not installed, `node` not found) and `invalid_output` come from
  the renderer. `exception` is anything else, and that one is also rethrown to the queue.
- `marketing.render.refused` (warning: project, post_id, size, reason `unknown_size | unknown_post | not_rendered |
  missing_file`) from Download.
- `marketing.render.backfill_queued` / `backfill_refused`.
- The job restores the originating `request_id`, so a render traces back to the Fill, Revise or click that asked
  for it.
- A healthy trace is `queued`, then `succeeded` with `ms` around 600. A post stuck on "Rendering…" means no worker;
  after 10 minutes it shows Retry. Ads in the wrong font mean the Vite build is missing on that host.

## Testing & verification
- `tests/Feature/Marketing/AdRendererTest.php`: one `it()` per acceptance criterion (named as in the story), plus
  style choice, the default spec, the photo-gone and cross-project fallbacks, skip reasons, re-render triggers,
  approve-once, Download refusals, library and peek images, and the campaign form. The renderer is
  `Tests\Support\FakeAdRenderer`, bound for every test in `tests/TestCase.php`, because renders run inline on the
  sync queue after Fill, Revise and photo changes.
- `tests/Browser/Marketing/AdRendererTest.php`: the real renderer, which writes an exactly 1080×1080 PNG. Every
  style × layout × size renders a set of worst-case headlines at or past the cap with no overflow. The set covers
  long prose, long words, all capitals and short multi-word headlines that wrap at a large size, among them
  "61 down, 36 to go.", the stat that broke. the studio at 375 px has no sideways scroll; a failing script becomes
  `process_failed`.
- `tests/Browser/Journeys/MarketingPostTest.php` step 8: Fill "Ask the community" (AI-decides photos, so the drafts
  render at once), and the studio shows the render. Both Download links serve PNGs of the right size.
- Browser check (coins): Fill a date, see the rendered drafts, download both sizes, open the PNGs; repeat at 375 px.

## Key decisions & tradeoffs
- All three mockup families shipped as per-campaign ad styles over the same five layouts →
  [ADR-092](../decisions/ADR-092-marketing-three-ad-styles-chosen-per-campaign.md).
- Headless Chromium through a Node script over `file://`, not an AI image generator and not a route →
  [ADR-093](../decisions/ADR-093-marketing-ads-rendered-by-headless-chromium-over-file-urls.md).
- Spec and render state on the post in `ad`, not in the cache →
  [ADR-094](../decisions/ADR-094-marketing-render-state-lives-on-the-post.md).
- Headline fit is pure CSS (no JS in the ad) plus a per-layout character cap with "…". The cap is what guarantees
  no mid-line clip, and the script's overflow report is the alarm if the CSS ever loses. Since `b0d4b00` the CSS
  sizes from measured glyph widths and real line breaks, not from an even spread of characters. That spread model
  clipped wrapped stat headlines (RUNBOOK 2026-10-13).
- Vault's bleed photo and the spotlight photo are round: square crops showed coin photos' white backgrounds.
- Re-renders also follow caption edits, Undo and new one-off posts, beyond the story's list; otherwise the image
  goes stale against the caption.

## Known limitations & gotchas
- **Changing a campaign's ad style does not re-render existing posts.** New renders use it. The form's hint says so.
- **Drafts discarded by Approve or Regenerate keep their PNG files and render rows**, so the disk grows. Fixing it
  needs a change to approve behaviour, which this story could not touch. That is the owner's call.
- Drafts waiting for a photo are not rendered until they get one; the studio shows "Needs a photo" instead.
- **The glyph table (`BuildAdHtml::GLYPH_EMS`) is measured for Instrument Sans only.** A brand font that is much
  wider can still overflow, and `overflow: true` on `marketing.render.succeeded` would show it. If brands start
  using other fonts, re-measure the table or measure per font.
- **The 14-character stat cap cuts prose headlines with "…"**, and that can lose the meaning ("61 down, 36 to…").
  Claude is told the cap in its prompt, but a stat written as a sentence still gets cut. The cap itself did not
  change in `b0d4b00`, and `NormaliseAdSpec`'s `min(60, cap)` still holds.
- `--chars` is still printed, but now only for the letter-spacing term (`--ls × chars`) in `.fit`.
- `soft` is derived from the accent (22% into white) until F-19 adds real brand colour fields.
- `ad.render` holds only the latest render. A re-render keeps showing the old image until the new one is stored.
- Blade gotchas when editing the templates (RUNBOOK 2026-10-13): a directive written straight after another
  (`@endif@include`) is not parsed, and CSS `@`-rules must stay in the `.css` files, not in Blade.
- Every new style's CSS relies on `box-sizing: border-box`, which `base.css` sets globally. The mockups got it from
  page chrome that was not ported.
- `package-lock.json` was hand-edited (npm offline): only the `dev` flags and the dependency move. A later
  `npm install` may reshuffle it.

## Change history
2026-10-13 — Ad renderer: five layouts in three per-campaign styles, rendered to 1:1 and 4:5 PNGs by headless
Chromium on the queue; studio preview, ratio switch, Download, Retry; library and calendar thumbnails;
`marketing:render` backfill (MK-5, `6a9beb3`)
2026-10-13 — Specs now come from Claude's art direction; re-specs read `generation_meta.ad` through `NormaliseAdSpec`
(MK-19, `c21a4d7`)
2026-10-13 — Fix: the headline fit now uses measured glyph widths and real line breaks, so a wrapped stat headline is
no longer clipped. The overflow check now also covers the subline and CTA and any clipping ancestor (MK-5, `b0d4b00`)
