<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Rendering a post's ad to PNG (MK-5) did not give two images: Chromium did not start, crashed, ran past the
 * timeout, or wrote no file. Nothing has been stored when it is thrown; RenderPostAd logs `marketing.render.failed`
 * with the reason and the studio offers Retry.
 */
class MarketingRenderException extends RuntimeException
{
    /**
     * @param  string  $reason  a stable key for logs: `timeout`, `process_failed`, `invalid_output`
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
