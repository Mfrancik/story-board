<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The studio's approval queue (MK-9): the campaign dates waiting for the owner, earliest first, for one campaign or
 * for every campaign of a project that is on ("Start approving"). Each campaign's dates come from
 * BuildCampaignRunway::datesToApprove(), the rule behind the campaigns list's count. Reads only.
 */
class ListDatesToApprove
{
    /**
     * @param  BuildCampaignRunway  $runway  the one place "to approve" is decided
     */
    public function __construct(private BuildCampaignRunway $runway) {}

    /**
     * The queue for `$campaign`, or for all of `$project`'s enabled campaigns when null.
     *
     * Side effect: a campaign whose schedule cannot be read is left out and logged as `marketing.runway.failed`
     * (warning, source studio).
     *
     * @return list<array{campaign_id: int, date: string, at: CarbonImmutable}>
     */
    public function handle(Project $project, ?MarketingCampaign $campaign, CarbonInterface $now): array
    {
        $campaigns = $campaign !== null ? collect([$campaign]) : $project->marketingCampaigns()->where('is_enabled', true)->get();

        $queue = [];
        foreach ($campaigns as $c) {
            try {
                foreach ($this->runway->datesToApprove($c, $now) as $date) {
                    $queue[] = ['campaign_id' => $c->id, ...$date];
                }
            } catch (CampaignScheduleException $e) {
                Log::warning('marketing.runway.failed', ['project' => $project->name, 'campaign_id' => $c->id, 'reason' => $e->getMessage(), 'source' => 'studio']);
            }
        }
        usort($queue, fn (array $a, array $b) => [$a['at']->getTimestamp(), $a['campaign_id']] <=> [$b['at']->getTimestamp(), $b['campaign_id']]);

        return $queue;
    }
}
