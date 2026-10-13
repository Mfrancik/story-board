<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A studio action on a post or date was refused (MK-9): approving a post that is not a draft or still needs its
 * photo, rescheduling into the past, skipping a decided date, an unknown dislike reason, and so on. The Action logs
 * the refusal with `reason`; the studio shows the message.
 */
class MarketingPostRefusedException extends RuntimeException
{
    /**
     * @param  string  $reason  a stable key for the log line (`not_draft`, `needs_photo`, `not_approved`, `past`, `decided`, `not_skipped`, `unknown_reason`, `bad_vote`, `empty_caption`, `not_editable`, `no_version`)
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
