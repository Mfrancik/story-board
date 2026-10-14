<?php

namespace App\Actions\Marketing;

use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * Turns the ad spec Claude wrote for a post (MK-19, the art director) into one MK-5 can draw, against the post as it
 * is now: an unknown layout (or no spec at all) gives the post MK-5's default spec, a photo layout with no photo
 * becomes the text card, over-long words are cut at a word boundary, and a bad crop is centred. Every fix is logged,
 * so a prompt that keeps producing bad specs shows up in the logs rather than in the ads.
 *
 * It is re-run whenever the post is re-specced (QueuePostRender), from the spec Claude chose as recorded in
 * `generation_meta.ad`, so a photo added or removed later moves the post between its photo layout and the text card.
 */
class NormaliseAdSpec
{
    /** The longest headline any layout gets; a layout's own cap (BuildAdHtml::MAX_HEADLINE) can be lower. */
    public const HEADLINE_MAX = 60;

    /** The longest subline. */
    public const SUBLINE_MAX = 120;

    /** The longest call to action. */
    public const CTA_MAX = 24;

    /** The longest badge (the coin spotlight's tag). */
    public const BADGE_MAX = 24;

    /**
     * @param  BuildDefaultAdSpec  $default  the spec a post gets when Claude's is unusable
     */
    public function __construct(private BuildDefaultAdSpec $default) {}

    /**
     * The drawable spec for `$post` from `$ad` (Claude's `ad` object, or anything else it sent), keyed as
     * MarketingPost::AD_SPEC_KEYS, with `asset_id` always the post's own photo.
     *
     * Side effects: logs `marketing.ai.ad_spec_fixed` (info: post_id, field, reason) once per fix — reason `missing`,
     * `unknown_layout`, `no_photo`, `empty`, `too_long` or `invalid`.
     *
     * @return array{layout: string, headline: string, subline: string|null, cta: string|null, asset_id: int|null, crop: string|null, badge: string|null}
     */
    public function handle(MarketingPost $post, mixed $ad): array
    {
        if (! is_array($ad)) {
            $this->fixed($post, 'ad', 'missing');

            return $this->default->handle($post);
        }
        $layout = $ad['layout'] ?? null;
        if (! in_array($layout, MarketingPost::AD_LAYOUTS, true)) {
            $this->fixed($post, 'layout', 'unknown_layout');

            return $this->default->handle($post);
        }
        // A photo layout with no photo is drawn as the text card anyway (BuildAdHtml); saying so in the spec keeps the
        // studio's "Layout:" line honest. Not for a draft still waiting for the owner's photo: it is not rendered until
        // the photo comes, and then the layout Claude chose is the one wanted.
        if (in_array($layout, MarketingPost::PHOTO_LAYOUTS, true) && $post->asset_id === null && ! $post->needsPhoto()) {
            $this->fixed($post, 'layout', 'no_photo');
            $layout = 'text_card';
        }

        $headline = $this->words($post, 'headline', $ad['headline'] ?? null, min(self::HEADLINE_MAX, BuildAdHtml::MAX_HEADLINE[$layout]), $this->default->handle($post)['headline']);
        $crop = $ad['crop'] ?? null;
        if (! in_array($crop, BuildAdHtml::CROPS, true)) {
            $this->fixed($post, 'crop', 'invalid');
            $crop = 'center';
        }

        return [
            'layout' => $layout,
            'headline' => (string) $headline,
            'subline' => $this->words($post, 'subline', $ad['subline'] ?? null, self::SUBLINE_MAX),
            'cta' => $this->words($post, 'cta', $ad['cta'] ?? null, self::CTA_MAX),
            'asset_id' => $post->asset_id,
            'crop' => $crop,
            'badge' => $this->words($post, 'badge', $ad['badge'] ?? null, self::BADGE_MAX),
        ];
    }

    /**
     * One piece of on-image copy, on one line and at most `$max` characters, or `$fallback` when there is none. A
     * value that is not text is dropped; one that is too long is cut at the last whole word.
     */
    private function words(MarketingPost $post, string $field, mixed $value, int $max, ?string $fallback = null): ?string
    {
        if ($value !== null && ! is_string($value)) {
            $this->fixed($post, $field, 'invalid');
            $value = null;
        }
        // The ad is one block of type: a line break from the model would only fight the layout's own wrapping.
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($text === '' && $fallback !== null) {
            $this->fixed($post, $field, 'empty');
            $text = trim((string) preg_replace('/\s+/u', ' ', $fallback));
        }
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            $this->fixed($post, $field, 'too_long');
            $text = $this->cut($text, $max);
        }

        return $text;
    }

    /**
     * `$text` cut to at most `$max` characters at the last whole word, without trailing punctuation. A single word
     * longer than `$max` is cut mid-word, as there is no boundary to cut at. Not Str::limit: with preserveWords it
     * drops the last whole word whenever the cut lands just after it ("…toner you| think" loses "you").
     */
    private function cut(string $text, int $max): string
    {
        $head = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($head, ' ');
        $cut = $space !== false && $space > 0 ? mb_substr($head, 0, $space) : mb_substr($text, 0, $max);

        return (string) preg_replace('/[\s,;:\-–—]+$/u', '', $cut);
    }

    /**
     * Log one fix to `$post`'s spec.
     */
    private function fixed(MarketingPost $post, string $field, string $reason): void
    {
        Log::info('marketing.ai.ad_spec_fixed', ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'field' => $field, 'reason' => $reason]);
    }
}
