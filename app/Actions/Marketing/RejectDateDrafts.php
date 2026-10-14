<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Services\Marketing\MarketingAiClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Reject all 3" in the review inbox (MK-20): none of a campaign date's drafts will do. Each gets a dislike (with the
 * owner's optional shared reason) through RecordFeedback, so the taste examples learn from them; the drafts are
 * discarded; and the date is redrafted by MK-8's Fill for that one date (FillCampaign), which marks it drafting and
 * queues its DraftOccurrenceJob — the same path, state and Retry as a row's Fill on the campaign page.
 */
class RejectDateDrafts
{
    use RefusesPostActions;

    /**
     * @param  FindOccurrencePosts  $occurrence  the date's posts
     * @param  RecordFeedback  $feedback  the one way a vote is written (MK-9)
     * @param  FillCampaign  $fill  MK-8's single-date Fill
     * @param  MarketingAiClient  $ai  whether a key is set
     */
    public function __construct(
        private FindOccurrencePosts $occurrence,
        private RecordFeedback $feedback,
        private FillCampaign $fill,
        private MarketingAiClient $ai,
    ) {}

    /**
     * Reject every draft of `$campaign` on `$date` (Y-m-d, campaign timezone) with `$reason` (one of
     * RecordFeedback::REASONS, or null), then redraft the date. Returns the rejected post ids.
     *
     * Side effects: one dislike per draft (RecordFeedback logs each), the drafts set `discarded`, then FillCampaign
     * marks the date drafting and queues its job (logs `marketing.ai.fill.queued`); logs `marketing.review.rejected_all`
     * (campaign_id, date, post_ids, reason). A refusal logs `marketing.post.refused` (nothing to reject) or
     * `marketing.review.refused` (redraft cannot start) and writes nothing.
     *
     * @return list<int>
     *
     * @throws MarketingPostRefusedException when the date has no drafts or has an approved or posted post, or (from RecordFeedback) the reason is unknown
     * @throws MarketingDraftRefusedException when the redraft cannot start (no key, campaign paused), or Fill refuses the date
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function handle(MarketingCampaign $campaign, string $date, ?string $reason, CarbonInterface $now): array
    {
        $posts = $this->occurrence->handle($campaign, $date);
        $drafts = $posts->where('status', MarketingPost::STATUS_DRAFT)->values();
        // Only a date still waiting for a pick: drafts, and nothing approved or posted beside them.
        if ($drafts->isEmpty() || $posts->contains(fn (MarketingPost $p) => $p->status === MarketingPost::STATUS_APPROVED || $p->isPosted())) {
            $this->refuse('reject_all', null, 'nothing_to_reject', 'This date has no drafts to reject.', ['campaign_id' => $campaign->id, 'date' => $date]);
        }
        // Checked before anything is written: discarding the three and then failing to redraft would leave the date
        // empty for a reason the owner can only fix in .env or on the campaign page. FillCampaign checks both again.
        if (! $this->ai->isConfigured() || ! $campaign->is_enabled) {
            $why = $campaign->is_enabled ? 'not_configured' : 'paused';
            Log::info('marketing.review.refused', ['campaign_id' => $campaign->id, 'date' => $date, 'reason' => $why]);

            throw new MarketingDraftRefusedException($why === 'paused'
                ? 'This campaign is paused. Turn it on to redraft its dates. Nothing was rejected.'
                : 'Redrafting needs ANTHROPIC_API_KEY in .env. Nothing was rejected.', $why);
        }

        $ids = array_values(array_map(intval(...), $drafts->modelKeys()));
        // The votes and the discard land together (RecordFeedback refusing an unknown reason rolls both back); the Fill is outside, because on the sync queue its job runs (and
        // calls Claude) inline, and it must see the date already open.
        DB::transaction(function () use ($drafts, $reason, $ids) {
            foreach ($drafts as $draft) {
                $this->feedback->handle($draft, MarketingFeedback::DISLIKED, $reason === null ? [] : [$reason]);
            }
            MarketingPost::whereKey($ids)->update(['status' => MarketingPost::STATUS_DISCARDED]);
        });

        Log::info('marketing.review.rejected_all', ['campaign_id' => $campaign->id, 'date' => $date, 'post_ids' => $ids, 'reason' => $reason]);
        // Fill reads the date's state from the campaign's `posts` when loaded; a caller's copy still holds the drafts
        // just discarded, and would make the date read as not open.
        $this->fill->handle($campaign->withoutRelations(), $now, $date);

        return $ids;
    }
}
