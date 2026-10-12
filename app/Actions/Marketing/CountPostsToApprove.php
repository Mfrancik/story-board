<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * How many posts across a project's campaigns that are on need the owner's
 * approval (MK-1): the count on the Marketing tab of every project page. The same
 * rule as the campaigns list's banner, because it sums BuildCampaignRunway's
 * `to_approve`. Paused campaigns never count. Reads only.
 */
class CountPostsToApprove
{
    /**
     * @param  BuildCampaignRunway  $runway  the one place "to approve" is decided
     */
    public function __construct(private BuildCampaignRunway $runway) {}

    /**
     * The number of dates to approve in the named project, as of `$now`.
     *
     * Side effect: logs `marketing.runway.failed` (warning) for a campaign whose
     * schedule cannot be read; it is left out of the count, never fails the page.
     */
    public function handle(string $project, CarbonInterface $now): int
    {
        $campaigns = MarketingCampaign::query()
            ->whereRelation('project', 'name', $project)
            ->where('is_enabled', true)
            ->with(['posts' => fn ($q) => $q->runwayWindow($now)])
            ->get();

        $total = 0;
        foreach ($campaigns as $campaign) {
            try {
                $total += $this->runway->handle($campaign, $now)['to_approve'];
            } catch (CampaignScheduleException $e) {
                Log::warning('marketing.runway.failed', ['project' => $project, 'campaign_id' => $campaign->id, 'reason' => $e->getMessage(), 'source' => 'tab_count']);
            }
        }

        return $total;
    }
}
