<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Fill, Regenerate or Revise was refused before any call to Claude (MK-8): the date is not open, the campaign is
 * paused, no API key is set, the post is not a draft, or the feedback is empty or names an unknown chip. The Action
 * logs the refusal with `reason`; the page shows the message.
 */
class MarketingDraftRefusedException extends RuntimeException
{
    /**
     * @param  string  $reason  a stable key for the log line (`not_configured`, `paused`, `not_open`, `not_draft`, `empty_feedback`, `unknown_chip`)
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
