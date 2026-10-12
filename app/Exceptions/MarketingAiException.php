<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A call to Claude for marketing copy (MK-8) did not give usable drafts: no API key, an HTTP error, a timeout, a
 * refusal, or a reply that is not the JSON asked for. Nothing has been written when it is thrown; the caller logs
 * `marketing.ai.draft.failed` or `marketing.ai.revise.failed` with the safe fields here (never the key or prompt).
 */
class MarketingAiException extends RuntimeException
{
    /**
     * @param  string  $reason  a stable key for logs and the UI: `not_configured`, `timeout`, `http_error`, `refusal`, `truncated`, `invalid_reply`
     * @param  int|null  $status  the HTTP status Claude answered with, null when it never answered
     * @param  int  $durationMs  how long the call took, 0 when none was made
     * @param  string|null  $errorType  Anthropic's `error.type` (e.g. `overloaded_error`), a safe summary of the reply
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly int $durationMs = 0,
        public readonly ?string $errorType = null,
    ) {
        parent::__construct($message);
    }
}
