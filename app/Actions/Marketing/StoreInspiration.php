<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Stores one image dropped on the Inspiration wall (MK-18) as an inspiration asset of the project — MK-2's table and
 * StoreMarketingAsset, `purpose=inspiration`, not a favourite — and asks for its one AI read. The caller has already
 * validated type and size (ValidatesMarketingImages).
 */
class StoreInspiration
{
    /**
     * @param  StoreMarketingAsset  $store  writes the file and row
     * @param  QueueAssetRead  $read  marks it reading and queues the read
     */
    public function __construct(private StoreMarketingAsset $store, private QueueAssetRead $read) {}

    /**
     * Store `$file` as `$project`'s inspiration and queue its read.
     *
     * Side effects: a file and a `marketing_assets` row (StoreMarketingAsset logs `marketing.asset.stored`); one
     * ReadInspiration job; logs `marketing.inspiration.stored`.
     */
    public function handle(Project $project, UploadedFile $file): MarketingAsset
    {
        $asset = $this->store->handle($project, $file, MarketingAsset::PURPOSE_INSPIRATION, favourite: false);
        $asset->update(['ai_notes' => ['source' => 'upload']]);
        Log::info('marketing.inspiration.stored', ['project' => $project->name, 'asset_id' => $asset->id]);
        $this->read->handle($asset);

        return $asset;
    }
}
