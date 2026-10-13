<?php

namespace App\Actions\Marketing;

use App\Jobs\ReadInspiration;
use App\Models\MarketingAsset;
use Illuminate\Support\Facades\Log;

/**
 * Asks for the one AI read of a marketing image (MK-18): an inspiration upload, a new favourite photo, the owner's
 * Retry, or the favourites backfill. Every way in comes through here, so "reading" is marked before the job is queued
 * (on the sync queue the job has already finished by the time dispatch returns) and an image already being read is
 * never sent twice.
 */
class QueueAssetRead
{
    /**
     * Mark `$asset` as reading and queue its ReadInspiration job. Returns false, queueing nothing, when it is already
     * being read (and not stale).
     *
     * Side effects: writes `ai_notes.status`; queues a job; logs `marketing.asset.read_queued`, or
     * `marketing.asset.read_skipped` (reason `already_reading`).
     */
    public function handle(MarketingAsset $asset): bool
    {
        if ($asset->readState() === MarketingAsset::READ_READING) {
            Log::info('marketing.asset.read_skipped', ['asset_id' => $asset->id, 'purpose' => $asset->purpose, 'reason' => 'already_reading']);

            return false;
        }

        // Keep what is already known (the source, a saved headline) and drop only the last failure's reason.
        $notes = $asset->ai_notes ?? [];
        unset($notes['error']);
        $asset->ai_notes = [...$notes, 'status' => MarketingAsset::READ_READING];
        // readState() measures staleness from updated_at, so a retry of a stale read (status already "reading", so
        // nothing dirty) must still start a fresh window: touch it.
        $asset->isDirty() ? $asset->save() : $asset->touch();

        Log::info('marketing.asset.read_queued', ['asset_id' => $asset->id, 'purpose' => $asset->purpose]);
        ReadInspiration::dispatch($asset->id);

        return true;
    }
}
