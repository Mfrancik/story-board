<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * "Save metrics" refused one metric (SB-17). Carries the form field the message
 * belongs under and a stable reason for the log.
 */
class ProdMetricsRefusedException extends RuntimeException
{
    /**
     * @param  string  $field  the Livewire field, e.g. `customs.2.sql` or `presets.new_today.table`
     * @param  string  $reason  logged as `reason`: no_connection, no_label, sql_refused, bad_name, incomplete
     * @param  string  $message  the owner-facing sentence
     */
    public function __construct(public readonly string $field, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
