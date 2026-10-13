<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingPost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * "Reschedule" in the studio (MK-9): an approved post — upcoming or missed — moves to a new date and time. Only
 * forward: a time already past is refused, because a post is never scheduled to have gone out already.
 */
class ReschedulePost
{
    use RefusesPostActions;

    /**
     * Move approved `$post` to `$at`, which must be after `$now`.
     *
     * Side effects: writes the post; logs `marketing.post.rescheduled` (from, to) or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post is not approved, or `$at` is not in the future
     */
    public function handle(MarketingPost $post, CarbonImmutable $at, CarbonInterface $now): MarketingPost
    {
        if ($post->status !== MarketingPost::STATUS_APPROVED || $post->isPosted()) {
            $this->refuse('reschedule', $post, 'not_approved', 'Only an approved post can be rescheduled.');
        }
        if ($at->lessThanOrEqualTo($now)) {
            $this->refuse('reschedule', $post, 'past', 'That time has passed. Pick a later one.', ['to' => $at->utc()->toIso8601String()]);
        }

        $from = $post->scheduled_for?->toIso8601String();
        $post->update(['scheduled_for' => $at->utc()]);
        Log::info('marketing.post.rescheduled', [
            'post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version, 'from' => $from, 'to' => $at->utc()->toIso8601String(),
        ]);

        return $post;
    }
}
