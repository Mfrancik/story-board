<?php

namespace App\Actions\Marketing;

use App\Models\MarketingCampaign;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Switches a campaign on or off (MK-7, the switch in the campaign page's header). Switching off pauses it: its posts
 * are kept exactly as they are, it moves to the list's Paused group and stops counting towards what needs the owner.
 */
class ToggleCampaign
{
    /**
     * Set `$campaign` on (`$on` true) or off.
     *
     * Side effects: writes `is_enabled`; logs `marketing.campaign.toggled` (project, campaign_id, is_enabled).
     */
    public function handle(Project $project, MarketingCampaign $campaign, bool $on): MarketingCampaign
    {
        $campaign->update(['is_enabled' => $on]);
        Log::info('marketing.campaign.toggled', ['project' => $project->name, 'campaign_id' => $campaign->id, 'is_enabled' => $on]);

        return $campaign;
    }
}
