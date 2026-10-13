<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingCampaign;
use Illuminate\Support\Collection;

/**
 * Picks the inspiration Fill shows Claude as examples of tone and copy (MK-18): the owner's favourites first (newest
 * first), then the closest matches to the campaign — at most MAX in all. "Closest" is plain word overlap between an
 * image's notes (topic, headline, style, caption) and the campaign's name, brief and topics; an image sharing no word
 * is never picked just to fill a slot. No embeddings (out of scope): word overlap is enough for a few dozen ads.
 *
 * Only images whose notes are known count: read by the AI, or seeded from the kit, or saved from a post.
 */
class PickInspirationExamples
{
    /** At most this many examples per prompt: enough to set a tone, few enough to keep the prompt cheap. */
    public const MAX = 5;

    /** Words that say nothing about a topic. */
    private const STOP = ['about', 'after', 'again', 'their', 'there', 'these', 'this', 'that', 'with', 'from', 'your', 'yours',
        'what', 'when', 'which', 'them', 'they', 'then', 'than', 'into', 'just', 'more', 'most', 'only', 'over', 'some', 'very', 'each'];

    /**
     * The examples for `$campaign`, favourites first, then the closest matches, at most MAX.
     *
     * @return Collection<int, MarketingAsset>
     */
    public function handle(MarketingCampaign $campaign): Collection
    {
        $known = MarketingAsset::query()->where('project_id', $campaign->project_id)->inspiration()->get()
            ->filter(fn (MarketingAsset $a) => $a->readState() === MarketingAsset::READ_DONE)->values();

        $favourites = $known->where('is_favourite', true)->take(self::MAX)->values();
        $room = self::MAX - $favourites->count();
        if ($room === 0) {
            return $favourites;
        }

        $wanted = $this->words(implode(' ', [$campaign->name, (string) $campaign->brief, ...$this->topics($campaign)]));
        $closest = $known->where('is_favourite', false)
            ->map(fn (MarketingAsset $a) => ['asset' => $a, 'score' => count(array_intersect($wanted, $this->words($this->notesText($a))))])
            ->filter(fn (array $r) => $r['score'] > 0)
            // Stable on ties, so the newer image (earlier in $known) wins.
            ->sortByDesc('score')
            ->take($room)
            ->pluck('asset');

        return $favourites->concat($closest)->values();
    }

    /**
     * The campaign's topics as text: its menu ideas ("Category|Title") and its own topics with their detail.
     *
     * @return list<string>
     */
    private function topics(MarketingCampaign $campaign): array
    {
        $own = array_map(fn (array $t) => $t['text'].' '.($t['detail'] ?? ''), $campaign->topics['own'] ?? []);

        return [...array_map(fn (string $k) => str_replace('|', ' ', $k), $campaign->topics['ideas'] ?? []), ...$own];
    }

    /**
     * Everything an image's notes say, as one string.
     */
    private function notesText(MarketingAsset $asset): string
    {
        $n = $asset->ai_notes ?? [];

        return implode(' ', [(string) ($n['topic'] ?? ''), (string) ($n['headline'] ?? ''), implode(' ', (array) ($n['style'] ?? [])), (string) ($n['caption'] ?? '')]);
    }

    /**
     * The distinct meaningful words of `$text`: lower-cased, longer than 3 letters, stop words out, a plural "s"
     * dropped so "colours" meets "colour".
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn (string $w) => mb_strlen($w) > 4 && str_ends_with($w, 's') ? mb_substr($w, 0, -1) : $w, $words);

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 3 && ! in_array($w, self::STOP, true))));
    }
}
