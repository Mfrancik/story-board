<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingAiException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Regenerate all 3" for one campaign date (MK-8; its button arrives with the studio, MK-9): discards the date's
 * drafts and drafts new ones. The old drafts are discarded only once the new ones are in hand, in the same
 * transaction, so a failed regenerate leaves the date as it was. A date with an approved, posted or skipped post
 * is refused: regenerating never touches a decision the owner made.
 */
class RegenerateOccurrence
{
    /**
     * @param  DraftOccurrence  $draft  which drafts with `replace` on
     */
    public function __construct(private DraftOccurrence $draft) {}

    /**
     * Replace `$campaign`'s drafts on `$at` with new ones and return them.
     *
     * Side effects: as DraftOccurrence (one Claude call, writes, logs), plus the old drafts' status set to discarded.
     *
     * @return Collection<int, MarketingPost>
     *
     * @throws MarketingDraftRefusedException when no key is set or the date has a post that is not a draft
     * @throws MarketingAiException when drafting fails; the old drafts are kept
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function handle(MarketingCampaign $campaign, CarbonImmutable $at): Collection
    {
        return $this->draft->handle($campaign, $at, replace: true);
    }
}
