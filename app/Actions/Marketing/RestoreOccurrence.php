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
 * "Restore this date" in the studio (MK-9), the way back from Skip: the date's skipped drafts become drafts again,
 * and the captionless placeholder SkipOccurrence put on an open date is discarded, so the date reads open again.
 */
class RestoreOccurrence
{
    use RefusesPostActions;

    /**
     * @param  FindOccurrencePosts  $occurrence  the date's posts
     */
    public function __construct(private FindOccurrencePosts $occurrence) {}

    /**
     * Restore `$campaign`'s skipped `$date` (Y-m-d, campaign timezone).
     *
     * Side effects: writes the date's skipped posts; logs `marketing.post.restored` or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the date is not skipped
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function handle(MarketingCampaign $campaign, string $date): void
    {
        $skipped = $this->occurrence->handle($campaign, $date)->where('status', MarketingPost::STATUS_SKIPPED);
        if ($skipped->isEmpty()) {
            $this->refuse('restore', null, 'not_skipped', 'This date is not skipped.', ['campaign_id' => $campaign->id, 'date' => $date]);
        }

        [$placeholders, $drafts] = $skipped->partition(fn (MarketingPost $p) => $p->caption === '');
        DB::transaction(function () use ($placeholders, $drafts) {
            MarketingPost::whereKey($placeholders->modelKeys())->update(['status' => MarketingPost::STATUS_DISCARDED]);
            MarketingPost::whereKey($drafts->modelKeys())->update(['status' => MarketingPost::STATUS_DRAFT]);
        });

        Log::info('marketing.post.restored', ['campaign_id' => $campaign->id, 'date' => $date, 'post_ids' => $drafts->modelKeys()]);
    }
}
