<?php

namespace App\Actions\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The owner's taste as examples for a prompt (MK-8): the latest liked captions, the latest disliked captions with
 * their reasons, and the latest written feedback notes. Taste is examples, not training. Each list is filled from
 * the campaign first and topped up from the rest of the project, so a campaign's own voice wins. Reads only.
 */
class BuildTasteExamples
{
    /** Liked captions in a prompt. */
    public const LIKED = 10;

    /** Disliked captions in a prompt. */
    public const DISLIKED = 5;

    /** Feedback notes in a prompt. */
    public const NOTES = 10;

    /**
     * The taste examples for drafting in `$campaign`, newest first within the campaign's and then the project's.
     *
     * @return array{liked: list<string>, disliked: list<array{caption: string, reasons: list<string>}>, notes: list<string>}
     */
    public function handle(MarketingCampaign $campaign): array
    {
        $liked = $this->latest($campaign, fn (Builder $q) => $q->where('vote', MarketingFeedback::LIKED), self::LIKED);
        $disliked = $this->latest($campaign, fn (Builder $q) => $q->where('vote', MarketingFeedback::DISLIKED), self::DISLIKED);
        $notes = $this->latest($campaign, fn (Builder $q) => $q->whereNotNull('note')->where('note', '!=', ''), self::NOTES);

        return [
            'liked' => array_values($liked->map(fn (MarketingFeedback $f) => (string) $f->post?->caption)->all()),
            'disliked' => array_values($disliked->map(fn (MarketingFeedback $f) => ['caption' => (string) $f->post?->caption, 'reasons' => $f->reasons ?? []])->all()),
            'notes' => array_values($notes->map(fn (MarketingFeedback $f) => (string) $f->note)->all()),
        ];
    }

    /**
     * Up to `$limit` of the project's feedback matching `$where`: the campaign's newest first, then the rest of the
     * project's newest. Two bounded queries rather than one ORDER BY on a computed column.
     *
     * @param  callable(Builder<MarketingFeedback>): mixed  $where
     * @return Collection<int, MarketingFeedback>
     */
    private function latest(MarketingCampaign $campaign, callable $where, int $limit): Collection
    {
        $base = fn () => MarketingFeedback::query()->where('project_id', $campaign->project_id)->tap($where)
            ->with('post:id,caption')->latest('created_at')->latest('id');

        $own = $base()->where('campaign_id', $campaign->id)->limit($limit)->get();
        if ($own->count() >= $limit) {
            return $own;
        }
        $rest = $base()->where(fn (Builder $q) => $q->whereNull('campaign_id')->orWhere('campaign_id', '!=', $campaign->id))
            ->limit($limit - $own->count())->get();

        return $own->concat($rest)->values();
    }
}
