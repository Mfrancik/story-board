<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingAsset;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * The studio's photo choice for a post (MK-9): a photo uploaded for this post or a favourite (`$asset`), or "Text
 * only" (`$asset` null), which tells a photo-per-post campaign that no photo is wanted. The caller has checked the
 * asset belongs to the post's project (FindProjectAsset).
 */
class SetPostPhoto
{
    use RefusesPostActions;

    /**
     * Give `$post` the photo `$asset`, or make it text only when null.
     *
     * Side effects: writes the post; logs `marketing.post.photo_set` (asset_id, text_only) or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post has gone out, been skipped or discarded
     */
    public function handle(MarketingPost $post, ?MarketingAsset $asset): MarketingPost
    {
        if (! in_array($post->status, [MarketingPost::STATUS_DRAFT, MarketingPost::STATUS_APPROVED], true) || $post->isPosted()) {
            $this->refuse('set_photo', $post, 'not_editable', 'Only a draft or an approved post can change its photo.');
        }

        $post->update(['asset_id' => $asset?->id, 'is_text_only' => $asset === null]);
        Log::info('marketing.post.photo_set', [
            'post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version, 'asset_id' => $asset?->id, 'text_only' => $asset === null,
        ]);

        return $post;
    }
}
