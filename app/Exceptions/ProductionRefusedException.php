<?php

namespace App\Exceptions;

use App\Services\Production\ConnectionCheck;
use RuntimeException;

/**
 * A production connection was not opened, or not stored, because it is
 * unreachable, failed, or not proven read-only (SB-17). Carries the full
 * ConnectionCheck so the page can show what was found, not only the refusal.
 */
class ProductionRefusedException extends RuntimeException
{
    /**
     * @param  ConnectionCheck  $check  what the attempt found; its message is already redacted
     */
    public function __construct(public readonly ConnectionCheck $check)
    {
        parent::__construct($check->message);
    }
}
