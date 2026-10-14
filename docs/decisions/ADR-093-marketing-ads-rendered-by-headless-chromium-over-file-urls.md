# ADR-093 — Ads are rendered by headless Chromium from self-contained HTML over file://

Date: 2026-10-13 · Status: accepted · Story: MK-5

## Context

Posts must become finished ad images at 1080×1080 and 1080×1350. The output must be crisp and correctly spelled,
exactly on-brand (colours, font, logo), and free to produce at volume, because every draft and every rewrite renders
again. Playwright and its Chromium were already installed for the Browser test suite.

## Decision

- **Blade templates + headless Chromium**, not an AI image generator. `app/Services/Marketing/AdRenderer.php` runs
  `node bin/render-ad.mjs` with fixed process arguments (never a shell string) and a 60 s timeout. The script
  starts one browser per post and screenshots each size.
- **No route, no network.** `BuildAdHtml` inlines everything into one page: the CSS, the brand's fonts as base64
  from the app's own Vite build, the photo as a data URL and the sanitised logo SVG. `AdRenderer` writes the page to
  a private temp directory and opens it over `file://`. The script aborts every request that is not `file:` or
  `data:`.
- **No JS in the ad.** Headline sizing is a CSS `clamp(sqrt(area / chars))` rule plus a per-layout character cap.
  The script reports any overflow instead of fixing it.
- **Behind an interface.** `AdRendererClient` is bound to `AdRenderer`. `Tests\TestCase` binds
  `Tests\Support\FakeAdRenderer` for every test, so only `tests/Browser/Marketing/AdRendererTest.php` starts
  Chromium.
- `playwright` moved from `devDependencies` to `dependencies`, because production now needs it.

Alternatives rejected:
- **AI image generation.** It costs per image, misspells text, and drifts off-brand. The owner decided against it.
- **Render a board route** (Chromium visiting `http://…/ad/{post}`). That needs a running server reachable from the
  worker, plus auth and routing for an internal page. The page could also fetch anything.
- **A PHP image library (GD/Imagick).** Layout, wrapping and web fonts would have to be reimplemented by hand, with
  no path back to the HTML mockups.

## Consequences

- The queue worker needs `node` on its PATH and the Playwright Chromium installed. A missing one shows up as
  `marketing.render.failed` with reason `process_failed`.
- A host without the Vite build (`public/build/fonts-manifest.json`) silently renders in Helvetica/Arial.
- A render takes about 0.6 s and one Chromium start per post.
