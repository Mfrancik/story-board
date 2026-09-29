<?php

namespace App\Services\Production;

/**
 * One metric's value read from production, or why there is none (SB-17). The
 * message is already redacted; the value is never logged.
 */
final readonly class MetricReading
{
    /** One number came back. */
    public const OK = 'ok';

    /** Refused by the board before or after it ran: not one SELECT, not one number, no connection. */
    public const REFUSED = 'refused';

    /** Stopped by the 5 s limit. */
    public const TIMED_OUT = 'timed_out';

    /** The connection or the query failed; `message` says how. */
    public const FAILED = 'failed';

    /**
     * @param  self::OK|self::REFUSED|self::TIMED_OUT|self::FAILED  $status
     * @param  string|null  $reason  stable, logged: sql_refused, bad_name, incomplete, not_one_number, timed_out, query_failed, or a ConnectionCheck reason
     * @param  string|null  $sql  the statement as run, bindings inlined, for "Show SQL"
     */
    public function __construct(
        public string $status,
        public int|float|null $value = null,
        public ?string $message = null,
        public ?string $reason = null,
        public int $ms = 0,
        public ?string $sql = null,
    ) {}

    /**
     * Whether a number was read.
     */
    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * A metric that could not run because the connection did not open read-only.
     */
    public static function fromCheck(ConnectionCheck $check): self
    {
        return new self($check->status === ConnectionCheck::UNREACHABLE ? self::FAILED : self::REFUSED,
            message: $check->message, reason: $check->reason, ms: $check->ms);
    }

    /**
     * The reading as plain data, for a Livewire property and the view.
     *
     * @return array{status: string, value: int|float|null, message: string|null, reason: string|null, ms: int, sql: string|null}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'value' => $this->value, 'message' => $this->message,
            'reason' => $this->reason, 'ms' => $this->ms, 'sql' => $this->sql];
    }
}
