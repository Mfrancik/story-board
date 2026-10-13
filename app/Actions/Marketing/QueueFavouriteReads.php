<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingKitException;
use App\Models\MarketingAsset;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * The one-time backfill for favourite photos stored before MK-18 (`marketing:read-favourites`): one read for each
 * favourite with no description yet, so Fill's photo list shows what each photo is rather than its file name. Safe to
 * run again: a photo already read, or being read, is left alone.
 */
class QueueFavouriteReads
{
    /**
     * @param  QueueAssetRead  $read  marks a photo reading and queues its read
     */
    public function __construct(private QueueAssetRead $read) {}

    /**
     * Queue a read for each of `$projectName`'s favourite photos that has no description and is not being read.
     * Returns how many were queued.
     *
     * Side effects: one ReadInspiration job per photo (QueueAssetRead logs each); logs `marketing.photo.backfill_queued`.
     *
     * @throws MarketingKitException for an unknown project (logged as `marketing.photo.backfill_refused`)
     */
    public function handle(string $projectName): int
    {
        $project = Project::where('name', $projectName)->first();
        if ($project === null) {
            Log::warning('marketing.photo.backfill_refused', ['project' => $projectName, 'reason' => 'unknown_project']);

            throw new MarketingKitException("No project named \"{$projectName}\".", 'unknown_project');
        }

        $queued = $project->marketingAssets()->favouritePhotos()->get()
            ->filter(fn (MarketingAsset $a) => trim((string) ($a->ai_notes['description'] ?? '')) === '' && $a->readState() !== MarketingAsset::READ_READING)
            ->filter(fn (MarketingAsset $a) => $this->read->handle($a))
            ->count();

        Log::info('marketing.photo.backfill_queued', ['project' => $project->name, 'queued' => $queued]);

        return $queued;
    }
}
