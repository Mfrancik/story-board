<?php

namespace App\Jobs;

use App\Actions\Marketing\ReadMarketingAsset;
use App\Models\MarketingAsset;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one AI read of a marketing image, on the queue (MK-18): an inspiration image (headline, style, topic) or a
 * favourite photo (what is in it). The upload returns at once and each image shows "Reading…" until its own read is
 * done. Unique per asset and tried once: a failure shows "Couldn't read · Retry", and the owner decides whether to
 * spend another call.
 *
 * It carries the asset's id, not the model, so an image deleted before the worker gets to it is skipped and logged
 * rather than failing on a missing model.
 */
class ReadInspiration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One attempt; the owner's Retry is the retry. */
    public int $tries = 1;

    /** Past the HTTP timeout, so the lock outlives any run of the job. */
    public int $uniqueFor = 600;

    /** The request that stored the image, so this job's log lines trace back to it. */
    public ?string $requestId;

    /**
     * @param  int  $assetId  the marketing asset to read
     */
    public function __construct(public int $assetId)
    {
        $this->requestId = Context::get('request_id');
    }

    /**
     * One queued read per asset at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    /**
     * Read the asset. ReadMarketingAsset logs and records the expected outcomes (read, or failed with a reason); an
     * unexpected error is recorded as failed here, logged and rethrown so the queue records it.
     *
     * Side effects: one Claude call; writes the asset's `ai_notes`; logs.
     */
    public function handle(ReadMarketingAsset $read): void
    {
        // On a worker the originating request's Context is gone; restore its ID for this job's logs.
        if ($this->requestId !== null) {
            Context::add('request_id', $this->requestId);
        }

        $asset = MarketingAsset::find($this->assetId);
        if ($asset === null) {
            Log::info('marketing.asset.read_skipped', ['asset_id' => $this->assetId, 'reason' => 'gone']);

            return;
        }

        try {
            $read->handle($asset);
        } catch (Throwable $e) {
            Log::error($read->event($asset, 'read_failed'), ['asset_id' => $asset->id, 'reason' => 'exception', 'exception' => $e->getMessage()]);
            $read->markFailed($asset, 'exception');

            throw $e;
        }
    }
}
