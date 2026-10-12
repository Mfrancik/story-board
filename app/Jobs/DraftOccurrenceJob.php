<?php

namespace App\Jobs;

use App\Actions\Marketing\DraftOccurrence;
use App\Exceptions\MarketingAiException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Models\MarketingCampaign;
use App\Services\Marketing\DraftingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drafts one campaign date on the queue (MK-8), so Fill returns at once and each date fills, or fails, on its own.
 * Unique per campaign date: a double click queues one job. Tried once: a failure shows "Drafting failed · Retry" on
 * the page, and the owner decides whether to spend another call.
 */
class DraftOccurrenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One attempt; the owner's Retry is the retry. */
    public int $tries = 1;

    /** Past the HTTP timeout, so the lock outlives any run of the job. */
    public int $uniqueFor = 600;

    /** The request that pressed Fill, so this job's log lines trace back to it. */
    public ?string $requestId;

    /**
     * @param  MarketingCampaign  $campaign  the campaign whose date to draft
     * @param  string  $at  the occurrence's time, ISO 8601 in UTC
     * @param  string  $date  the occurrence's date (Y-m-d) in the campaign's timezone, the key the page reads its state by
     */
    public function __construct(public MarketingCampaign $campaign, public string $at, public string $date)
    {
        $this->requestId = Context::get('request_id');
    }

    /**
     * One queued draft per campaign date at a time.
     */
    public function uniqueId(): string
    {
        return $this->campaign->id.'|'.$this->date;
    }

    /**
     * Draft the date, then record how it went for the page: cleared on success or refusal (the posts tell the
     * story), failed on an AI failure. DraftOccurrence logs the expected outcomes; an unexpected error is logged
     * here, marked failed and rethrown so the queue records it.
     */
    public function handle(DraftOccurrence $draft, DraftingStatus $status): void
    {
        // On a worker the originating request's Context is gone; restore its ID for this job's logs.
        if ($this->requestId !== null) {
            Context::add('request_id', $this->requestId);
        }

        try {
            $draft->handle($this->campaign, CarbonImmutable::parse($this->at));
            $status->clear($this->campaign->id, $this->date);
        } catch (MarketingDraftRefusedException) {
            // Already logged by DraftOccurrence (e.g. the date was filled by hand meanwhile): nothing to show.
            $status->clear($this->campaign->id, $this->date);
        } catch (MarketingAiException) {
            // Already logged as marketing.ai.draft.failed; the page offers Retry.
            $status->failed($this->campaign->id, $this->date);
        } catch (Throwable $e) {
            Log::error('marketing.ai.draft.failed', ['campaign_id' => $this->campaign->id, 'date' => $this->date, 'reason' => 'exception', 'exception' => $e->getMessage()]);
            $status->failed($this->campaign->id, $this->date);

            throw $e;
        }
    }
}
