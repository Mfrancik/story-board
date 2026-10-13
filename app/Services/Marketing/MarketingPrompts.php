<?php

namespace App\Services\Marketing;

use App\Actions\Marketing\BuildTasteExamples;
use App\Actions\Marketing\PickInspirationExamples;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The prompts Claude drafts and revises marketing captions from (MK-8), and the JSON schemas of the replies.
 *
 * Two parts. The system part is fixed per project — the brand's voice and copy rules — so it is the cached prefix
 * of every call. The user part is the call: the campaign's brief, its topics (rotated so recent ones are not
 * repeated), the owner's taste examples and what to do about photos. Nothing about how a post looks is ever in
 * either part: the brand's look is fixed and the AI never chooses it (owner, 2026-10-12).
 */
class MarketingPrompts
{
    /** Candidates per date. */
    public const DRAFTS = 3;

    /** How many of a campaign's latest posts are read to see which topics were used recently. */
    public const ROTATION_WINDOW = 30;

    /** At most this many favourite photos are offered to the model by id. */
    public const MAX_PHOTOS = 30;

    /** A placeholder in an idea's sample line ("[coin]", "[set]") that waits for a data source (phase 3). */
    public const PLACEHOLDER = '/\[[a-z][a-z ]*\]/i';

    /** The reply to a draft call: one caption and photo id (or null) per candidate. */
    public const DRAFTS_SCHEMA = [
        'type' => 'object',
        'properties' => ['drafts' => ['type' => 'array', 'items' => self::CAPTION_SCHEMA]],
        'required' => ['drafts'],
        'additionalProperties' => false,
    ];

