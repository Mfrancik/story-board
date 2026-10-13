<?php

namespace App\Actions\Marketing;

use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * Undo for Save to Inspiration (MK-18): deletes the inspiration that was saved from a post, and its copy of the
 * image. The post and its own photo are untouched.
 */
class RemoveSavedInspiration
{
    /**
     * @param  DeleteMarketingAsset  $delete  removes the row and its file
     */
    public function __construct(private DeleteMarketingAsset $delete) {}

    /**
     * Delete the inspiration saved from `$post`. Returns false when there is none (already undone).
     *
     * Side effects: deletes the asset and its file (DeleteMarketingAsset logs `marketing.asset.deleted`); logs
     * `marketing.inspiration.unsaved`, or `marketing.inspiration.unsave_skipped` (reason `not_saved`).
     */
    public function handle(MarketingPost $post): bool
    {
        $saved = SaveToInspiration::savedFrom($post);
        if ($saved === null) {
            Log::info('marketing.inspiration.unsave_skipped', ['post_id' => $post->id, 'reason' => 'not_saved']);

            return false;
        }

        $this->delete->handle($saved);
        Log::info('marketing.inspiration.unsaved', ['project_id' => $post->project_id, 'post_id' => $post->id, 'asset_id' => $saved->id]);

        return true;
    }
}
