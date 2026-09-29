<?php

namespace App\Services\Production;

/**
 * What ProductionReader found when it opened a production connection (SB-17):
 * reachable or not, the privilege check, the MySQL version, SSL and the time
 * taken. Every message in it is already redacted, so it is safe to render and log.
 */
final readonly class ConnectionCheck
{
    /** Reachable, and SHOW GRANTS proved the user SELECT-only. */
    public const OK = 'ok';

    /** Reachable, but the user can do more than SELECT. */
    public const REFUSED = 'refused';

    /** No answer from the host within the connect timeout. */
    public const UNREACHABLE = 'unreachable';

    /** The server answered but the connection failed (bad password, no such database, …). */
    public const FAILED = 'failed';

    /**
     * @param  self::OK|self::REFUSED|self::UNREACHABLE|self::FAILED  $status
     * @param  string|null  $reason  stable, logged: can_write, more_than_select, unreachable, access_denied, no_database, bad_target, failed
     * @param  string  $message  the owner-facing sentence
     * @param  string|null  $privilege  for a refusal, the first privilege beyond SELECT
     * @param  list<string>  $grants  SHOW GRANTS, one line each
     * @param  string|null  $detail  the driver's own error, redacted
     */
    public function __construct(
        public string $status,
        public ?string $reason,
        public string $message,
        public ?string $privilege = null,
        public array $grants = [],
        public ?string $version = null,
        public bool $ssl = false,
        public int $ms = 0,
        public string $target = '',
        public ?string $detail = null,
    ) {}

    /**
     * Whether the connection is usable: reachable and proven read-only.
     */
    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * The check as plain data, for a Livewire property and the view.
     *
     * @return array{status: string, reason: string|null, message: string, privilege: string|null, grants: list<string>, version: string|null, ssl: bool, ms: int, target: string, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status, 'reason' => $this->reason, 'message' => $this->message, 'privilege' => $this->privilege,
            'grants' => $this->grants, 'version' => $this->version, 'ssl' => $this->ssl, 'ms' => $this->ms,
            'target' => $this->target, 'detail' => $this->detail,
        ];
    }
}
