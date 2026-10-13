<?php

namespace App\Livewire\Marketing\Concerns;

use App\Models\MarketingAsset;

/**
 * The one set of upload rules and messages for every Marketing image picker — Settings' favourites (MK-2), the
 * studio's per-post photo (MK-9) and the Inspiration wall (MK-18) — so the type and size limits, and the words that
 * explain them, live in one place instead of drifting apart per page.
 */
trait ValidatesMarketingImages
{
    /**
     * The rules one uploaded image must pass: a JPG, PNG or WebP of at most MarketingAsset::MAX_KB.
     *
     * @return list<string>
     */
    protected function imageRules(): array
    {
        return ['file', 'mimetypes:'.implode(',', MarketingAsset::PHOTO_MIMES), 'max:'.MarketingAsset::MAX_KB];
    }

    /**
     * The messages for imageRules() on `$field` (e.g. `photos.*`), naming the files as `$noun` ("Photos", "Ads").
     *
     * @return array<string, string>
     */
    protected function imageMessages(string $field, string $noun): array
    {
        return [
            $field.'.mimetypes' => $noun.' must be JPG, PNG or WebP images.',
            $field.'.max' => $this->imageTooBig($noun),
        ];
    }

    /**
     * The size message alone: also what replaces Livewire's own "12288 kilobytes" when a file is over its temporary
     * upload limit, before the page's rules ever run.
     */
    protected function imageTooBig(string $noun): string
    {
        return $noun.' can be '.intdiv(MarketingAsset::MAX_KB, 1024).' MB at most.';
    }
}
