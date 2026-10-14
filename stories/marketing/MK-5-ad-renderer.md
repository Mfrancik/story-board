# MK-5 — Ad renderer: every post becomes an on-brand image
Status: approved       Journey: marketing-post (step 8 of 10)
Source: owner 2026-10-13: "i want to get this to actually generate and render ads for me to sift through and pick and
choose"; phase 3 batch 1 approved ("approve all"); renderer: reuse Playwright (owner pick)

## Story
As the owner, I want every draft and approved post rendered as a finished, on-brand ad image, so that I judge and post
real ads instead of a caption plus a loose photo.

## Why
Today a post is a caption and (maybe) a photo; text-only posts cannot be downloaded at all. Rendering brand templates
in a headless browser gives crisp, correctly spelled, always-on-brand images with no new paid service (decided over an
AI image generator, owner 2026-10-13).

## In scope
- **Layouts** as Blade templates (pure HTML/CSS, no JS), driven by `MarketingBrand` values (accent, ink, surface, soft,
  font, radius, shadow, logo_svg) and an **ad spec**: `{layout, headline, subline, cta, asset_id, crop}`. 4–6 layouts
  picked at the mockup gate, from directions such as: photo + headline, bold text card, question/quote, coin spotlight
  (one photo with a badge), stat/number. Based on the kit designs in `docs/mockups/MK-kit/posts/`.
- **Two sizes**: 1080×1080 (1:1) and 1080×1350 (4:5).
- **Renderer**: a small Node script (`bin/render-ad.mjs`) driving the already-installed Playwright Chromium; Playwright
  moves from `devDependencies` to `dependencies` (owner-approved). PHP calls it through one service
  (`AdRenderer`) with a timeout; the HTML is written to a temp file — no route, no network, `file://` only.
- **Job** `RenderPostAd` (queued, unique per post): renders both sizes, stores each as a `MarketingAsset` with
  `purpose=render` (new purpose constant; column is a string, no schema change for it), replaces the post's previous
  renders and deletes their files.
- **Default ad spec** (until MK-19's art director fills it): photo post → photo+headline layout; text-only post → text
  card; headline = caption's first line, subline = the rest trimmed. Stored on the post.
- **When it renders**: after a draft is written (Fill), after Revise/Rebuild, after a photo change, and on approve if
  no render exists. A backfill command `marketing:render {project}` renders existing drafts/approved posts once.
- **Studio**: the preview shows the rendered image when present (with "Rendering…" until done and "Couldn't render ·
  Retry" on failure); 1:1 / 4:5 switch; **Download** offers both sizes for every post, text-only included.
- Library and calendar cards show the 1:1 render when present.

## Out of scope (do NOT build)
- Claude choosing layout/headline/crop (MK-19), the review inbox (MK-20), editing the ad by hand (MK-4 designer).
- Coin-data layouts that need inventory (typeset grid, stat stack with live values) — MK-17.
- Stories size (9:16), video, animation, AI image generation.
- Retiring a page or feature? None retired. The HTML `marketing/post-preview` text card stays as the fallback while a
  render is pending or failed.

## Acceptance criteria (executable — these become the Pest test names)
- Given a draft with a photo, when it is written, then a `RenderPostAd` job is queued, and running it (renderer faked)
  stores two `render` assets (1080×1080, 1080×1350) linked to the post.
- Given a text-only post, when rendered, then it uses the text-card layout and Download offers both PNGs.
- Given each layout and brand values, when its HTML is built, then it contains the brand's colours, font and logo and
  the spec's headline, and no external URL (rendered offline).
- Given the real renderer (one browser test), when a photo+headline ad renders, then a valid PNG of exactly 1080×1080
  is written.
- Given a re-render, then the old render assets and their files are removed and exactly two remain.
- Given the renderer fails or times out, then the post keeps the HTML preview, the studio shows "Couldn't render ·
  Retry", `marketing.render.failed` is logged, and Retry re-queues.
- Given a headline too long for a layout, then the text shrinks to fit within the layout's bounds (no overflow).
- Given the backfill runs twice, then each draft/approved post without a render gets exactly one job.
- Given another project's post id, when Download or Retry is requested, then it is refused (404) and logged.
- Given a 375px viewport, then the studio's rendered preview fits with no sideways scroll.

## Applicable standards
- Design: layouts from the chosen mockup; tokens only in the app UI (layouts use brand values, not app tokens); reuse
  `marketing/post-preview` as the pending/failed fallback.
- Codebase/DB: Actions + one `AdRenderer` service; the job never calls Claude; `MarketingAsset::PURPOSE_RENDER`;
  ad spec stored on the post (additive nullable JSON column `ad` on `marketing_posts` — one migration, no data
  rewrite). Process call with fixed arguments, no shell string.
- Logging: `marketing.render.queued`, `marketing.render.succeeded` (post, layout, ms, bytes),
  `marketing.render.failed` (warning: post, layout, reason), `marketing.render.refused`.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-5/option-{a,b,c}.html — three layout families (each showing its 4–6 layouts at 1:1 and 4:5
  with real coins data and the StackerMint brand)
- Chosen option: **all three** — A Gallery card, B Vault, C Poster blocks, as three ad *styles* of the same 5 layouts
  (photo + headline, bold text card, question, coin spotlight, stat) = 15 templates. Style chosen per campaign:
  nullable `ad_style` on `marketing_campaigns` (`gallery|vault|poster`, null = gallery); one-off posts use gallery;
  a "Ad style" picker on the campaign form. Spec gains optional `badge` (≤ 24 chars, coin spotlight). `soft` is
  derived from the accent (F-19 will make it a real brand field).
- Why I chose it: owner 2026-10-13: "all of these are fine, we can use every design here they're all great and fit
  the brand. we could use different ones for difference campaigns"; style per campaign setting (owner pick); badge in
  the spec (owner pick); soft colour → F-19.

## Do NOT touch
- `config/` (renderer timeout and binary path are constants in `AdRenderer`; ask before adding config); MK-8's prompt
  and taste logic (MK-19's job); the studio's approve/flip behaviour.

## Data & interfaces
- Schema: migration adding nullable JSON `ad` to `marketing_posts`. `marketing_assets.purpose` gains `render`.
- Added: `bin/render-ad.mjs`, `App\Services\Marketing\AdRenderer`, `App\Jobs\RenderPostAd`, Actions
  `BuildDefaultAdSpec`, `StorePostRenders`; Blade `resources/views/marketing/ads/<layout>.blade.php`; command
  `marketing:render`. `package.json`: playwright → dependencies.

## Test plan
- Pest: failing tests first; renderer faked (bound interface) except one Browser-suite test that renders for real.
- Journey test: step 8 — after Fill, the studio shows a rendered PNG and Download offers 1:1 and 4:5.
- Browser check: coins — Fill a date, see three rendered drafts, download both sizes, open the PNGs; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-2, MK-8, MK-9
