<?php

namespace App\Actions\Marketing;

use App\Models\MarketingPost;

/**
 * The ad spec a post gets when Claude wrote none it could use (MK-5; MK-19's art director writes the rest, through
 * NormaliseAdSpec): a one-off, a post drafted before MK-19, or an unknown layout. A post with a photo is the photo + headline
 * layout, a post without one the bold text card; the headline is the caption's first line and the subline the rest,
 * joined and trimmed. Pure: reads only the post it is given.
 */
class BuildDefaultAdSpec
{
    /**
     * The default spec for `$post`, keyed as MarketingPost::AD_SPEC_KEYS.
     *
     * @return array{layout: string, headline: string, subline: string|null, cta: null, asset_id: int|null, crop: string|null, badge: null}
     */
    public function handle(MarketingPost $post): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $post->caption) ?: []), fn (string $l) => $l !== ''));
        $subline = implode(' ', array_slice($lines, 1));
        $photo = $post->asset_id;

        return [
            'layout' => $photo !== null ? 'photo_headline' : 'text_card',
            'headline' => $lines[0] ?? '',
            'subline' => $subline === '' ? null : $subline,
            'cta' => null,
            'asset_id' => $photo,
            'crop' => $photo !== null ? 'center' : null,
            'badge' => null,
        ];
    }
}
