<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * A campaign's dates (MK-1): from its weekdays, wall-clock time and timezone, the
 * next N occurrences from a moment. Times are built in the campaign's own timezone,
 * so "Tue 19:00" stays 19:00 local across daylight-saving changes. Reads only.
 */
class CampaignSchedule
{
    /**
     * The next `$count` occurrences at or after `$from`, in the campaign's timezone,
     * earliest first. A campaign with no days has none.
     *
     * @return list<CarbonImmutable>
     *
     * @throws CampaignScheduleException when the timezone, days or time cannot be read
     */
    public function handle(MarketingCampaign $campaign, CarbonInterface $from, int $count): array
    {
        $zone = $this->zone($campaign);
        $days = $this->days($campaign);
        [$hour, $minute] = $this->time($campaign);
        if ($days === [] || $count < 1) {
            return [];
        }

        $start = CarbonImmutable::instance($from)->setTimezone($zone);
        $out = [];
        // Each day yields at most one occurrence, so `$count` weeks plus one always reach `$count` dates.
        for ($i = 0; count($out) < $count && $i <= 7 * ($count + 1); $i++) {
            $day = $start->startOfDay()->addDays($i);
            if (! in_array($day->dayOfWeekIso, $days, true)) {
                continue;
            }
            $at = $day->setTime($hour, $minute);
            if ($at->greaterThanOrEqualTo($start)) {
                $out[] = $at;
            }
        }

        return $out;
    }

    /**
     * The campaign's timezone.
     *
     * @throws CampaignScheduleException when it is not a real timezone
     */
    public function zone(MarketingCampaign $campaign): DateTimeZone
    {
        try {
            return new DateTimeZone($campaign->timezone);
        } catch (Throwable) {
            throw new CampaignScheduleException("Unknown timezone \"{$campaign->timezone}\".");
        }
    }

    /**
     * The campaign's ISO weekdays.
     *
     * @return list<int>
     *
     * @throws CampaignScheduleException for a value that is not a weekday 1–7
     */
    private function days(MarketingCampaign $campaign): array
    {
        $days = [];
        foreach ($campaign->days as $day) {
            if (! is_int($day) || $day < 1 || $day > 7) {
                throw new CampaignScheduleException('Days must be ISO weekdays 1–7, got '.json_encode($campaign->days).'.');
            }
            $days[] = $day;
        }

        return $days;
    }

    /**
     * The campaign's wall-clock hour and minute.
     *
     * @return array{int, int}
     *
     * @throws CampaignScheduleException for a time that is not HH:MM[:SS]
     */
    private function time(MarketingCampaign $campaign): array
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', (string) $campaign->time, $m)) {
            throw new CampaignScheduleException("Unreadable time \"{$campaign->time}\".");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
