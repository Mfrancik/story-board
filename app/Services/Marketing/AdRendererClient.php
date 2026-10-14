<?php

namespace App\Services\Marketing;

use App\Exceptions\MarketingRenderException;

/**
 * What RenderPostAd needs to turn an ad's HTML into PNGs (MK-5). Bound to AdRenderer (headless Chromium through
 * bin/render-ad.mjs); tests bind Tests\Support\FakeAdRenderer, so only the Browser suite ever starts a browser.
 */
interface AdRendererClient
{
    /**
     * Render each page's self-contained HTML at its width × height and return the PNG bytes by the same keys, with
     * whether a headline overflowed its box (`overflow`, for the log; the CSS should never let it).
     *
     * Side effects: runs a browser process; writes and removes temporary files.
     *
     * @param  array<string, array{html: string, width: int, height: int}>  $pages
     * @return array<string, array{png: string, overflow: bool}>
     *
     * @throws MarketingRenderException when the browser fails, times out, or writes no image
     */
    public function render(array $pages): array;
}
