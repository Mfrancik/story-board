<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Skip date" in the studio (MK-9): nothing posts on this campaign date. A date with drafts keeps them, marked
 * `skipped`, so Restore can bring them back; an open date gets a captionless `skipped` placeholder post at the
 * campaign's time, because a date's state is read from its posts (BuildCampaignRunway::state()). A skipped date
 * counts as covered for approved-through.
 */
class SkipOccurrence
{
    use RefusesPostActions;

    /**
     * @param  FindOccurrencePosts  $occurrence  the date's posts and time
     */
    public function __construct(private FindOccurrencePosts $occurrence) {}

    /**
     * Skip `$campaign`'s `$date` (Y-m-d, campaign timezone).
     *
     * Side effects: writes the date's drafts or one placeholder; logs `marketing.post.skipped` (campaign_id, date,
     * post_ids) or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the date has an approved or posted post
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function handle(MarketingCampaign $campaign, string $date): void
    {
        $posts = $this->occurrence->handle($campaign, $date);
        if ($posts->contains(fn (MarketingPost $p) => $p->status === MarketingPost::STATUS_APPROVED || $p->isPosted())) {
            $this->refuse('skip', null, 'decided', 'This date already has an approved post. Unapprove it first.', ['campaign_id' => $campaign->id, 'date' => $date]);
        }

        $ids = DB::transaction(function () use ($campaign, $date, $posts) {
            $drafts = $posts->where('status', MarketingPost::STATUS_DRAFT);
            if ($drafts->isNotEmpty()) {
                MarketingPost::whereKey($drafts->modelKeys())->update(['status' => MarketingPost::STATUS_SKIPPED]);

                return $drafts->modelKeys();
            }
            if ($posts->isNotEmpty()) {
                // Already skipped: nothing to add.
                return [];
            }

            return [MarketingPost::create([
                'project_id' => $campaign->project_id, 'campaign_id' => $campaign->id, 'status' => MarketingPost::STATUS_SKIPPED,
                'scheduled_for' => $this->occurrence->at($campaign, $date)->utc(), 'caption' => '', 'source' => 'manual',
            ])->id];
        });

        Log::info('marketing.post.skipped', ['campaign_id' => $campaign->id, 'date' => $date, 'post_ids' => $ids]);
    }
}
