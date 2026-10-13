<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use Illuminate\Support\Facades\Log;

/**
 * The heart on an Inspiration tile (MK-18): a favourite is always among the examples Fill gives Claude, ahead of the
 * closest matches (PickInspirationExamples).
 */
class SetInspirationFavourite
{
    /**
     * Set whether `$asset` is a favourite.
     *
     * Side effects: writes `is_favourite`; logs `marketing.inspiration.favourited` (asset id, favourite).
     */
    public function handle(MarketingAsset $asset, bool $favourite): void
    {
        $asset->update(['is_favourite' => $favourite]);
        Log::info('marketing.inspiration.favourited', ['project_id' => $asset->project_id, 'asset_id' => $asset->id, 'favourite' => $favourite]);
    }
}