    /** One caption and its photo id, or null for none. */
    public const CAPTION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'caption' => ['type' => 'string'],
            'asset_id' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
        ],
        'required' => ['caption', 'asset_id'],
        'additionalProperties' => false,
    ];

    /**
     * @param  BuildTasteExamples  $taste  the owner's likes, dislikes and notes as examples
     * @param  PickInspirationExamples  $inspiration  the ads the owner likes, as examples of tone and copy (MK-18)
     */
    public function __construct(private BuildTasteExamples $taste, private PickInspirationExamples $inspiration) {}

    /**
     * The fixed, cacheable part for `$project`: who the captions are for, the voice and the copy rules.
     */
    public function system(Project $project): string
    {
        $brand = $project->marketingBrand()->first();
        $name = $brand?->values['name'] ?? null;
        $name = is_string($name) && $name !== '' ? $name : $project->name;
        $voice = trim((string) $brand?->voice);
        $rules = $brand !== null ? $brand->copy_rules : ['filler' => [], 'banned' => []];

        $lines = [
            "You write captions for {$name}'s own Facebook page.",
            '',
            'The voice:',
            $voice !== '' ? $voice : 'Plain, friendly and short.',
            '',
            'Copy rules:',
            '- One or two short lines. Never more than two lines.',
        ];
        if ($rules['filler'] !== []) {
            $lines[] = '- Never use these filler phrases: '.$this->quoted($rules['filler']).'.';
        }
        if ($rules['banned'] !== []) {
            $lines[] = '- Never make these claims, in any wording: '.$this->quoted($rules['banned']).'.';
        }
        $lines[] = '- Never promise to look anything up from a certificate number.';
        $lines[] = '- A comparison of value says where the price came from.';
        // The brand's look is not the model's to vary; saying so without naming any part of it keeps the prompt clean.
        $lines[] = '- Write only the words. How a post looks is fixed by the brand and is not part of your job.';
        $lines[] = '';
        $lines[] = 'Reply with JSON only, in the schema given.';

        return implode("\n", $lines);
    }

    /**
     * The prompt for DRAFTS captions for `$campaign`'s date `$at`. Also returns `topics` (one per draft, in order),
     * `assets` (the photo ids the model may answer with), `skipped_ideas` (ticked ideas left out for their
     * placeholders) and `inspiration_ids` (the inspiration quoted as examples, MK-18).
     *
     * @return array{system: string, user: string, topics: list<array{key: string, label: string}>, assets: list<int>, skipped_ideas: list<string>, inspiration_ids: list<int>}
     */
    public function draft(MarketingCampaign $campaign, CarbonImmutable $at): array
    {
        $project = $campaign->project()->firstOrFail();
        [$pool, $skipped] = $this->topicPool($campaign, $project->marketingBrand()->first());
        $topics = $this->rotate($campaign, $pool);
        [$photo, $assets] = $this->photos($campaign, $project, null);
        $examples = $this->inspiration->handle($campaign);

        $lines = $this->heading($campaign, $at);
        if ($topics === []) {
            $lines[] = 'Write '.self::DRAFTS.' different captions from the brief.';
        } else {
            $lines[] = 'Write '.self::DRAFTS.' different captions, one for each topic, in this order:';
            foreach ($topics as $i => $topic) {
                $lines[] = ($i + 1).'. '.$topic['label'];
            }
        }

        return [
            'system' => $this->system($project),
            // Inspiration sits beside the taste examples, never inside them: taste is the owner's verdicts on drafts.
            'user' => implode("\n", [...$lines, ...$this->tasteLines($campaign), ...$this->inspirationLines($examples), '', $photo]),
            'topics' => $topics,
            'assets' => $assets,
            'skipped_ideas' => $skipped,
            'inspiration_ids' => array_values($examples->map(fn (MarketingAsset $a) => $a->id)->all()),
        ];
    }

    /**
     * The prompt to rewrite `$post` from the owner's `$feedback` and `$chips`. `assets` are the photo ids the model may
     * answer with, or null when the photo stays as it is.
     *
     * @param  list<string>  $chips
     * @return array{system: string, user: string, assets: list<int>|null}
     */
    public function revise(MarketingPost $post, string $feedback, array $chips): array
    {
        $project = $post->project()->firstOrFail();
        $campaign = $post->campaign()->first();
        $at = $post->scheduled_for !== null ? CarbonImmutable::instance($post->scheduled_for)->setTimezone($campaign !== null ? $campaign->timezone : 'UTC') : null;

        $lines = $campaign !== null && $at !== null ? $this->heading($campaign, $at) : ['A one-off post.', ''];
        $lines[] = 'Rewrite this caption:';
        $lines[] = '"""';
        $lines[] = $post->caption;
        $lines[] = '"""';
        if (trim($feedback) !== '') {
            $lines[] = 'What the owner wants changed: '.trim($feedback);
        }
        if ($chips !== []) {
            $lines[] = 'Also: '.implode('; ', $chips).'.';
        }
        $lines[] = 'Keep what the owner did not ask to change.';

        $assets = null;
        $photo = 'Photos: set asset_id to null. The photo stays as it is.';
        // Only "Different photo" in a mode that picks from favourites lets the model choose again; otherwise the
        // photo is the owner's (per post), absent (text only) or unchanged.
        if ($campaign !== null && in_array('Different photo', $chips, true)
            && in_array($campaign->photo_mode, [MarketingCampaign::PHOTO_FAVOURITES, MarketingCampaign::PHOTO_AI_DECIDES], true)) {
            [$photo, $assets] = $this->photos($campaign, $project, $post->asset_id);
        }

        return [
            'system' => $this->system($project),
            'user' => implode("\n", [...$lines, ...($campaign !== null ? $this->tasteLines($campaign) : []), '', $photo]),
            'assets' => $assets,
        ];
    }

    /**
     * The campaign, date and brief that open every call's user part.
     *
     * @return list<string>
     */
    private function heading(MarketingCampaign $campaign, CarbonImmutable $at): array
    {
        $local = $at->setTimezone($campaign->timezone);
        $brief = trim((string) $campaign->brief);

        return [
            'Campaign: '.$campaign->name,
            'Date: '.$local->format('l j F Y').' at '.$local->format('H:i'),
            'Brief: '.($brief !== '' ? $brief : '(none: write from the topics)'),
            '',
        ];
    }

    /**
     * Every topic the campaign may post about: its ticked menu ideas (with their sample line) then its own topics
     * (with their detail). Ideas whose sample line still has placeholders are left out until a data source fills
     * them (phase 3), and returned so the caller can log them.
     *
     * @return array{0: list<array{key: string, label: string}>, 1: list<string>}
     */
    private function topicPool(MarketingCampaign $campaign, ?MarketingBrand $brand): array
    {
        $menu = [];
        foreach ($brand !== null ? $brand->idea_menu : [] as $category) {
            foreach ($category['ideas'] as $idea) {
                $menu[$category['category'].'|'.$idea['title']] = $idea;
            }
        }

        $pool = [];
        $skipped = [];
        foreach ($campaign->topics['ideas'] ?? [] as $key) {
            $idea = $menu[$key] ?? null;
            if ($idea !== null && preg_match(self::PLACEHOLDER, $idea['text'])) {
                $skipped[] = $key;

                continue;
            }
            // An idea renamed off the menu (ADR-065) still reads by its title.
            $title = $idea['title'] ?? (str_contains($key, '|') ? substr($key, strpos($key, '|') + 1) : $key);
            $pool[] = ['key' => 'idea:'.$key, 'label' => $idea !== null ? "{$title} (sample line: \"{$idea['text']}\")" : $title];
        }
        foreach ($campaign->topics['own'] ?? [] as $own) {
            $text = trim($own['text']);
            if ($text === '') {
                continue;
            }
            $detail = trim((string) ($own['detail'] ?? ''));
            $pool[] = ['key' => 'own:'.$text, 'label' => $detail !== '' ? "{$text}: {$detail}" : $text];
        }

        return [$pool, $skipped];
    }

    /**
     * DRAFTS topics from `$pool`, least recently used first: never-used topics in the owner's order, then the used
     * ones from the longest ago. "Used" is read from the campaign's latest posts' `generation_meta.topic_key`. A pool
     * smaller than DRAFTS repeats from its start.
     *
     * @param  list<array{key: string, label: string}>  $pool
     * @return list<array{key: string, label: string}>
     */
    private function rotate(MarketingCampaign $campaign, array $pool): array
    {
        if ($pool === []) {
            return [];
        }

        // 0 = used most recently. Only the first sighting of each key counts.
        $recency = [];
        $campaign->posts()->latest('id')->limit(self::ROTATION_WINDOW)->pluck('generation_meta')
            ->each(function ($meta) use (&$recency) {
                $key = is_array($meta) ? ($meta['topic_key'] ?? null) : null;
                if (is_string($key) && ! isset($recency[$key])) {
                    $recency[$key] = count($recency);
                }
            });

        $fresh = array_values(array_filter($pool, fn (array $t) => ! isset($recency[$t['key']])));
        $used = array_values(array_filter($pool, fn (array $t) => isset($recency[$t['key']])));
        usort($used, fn (array $a, array $b) => $recency[$b['key']] <=> $recency[$a['key']]);
        $ordered = [...$fresh, ...$used];

        $out = [];
        for ($i = 0; $i < self::DRAFTS; $i++) {
            $out[] = $ordered[$i % count($ordered)];
        }

        return $out;
    }

    /**
     * The taste examples as prompt lines; none when the owner has given no feedback yet.
     *
     * @return list<string>
     */
    private function tasteLines(MarketingCampaign $campaign): array
    {
        $taste = $this->taste->handle($campaign);
        $oneLine = fn (string $caption) => '"'.trim((string) preg_replace('/\s*\R\s*/', ' / ', $caption)).'"';
        $lines = [];

        if ($taste['liked'] !== []) {
            $lines[] = '';
            $lines[] = 'Captions the owner liked (match this taste):';
            foreach ($taste['liked'] as $caption) {
                $lines[] = '- '.$oneLine($caption);
            }
        }
        if ($taste['disliked'] !== []) {
            $lines[] = '';
            $lines[] = 'Captions the owner disliked, and why (avoid this):';
            foreach ($taste['disliked'] as $d) {
                $lines[] = '- '.$oneLine($d['caption']).($d['reasons'] !== [] ? ' ('.implode(', ', $d['reasons']).')' : '');
            }
        }
        if ($taste['notes'] !== []) {
            $lines[] = '';
            $lines[] = 'What the owner has said about earlier drafts:';
            foreach ($taste['notes'] as $note) {
                $lines[] = '- '.trim($note);
            }
        }

        return $lines;
    }

    /**
     * The ads the owner likes (MK-18) as prompt lines: each one's words and how it is written. Their words, not their
     * look: layout matching waits for the designer (phase 3). None when there is no inspiration.
     *
     * @param  Collection<int, MarketingAsset>  $examples
     * @return list<string>
     */
    private function inspirationLines(Collection $examples): array
    {
        if ($examples->isEmpty()) {
            return [];
        }
        $oneLine = fn (string $text) => trim((string) preg_replace('/\s*\R\s*/', ' / ', $text));

        $lines = ['', 'Ads the owner likes (follow their tone and wording; never copy them):'];
        foreach ($examples as $asset) {
            $n = $asset->ai_notes ?? [];
            $headline = trim((string) ($n['headline'] ?? ''));
            $caption = trim((string) ($n['caption'] ?? ''));
            $line = '- '.($headline !== '' ? '"'.$oneLine($headline).'"' : '(no words on the image)');
            if ($caption !== '' && $oneLine($caption) !== $oneLine($headline)) {
                $line .= ' with the caption "'.Str::limit($oneLine($caption), 280).'"';
            }
            $about = array_filter([implode(', ', (array) ($n['style'] ?? [])), (string) ($n['topic'] ?? '')], fn (string $s) => $s !== '');
            $lines[] = $line.($about !== [] ? ' ('.implode('; ', $about).')' : '');
        }

        return $lines;
    }

    /**
     * What the model is told about photos for `$campaign`'s photo mode, and the asset ids it may answer with.
     * Only `favourites` and `ai_decides` offer photos, and only the project's own favourites; `$except` (the
     * post's current photo) is left out so "Different photo" gets a different one.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function photos(MarketingCampaign $campaign, Project $project, ?int $except): array
    {
        $mode = $campaign->photo_mode;
        if ($mode === MarketingCampaign::PHOTO_PER_POST) {
            return ['Photos: the owner adds a photo to each post. Set asset_id to null.', []];
        }
        if ($mode !== MarketingCampaign::PHOTO_FAVOURITES && $mode !== MarketingCampaign::PHOTO_AI_DECIDES) {
            return ['Photos: these posts are text only. Set asset_id to null.', []];
        }

        $favourites = MarketingAsset::query()->where('project_id', $project->id)->favouritePhotos()
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->limit(self::MAX_PHOTOS)->get(['id', 'original_name', 'ai_notes']);
        if ($favourites->isEmpty()) {
            return ['Photos: there are no favourite photos yet. Set asset_id to null.', []];
        }

        $intro = $mode === MarketingCampaign::PHOTO_FAVOURITES
            ? 'Photos: for each caption pick the favourite photo that fits it best, by its id (or null if none fits):'
            : 'Photos: for each caption pick a favourite photo by its id, or null to post it as text only:';
        // What the photo shows (MK-18's read) when known; the file name only while it is unread or the read failed.
        $list = $favourites->map(function (MarketingAsset $a) {
            $description = trim((string) ($a->ai_notes['description'] ?? ''));

            return "- id {$a->id}: ".($description !== '' ? $description : $a->original_name);
        })->all();

        return [implode("\n", [$intro, ...$list]), array_values($favourites->pluck('id')->map(fn ($id) => (int) $id)->all())];
    }

    /**
     * Phrases as a quoted, comma-separated list.
     *
     * @param  list<string>  $phrases
     */
    private function quoted(array $phrases): string
    {
        return implode(', ', array_map(fn (string $p) => '"'.trim($p).'"', $phrases));
    }
}
