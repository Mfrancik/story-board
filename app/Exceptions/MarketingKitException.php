<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A marketing console command cannot run: `marketing:seed-kit` (the project is unknown, or the kit folder or its
 * captions file is missing; logs `marketing.kit_seed.failed`) or `marketing:read-favourites` (unknown project, MK-18;
 * logs `marketing.photo.backfill_refused`). Thrown before anything is written; the command prints the message.
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
