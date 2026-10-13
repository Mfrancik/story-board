<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * "Unapprove" in the studio (MK-9, confirmed in board/confirm-modal): an approved post goes back to being a draft
 * that needs approval. The drafts discarded when it was approved stay discarded; Regenerate makes new ones.
 */
class UnapprovePost
{
    use RefusesPostActions;

    /**
     * Turn approved `$post` back into a draft.
     *
     * Side effects: writes the post; logs `marketing.post.unapproved` or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post is not approved (a posted post cannot be unapproved)
     */
    public function handle(MarketingPost $post): MarketingPost
    {
        if ($post->status !== MarketingPost::STATUS_APPROVED || $post->isPosted()) {
            $this->refuse('unapprove', $post, 'not_approved', 'Only an approved post that has not gone out can be unapproved.');
        }

        $post->update(['status' => MarketingPost::STATUS_DRAFT]);
        Log::info('marketing.post.unapproved', ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version]);

        return $post;
    }
}
