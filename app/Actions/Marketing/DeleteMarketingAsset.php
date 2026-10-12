<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes one marketing asset and its file (MK-2: Settings' "Delete photo" behind the confirm modal). Posts that
 * used it keep their caption and lose the photo: the posts' foreign key nulls `asset_id`.
 */
class DeleteMarketingAsset
{
    /**
     * Delete `$asset`'s row, then its file.
     *
     * Side effects: deletes the row (nulling posts' `asset_id`) and the file on the local disk; logs
     * `marketing.asset.deleted` (project id, asset id, posts unlinked).
     */
    public function handle(MarketingAsset $asset): void
    {
        $unlinked = $asset->posts()->count();
        // Row first: a file left behind by a failed delete is invisible, a row left pointing at no file is not.
        $asset->delete();
        Storage::disk('local')->delete($asset->path);

        Log::info('marketing.asset.deleted', ['project_id' => $asset->project_id, 'asset_id' => $asset->id, 'posts_unlinked' => $unlinked]);
    }
}
