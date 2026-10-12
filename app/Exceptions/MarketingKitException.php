<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `marketing:seed-kit` cannot seed: the project is unknown, or the kit folder or its captions file is missing.
 * Thrown before anything is written; the command prints the message and logs `marketing.kit_seed.failed`.
 */
class MarketingKitException extends RuntimeException
{
    /**
     * @param  string  $reason  a stable key for the log line (`unknown_project`, `missing_kit`, `missing_captions`)
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
