<?php

namespace App\Actions\Marketing\Concerns;

use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * The one way a studio Action (MK-9) refuses: log `marketing.post.refused` with the action, the reason and the post,
 * then throw for the studio to show. So every guard has its log line by construction.
 */
trait RefusesPostActions
{
    /**
     * Log the refusal and throw it.
     *
     * @param  array<string, mixed>  $context  extra context for the log line (campaign_id, date)
     *
     * @throws MarketingPostRefusedException always
     */
    protected function refuse(string $action, ?MarketingPost $post, string $reason, string $message, array $context = []): never
    {
        Log::info('marketing.post.refused', [
            'action' => $action, 'reason' => $reason, 'post_id' => $post?->id, 'campaign_id' => $post?->campaign_id, ...$context,
        ]);

        throw new MarketingPostRefusedException($message, $reason);
    }
}
