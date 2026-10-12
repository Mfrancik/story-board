<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Cache;

/**
 * Which campaign dates are being drafted on the queue, or failed to draft (MK-8), so the campaign page can show
 * "Drafting…" (and poll) or "Drafting failed · Retry". Kept in the cache, not the database: it is transient by
 * nature, and a lost entry only means the date reads as open again. Each entry expires, so a job that never ran
 * (no worker) cannot leave a date stuck on "Drafting…".
 */
class DraftingStatus
{
    /** A date with a draft job queued or running. */
    public const DRAFTING = 'drafting';

    /** A date whose last draft attempt failed. */
    public const FAILED = 'failed';

    /** Longest a date shows "Drafting…": well past a job's HTTP timeout. */
    public const DRAFTING_TTL = 600;

    /** How long a failure stays on the page before the date reads as plain open again. */
    public const FAILED_TTL = 86400;

    /**
     * Mark `$date` (Y-m-d in the campaign's timezone) of campaign `$campaignId` as being drafted.
     */
    public function drafting(int $campaignId, string $date): void
    {
        Cache::put($this->key($campaignId, $date), self::DRAFTING, self::DRAFTING_TTL);
    }

    /**
     * Mark the date's last draft attempt as failed.
     */
    public function failed(int $campaignId, string $date): void
    {
        Cache::put($this->key($campaignId, $date), self::FAILED, self::FAILED_TTL);
    }

    /**
     * Forget the date: it has drafts now, or was refused and is whatever its posts say.
     */
    public function clear(int $campaignId, string $date): void
    {
        Cache::forget($this->key($campaignId, $date));
    }

    /**
     * The state of each of `$dates` that has one, keyed by date.
     *
     * @param  list<string>  $dates
     * @return array<string, string>
     */
    public function states(int $campaignId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $keys = array_map(fn (string $d) => $this->key($campaignId, $d), $dates);
        $found = Cache::many($keys);

        $out = [];
        foreach ($dates as $i => $date) {
            $state = $found[$keys[$i]] ?? null;
            if ($state === self::DRAFTING || $state === self::FAILED) {
                $out[$date] = $state;
            }
        }

        return $out;
    }

    /**
     * The cache key for one campaign date.
     */
    private function key(int $campaignId, string $date): string
    {
        return "marketing.drafting.{$campaignId}.{$date}";
    }
}
