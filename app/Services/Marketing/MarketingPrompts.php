<?php

namespace App\Services\Marketing;

use App\Actions\Marketing\BuildAdHtml;
use App\Actions\Marketing\BuildTasteExamples;
use App\Actions\Marketing\NormaliseAdSpec;
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
 * Two parts. The system part is fixed per project — the brand's voice, the copy rules and the menu of ad layouts —
 * so it is the cached prefix of every call. The user part is the call: the campaign's brief, its topics (rotated so
 * recent ones are not repeated), the owner's taste examples, what to do about photos and how to pick the ads'
 * layouts. Claude art-directs each post's ad (MK-19): it picks one of MK-5's layouts and writes the words on the
 * image. The brand's look itself — its colours, fonts and the campaign's ad style — is fixed and never the model's to
 * choose (owner, 2026-10-12); only the style's name is given, as context.
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

    /** One caption, its photo id (or null for none) and its ad (MK-19). */
    public const CAPTION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'caption' => ['type' => 'string'],
            'asset_id' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
            'ad' => self::AD_SCHEMA,
        ],
        'required' => ['caption', 'asset_id', 'ad'],
        'additionalProperties' => false,
    ];

    /**
     * The ad Claude art-directs for a caption (MK-19): one of MK-5's layouts, the words on the image and the crop.
     * Lengths are in the descriptions and the prompt, not as maxLength: structured outputs do not enforce string
     * lengths, so NormaliseAdSpec cuts whatever comes back.
     */
    public const AD_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'layout' => ['type' => 'string', 'enum' => MarketingPost::AD_LAYOUTS],
            'headline' => ['type' => 'string', 'description' => 'The big line on the image, at most 60 characters.'],
            'subline' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'At most 120 characters.'],
            'cta' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'A call to action, at most 24 characters.'],
            'crop' => ['type' => 'string', 'enum' => BuildAdHtml::CROPS],
            'badge' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'The coin spotlight\'s tag, at most 24 characters.'],
        ],
        'required' => ['layout', 'headline', 'subline', 'cta', 'crop', 'badge'],
        'additionalProperties' => false,
    ];

    /** When each of MK-5's layouts suits a post: the art director's menu, beside MarketingPost::AD_LAYOUT_LABELS. */
    public const LAYOUT_USE_WHEN = [
        'photo_headline' => 'use when the photo is the point; one short headline goes with it.',
        'text_card' => 'use when the words carry the post on their own.',
        'question' => 'use when the post asks people something; the headline is the question.',
        'spotlight' => 'use when showing off one coin from its photo; the badge can tag it (a grade, "Key date").',
        'stat' => 'use when one number is the point; the headline is the number and the subline says what it means.',
    ];

    /**
     * The `ad` of a reply (MK-19) as it is kept in `generation_meta.ad`: the record of what Claude chose, re-read when
     * the post is re-specced (QueuePostRender). Only AD_SCHEMA's keys, so a stray field is never stored; null when the
     * reply had no ad.
     *
     * @return array<string, mixed>|null
     */
    public static function adChoice(mixed $ad): ?array
    {
        return is_array($ad) ? array_intersect_key($ad, self::AD_SCHEMA['properties']) : null;
    }

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
        // Since MK-19 the layout and the words on the image are its job; the look they are drawn in still is not.
        $lines[] = '- How a post looks is fixed by the brand and is not part of your job. You choose only each ad\'s layout and its words.';

        return implode("\n", [...$lines, '', ...$this->adMenu(), '', 'Reply with JSON only, in the schema given.']);
    }

    /**
     * The art director's brief (MK-19), fixed for every project: what an ad is, MK-5's layouts with when to use each,
     * and the rules for the words on the image. Part of the cached system prefix.
     *
     * @return list<string>
     */
    private function adMenu(): array
    {
        $lines = [
            'Each caption also gets an ad: an image of the post with a few words on it, drawn in the brand\'s fixed look.',
            'You choose its layout and write its words (the ad object). Layouts:',
        ];
        foreach (MarketingPost::AD_LAYOUTS as $layout) {
            $cap = min(NormaliseAdSpec::HEADLINE_MAX, BuildAdHtml::MAX_HEADLINE[$layout]);
            // Only a layout with less room than the usual headline says so; the usual limit is given once below.
            $lines[] = "- {$layout} (".MarketingPost::AD_LAYOUT_LABELS[$layout].'): '.self::LAYOUT_USE_WHEN[$layout]
                .($cap < NormaliseAdSpec::HEADLINE_MAX ? " Its headline is at most {$cap} characters." : '');
        }

        return [
            ...$lines,
            implode(' and ', MarketingPost::PHOTO_LAYOUTS).' are built around the post\'s photo; without one they are drawn as the text card.',
            '',
            'Words on the ad (the copy rules above apply to them too):',
            '- headline: at most '.NormaliseAdSpec::HEADLINE_MAX.' characters, and the shorter the better. The one big line; not a copy of the caption.',
            '- subline: at most '.NormaliseAdSpec::SUBLINE_MAX.' characters, or null.',
            '- cta: a call to action of at most '.NormaliseAdSpec::CTA_MAX.' characters, such as "Share yours below", or null.',
            '- badge: at most '.NormaliseAdSpec::BADGE_MAX.' characters, shown on the coin spotlight only, or null.',
            '- No hashtags. No emoji unless the voice above asks for them.',
            '- crop: which part of the photo to keep when it is cropped: '.implode(', ', BuildAdHtml::CROPS).'.',
        ];
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
            'user' => implode("\n", [...$lines, ...$this->tasteLines($campaign), ...$this->inspirationLines($examples), '', $photo, '', ...$this->adLines($campaign)]),
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
        $lines = [...$lines, '', ...$this->currentAd($post)];

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
     * How to pick a draft call's layouts (MK-19), after the taste and inspiration blocks so both inform the choice
     * without being changed: the campaign's ad style for context, the two-layout rule and what the photo mode allows.
     *
     * @return list<string>
     */
    private function adLines(MarketingCampaign $campaign): array
    {
        $photo = implode(' or ', MarketingPost::PHOTO_LAYOUTS);
        $lines = [
            'Ads: this campaign\'s ad style is '.ucfirst($campaign->adStyle()).'. The style is fixed; you choose the layouts.',
            'Use at least two different layouts across the '.self::DRAFTS.' drafts, each chosen for what its caption says. Let the owner\'s taste and the ads they like (above) guide the words and the choice.',
        ];
        $lines[] = match ($campaign->photo_mode) {
            MarketingCampaign::PHOTO_PER_POST => "Every post here gets the owner's own photo later, so {$photo} suit any draft.",
            MarketingCampaign::PHOTO_FAVOURITES, MarketingCampaign::PHOTO_AI_DECIDES => "Use {$photo} only for a draft you gave a photo.",
            default => "These posts have no photo: do not use {$photo}.",
        };

        return $lines;
    }

    /**
     * The post's current ad, for a revise call (MK-19), so the owner's note can change its words or its layout.
     *
     * @return list<string>
     */
    private function currentAd(MarketingPost $post): array
    {
        $spec = $post->adSpec();
        $ask = 'Send the ad back too: change its words or its layout where the owner\'s note asks, or where the new caption needs it.';
        if ($spec === null) {
            return ['It has no ad yet: write one for the new caption.', $ask];
        }

        $words = ['headline "'.$spec['headline'].'"'];
        foreach (['subline', 'cta', 'badge'] as $field) {
            if (is_string($spec[$field]) && $spec[$field] !== '') {
                $words[] = $field.' "'.$spec[$field].'"';
            }
        }
        $label = MarketingPost::AD_LAYOUT_LABELS[$spec['layout']] ?? $spec['layout'];

        return ["Its ad now: {$label} (layout {$spec['layout']}), ".implode(', ', $words).', crop '.($spec['crop'] ?? 'center').'.', $ask];
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
