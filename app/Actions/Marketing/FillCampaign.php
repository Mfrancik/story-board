<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Jobs\DraftOccurrenceJob;
use App\Models\MarketingCampaign;
use App\Services\Marketing\DraftingStatus;
use App\Services\Marketing\MarketingAiClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Fill on the campaign page (MK-8): queues one DraftOccurrenceJob per open date, so each date fills or fails on its
 * own. "Fill N open dates" takes every open date in the next WINDOW_DAYS days (the convention); a row's Fill takes
 * that one date, which must be an open date on the page's timeline. A date already being drafted is not queued
 * twice. Nothing is drafted here; the jobs do that.
 */
class FillCampaign
{
    /** "Fill N open dates" looks this many days ahead. */
    public const WINDOW_DAYS = 14;

    /** Occurrences read for the window: one a day at most, so a fortnight's worth plus today. */
    private const WINDOW_OCCURRENCES = self::WINDOW_DAYS + 1;

    /**
     * @param  ListCampaignOccurrences  $occurrences  the timeline the dates and their states come from
     * @param  DraftingStatus  $status  which dates are already being drafted
     * @param  MarketingAiClient  $ai  whether a key is set
     */
    public function __construct(
        private ListCampaignOccurrences $occurrences,
        private DraftingStatus $status,
        private MarketingAiClient $ai,
    ) {}

    /**
     * Queue drafting for `$campaign`'s open dates in the next WINDOW_DAYS, or only for `$date` (Y-m-d, the
     * campaign's timezone). Returns the dates queued.
     *
     * Side effects: marks each date drafting, dispatches a job per date, logs `marketing.ai.fill.queued`; a refusal
     * logs `marketing.ai.fill.refused`.
     *
     * @return list<string>
     *
     * @throws MarketingDraftRefusedException when no key is set, the campaign is paused, or `$date` is not an open date on its page
     * @throws CampaignScheduleException when the campaign's schedule cannot be read
     */
    public function handle(MarketingCampaign $campaign, CarbonInterface $now, ?string $date = null): array
    {
        if (! $this->ai->isConfigured()) {
            $this->refuse($campaign, 'not_configured', $date, 'Add ANTHROPIC_API_KEY to .env to draft with AI.');
        }
        if (! $campaign->is_enabled) {
            $this->refuse($campaign, 'paused', $date, 'This campaign is paused. Turn it on to fill its dates.');
        }

        if ($date === null) {
            $targets = $this->openDates($campaign, $now);
        } else {
            // A row's Fill: only a date the page shows as open, which also rejects a tampered or malformed date.
            $targets = array_values(array_filter($this->open($campaign, $now, ListCampaignOccurrences::COUNT), fn (CarbonImmutable $at) => $at->toDateString() === $date));
            if ($targets === []) {
                $this->refuse($campaign, 'not_open', $date, 'That date already has posts, or is not one of this campaign’s dates.');
            }
        }

        $states = $this->status->states($campaign->id, array_map(fn (CarbonImmutable $at) => $at->toDateString(), $targets));
        $queued = [];
        foreach ($targets as $at) {
            $day = $at->toDateString();
            if (($states[$day] ?? null) === DraftingStatus::DRAFTING) {
                continue;
            }
            // Marked before dispatch: on the sync queue the job has finished (and cleared it) by the time dispatch returns.
            $this->status->drafting($campaign->id, $day);
            DraftOccurrenceJob::dispatch($campaign, $at->utc()->toIso8601String(), $day);
            $queued[] = $day;
        }

        Log::info('marketing.ai.fill.queued', ['campaign_id' => $campaign->id, 'dates' => $queued, 'count' => count($queued)]);

        return $queued;
    }

    /**
     * The open dates "Fill N open dates" would queue: on the schedule, no posts, from now to WINDOW_DAYS ahead.
     * Read by the campaign page for N.
     *
     * @return list<CarbonImmutable>
     *
     * @throws CampaignScheduleException when the campaign's schedule cannot be read
     */
    public function openDates(MarketingCampaign $campaign, CarbonInterface $now): array
    {
        $until = CarbonImmutable::instance($now)->addDays(self::WINDOW_DAYS);

        return array_values(array_filter($this->open($campaign, $now, self::WINDOW_OCCURRENCES), fn (CarbonImmutable $at) => $at->lessThanOrEqualTo($until)));
    }

    /**
     * The campaign's next `$count` scheduled dates that are open (no kept posts).
     *
     * @return list<CarbonImmutable>
     */
    private function open(MarketingCampaign $campaign, CarbonInterface $now, int $count): array
    {
        $rows = $this->occurrences->handle($campaign, $now, $count);

        return array_values(array_map(fn (array $row) => $row['at'], array_filter($rows, fn (array $row) => $row['on_schedule'] && $row['state'] === 'open')));
    }

    /**
     * Log a refusal and throw it.
     *
     * @throws MarketingDraftRefusedException always
     */
    private function refuse(MarketingCampaign $campaign, string $reason, ?string $date, string $message): never
    {
        Log::info('marketing.ai.fill.refused', ['campaign_id' => $campaign->id, 'reason' => $reason, 'date' => $date]);

        throw new MarketingDraftRefusedException($message, $reason);
    }
}
