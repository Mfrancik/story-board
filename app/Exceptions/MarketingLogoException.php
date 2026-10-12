<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A brand logo cannot be used: the file is missing at the ref, the ref is malformed, or the text holds no
 * well-formed SVG. The message is written for the owner and shown under the field; the brand is left unchanged
 * and `marketing.brand.logo_refused` is logged.
 */
class MarketingLogoException extends RuntimeException
{
    /**
     * @param  string  $field  the Settings field the message belongs under (`logoPath` or `logoRef`)
     * @param  string  $reason  a stable key for the log line (`not_found`, `bad_ref`, `not_svg`, …)
     */
    public function __construct(string $message, public readonly string $field = 'logoPath', public readonly string $reason = 'not_svg')
    {
        parent::__construct($message);
    }
}
