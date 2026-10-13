<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * One campaign date's posts (MK-9): every kept (not discarded) post whose day, in the campaign's timezone, is the
 * date — the same rule as BuildCampaignRunway::day(), as a query. The studio shows them; Approve, Skip and Restore
 * act on them. Reads only.
 */
class FindOccurrencePosts
{
    /**
     * @param  CampaignSchedule  $schedule  where the campaign's timezone is read
     */
    public function __construct(private CampaignSchedule $schedule) {}

    /**
     * The kept posts of `$campaign` on `$date` (Y-m-d, campaign timezone), oldest first, with photo, campaign and
     * networks loaded for the studio.
     *
     * @return Collection<int, MarketingPost>
     *
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function handle(MarketingCampaign $campaign, string $date): Collection
    {
        $zone = $this->schedule->zone($campaign);
        $start = CarbonImmutable::parse($date, $zone)->startOfDay();

        return $campaign->posts()
            ->where('status', '!=', MarketingPost::STATUS_DISCARDED)
            // Half-open in UTC: the local day's first instant up to (not including) the next day's.
            ->where('scheduled_for', '>=', $start->utc())
            ->where('scheduled_for', '<', $start->addDay()->utc())
            ->with(['asset', 'campaign', 'networks'])
            ->orderBy('id')
            ->get();
    }

    /**
     * When `$campaign` posts on `$date`: its time on that day in its timezone.
     *
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function at(MarketingCampaign $campaign, string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$campaign->time, $this->schedule->zone($campaign));
    }
}
