<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds one post's ad as a self-contained HTML page (MK-5): the campaign's ad style (gallery, vault or poster; one-off
 * posts use gallery) drawing the spec's layout at one size, in the project's brand. Everything the page needs is
 * inside it — CSS, the brand's font files and the post's photo as data URLs — so the renderer opens it over file://
 * with no network at all.
 *
 * A photo layout whose photo is gone (deleted, another project's, its file missing) is drawn as the text card, so a
 * render never shows an empty photo slot.
 */
class BuildAdHtml
{
    /** The mockup family letter each style's CSS is written against (docs/mockups/MK-5/option-{a,b,c}.html). */
    private const LETTERS = [MarketingCampaign::AD_STYLE_GALLERY => 'A', MarketingCampaign::AD_STYLE_VAULT => 'B', MarketingCampaign::AD_STYLE_POSTER => 'C'];

    /** The class suffix each layout's CSS is written against. */
    private const SUFFIXES = ['photo_headline' => 'photo', 'text_card' => 'text', 'question' => 'q', 'spotlight' => 'spot', 'stat' => 'stat'];

    /** The crops a spec may ask for, as CSS object-position values. Anything else is centred. */
    public const CROPS = ['center', 'top', 'bottom', 'left', 'right'];

    /**
     * The card shadow for each brand shadow (MarketingBrand::SHADOWS). `halo` is the styles' own --halo, the kit's.
     */
    private const SHADOWS = ['none' => 'none', 'soft' => '0 24px 60px -24px rgba(0,0,0,.28)'];

    /**
     * The longest headline each layout draws, in characters: what its box holds at the CSS shrink-to-fit's minimum
     * size (measured with Instrument Sans, all three styles, both sizes, worst cases in
     * tests/Browser/Marketing/AdRendererTest.php). A longer one is cut at a word with "…" so it can never be clipped
     * mid-line; MK-19's art director should write within these.
     */
    public const MAX_HEADLINE = ['photo_headline' => 100, 'text_card' => 100, 'question' => 100, 'spotlight' => 50, 'stat' => 14];

    /**
     * Each character's advance in em at the headline's weight (600, no letter-spacing), rounded up: Instrument Sans,
     * measured in Chromium, digits the wider of proportional and tabular. The CSS shrink-to-fit sizes the headline from
     * the sum (see .fit in css/base.css); a character not listed reads as its ASCII form's width, else 1em.
     */
    private const GLYPH_EMS = [
        '0' => .69, '1' => .6, '2' => .6, '3' => .6, '4' => .62, '5' => .6, '6' => .63, '7' => .6, '8' => .61,
        '9' => .63, ' ' => .2, '!' => .27, '"' => .43, '#' => .73, '$' => .64, '%' => .79, '&' => .77, '\'' => .23,
        '(' => .34, ')' => .35, '*' => .42, '+' => .6, ',' => .25, '-' => .46, '.' => .25, '/' => .32, ':' => .28,
        ';' => .28, '<' => .6, '=' => .6, '>' => .6, '?' => .58, '@' => .87, 'A' => .74, 'B' => .65, 'C' => .75,
        'D' => .76, 'E' => .63, 'F' => .6, 'G' => .77, 'H' => .73, 'I' => .26, 'J' => .42, 'K' => .72, 'L' => .59,
        'M' => .9, 'N' => .73, 'O' => .8, 'P' => .67, 'Q' => .81, 'R' => .67, 'S' => .64, 'T' => .67, 'U' => .71,
        'V' => .74, 'W' => 1.08, 'X' => .71, 'Y' => .7, 'Z' => .63, '[' => .44, '\\' => .45, ']' => .44, '^' => .55,
        '_' => .51, '`' => .36, 'a' => .55, 'b' => .63, 'c' => .55, 'd' => .63, 'e' => .58, 'f' => .38, 'g' => .63,
        'h' => .62, 'i' => .26, 'j' => .26, 'k' => .56, 'l' => .26, 'm' => .95, 'n' => .62, 'o' => .6, 'p' => .63,
        'q' => .63, 'r' => .39, 's' => .51, 't' => .42, 'u' => .6, 'v' => .55, 'w' => .72, 'x' => .59, 'y' => .55,
        'z' => .51, '{' => .44, '|' => .24, '}' => .39, '~' => .55, '…' => .73, '’' => .28, '‘' => .25, '“' => .45,
        '”' => .48, '—' => .86, '–' => .56, '€' => .72, '£' => .6,
    ];

    /** A font name safe to print inside a CSS string: what Settings accepts (letters, digits, spaces, hyphens). */
    private const FONT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 \-]{0,63}$/D';

    /** @var array<string, string> the @font-face rules per family, read once per process */
    private static array $fontFaces = [];

    /**
     * @param  SaveBrand  $brands  the project's brand, or the defaults when it has none
     */
    public function __construct(private SaveBrand $brands) {}

    /**
     * The HTML page for `$post`'s ad from `$spec` at `$size` (a MarketingPost::RENDER_SIZES key).
     *
     * Side effects: reads the photo's file and the built font files from disk.
     *
     * @param  array<string, mixed>  $spec  an ad spec (MarketingPost::AD_SPEC_KEYS); missing keys read as empty
     *
     * @throws InvalidArgumentException for a size that is not one of RENDER_SIZES
     */
    public function handle(MarketingPost $post, array $spec, string $size): string
    {
        if (! array_key_exists($size, MarketingPost::RENDER_SIZES)) {
            throw new InvalidArgumentException("Unknown ad size {$size}.");
        }

        $project = $post->project()->firstOrFail();
        $brand = $this->brands->brandOf($project);
        $values = (array) $brand->values + ['name' => null] + MarketingBrand::DEFAULTS;
        $style = $post->campaign?->adStyle() ?? MarketingCampaign::AD_STYLE_GALLERY;
        $asset = $this->photo($post, $spec);
        $layout = $this->effectiveLayout($spec, $asset !== null);
        $headline = Str::limit(trim((string) ($spec['headline'] ?? '')), self::MAX_HEADLINE[$layout], '…', preserveWords: true);
        $font = preg_match(self::FONT_PATTERN, $values['font']) ? $values['font'] : MarketingBrand::DEFAULTS['font'];
        $crop = in_array($spec['crop'] ?? null, self::CROPS, true) ? $spec['crop'] : 'center';

        $ad = [
            'style' => $style,
            'letter' => self::LETTERS[$style],
            'layout' => $layout,
            'suffix' => self::SUFFIXES[$layout],
            'size' => $size === 'square' ? 'sq' : 'tall',
            'headline' => $headline,
            'chars' => max(1, mb_strlen($headline)),
            // The headline's width and its widest word's (plus the space before it: what a wrapped line can waste) in
            // em, for the CSS shrink-to-fit.
            'ems' => $this->ems($headline),
            'word_ems' => round(max(0, ...array_map(fn (string $word) => $this->ems($word), preg_split('/\s+/u', $headline) ?: [''])) + self::GLYPH_EMS[' '], 2),
            'subline' => $this->text($spec['subline'] ?? null),
            'cta' => $this->text($spec['cta'] ?? null),
            'badge' => $this->text($spec['badge'] ?? null),
            // The campaign's name is the eyebrow and the poster's footer tag, as in the mockups; a one-off has none.
            'eyebrow' => (string) $post->campaign?->name,
            'brand' => (string) ($values['name'] ?: $project->name),
            'logo' => $this->logo($brand->logo_svg),
            'photo' => $asset === null || ! in_array($layout, MarketingPost::PHOTO_LAYOUTS, true) ? null : $this->dataUrl($asset),
            'crop' => $crop,
        ];

        return view('marketing.ads.ad', [
            'ad' => $ad,
            'vars' => $this->vars($values, $font),
            'fonts' => $this->fontFaces($font),
            'css' => $this->css($style),
        ])->render();
    }

    /**
     * The layout `$post` will actually be drawn in from `$spec`: the spec's, or the text card when it is unknown or
     * needs a photo the post cannot give (for the render log).
     *
     * @param  array<string, mixed>  $spec
     */
    public function layoutFor(MarketingPost $post, array $spec): string
    {
        return $this->effectiveLayout($spec, $this->photo($post, $spec) !== null);
    }

    /**
     * `$text`'s width in em at the headline's weight, from GLYPH_EMS; never under 1, so an empty headline divides
     * safely.
     */
    private function ems(string $text): float
    {
        $ems = 0.0;
        foreach (mb_str_split($text) as $char) {
            $ems += self::GLYPH_EMS[$char] ?? self::GLYPH_EMS[Str::ascii($char)] ?? 1.0;
        }

        // Rounded up to 0.01 em; the inner round drops float noise (4.96 summing to 4.9600001) before the ceil.
        return ceil(round(max(1.0, $ems) * 100, 6)) / 100;
    }

    /**
     * The spec's layout when known and drawable, else the text card.
     *
     * @param  array<string, mixed>  $spec
     */
    private function effectiveLayout(array $spec, bool $hasPhoto): string
    {
        $layout = in_array($spec['layout'] ?? null, MarketingPost::AD_LAYOUTS, true) ? $spec['layout'] : 'text_card';

        return in_array($layout, MarketingPost::PHOTO_LAYOUTS, true) && ! $hasPhoto ? 'text_card' : $layout;
    }

    /**
     * The spec's photo when it is one of the post's project's photos and its file is there, else null.
     *
     * @param  array<string, mixed>  $spec
     */
    private function photo(MarketingPost $post, array $spec): ?MarketingAsset
    {
        $id = $spec['asset_id'] ?? null;
        if (! is_int($id)) {
            return null;
        }
        // Only the post's own project's photos: an id from anywhere else is drawn as if there were no photo.
        $asset = MarketingAsset::where('project_id', $post->project_id)->where('purpose', MarketingAsset::PURPOSE_PHOTO)->find($id);

        return $asset !== null && Storage::disk('local')->exists($asset->path) ? $asset : null;
    }

    /**
     * The asset's bytes as a data URL, so the page needs no file or network read for it.
     */
    private function dataUrl(MarketingAsset $asset): string
    {
        return 'data:'.$asset->mime.';base64,'.base64_encode((string) Storage::disk('local')->get($asset->path));
    }

    /**
     * A spec text trimmed, or null when empty.
     */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * The brand's sanitised logo, without its namespace attributes: inline SVG in HTML needs none, and the page then
     * carries no URL of any kind.
     */
    private function logo(?string $svg): ?string
    {
        if ($svg === null || trim($svg) === '') {
            return null;
        }

        return (string) preg_replace('/\s+xmlns(:[A-Za-z]+)?="[^"]*"/', '', $svg);
    }

    /**
     * The brand values as CSS custom properties on the ad. Colours and the font are checked against what Settings
     * accepts before they go into a style attribute; anything else falls back to the default.
     *
     * @param  array<string, mixed>  $values
     */
    private function vars(array $values, string $font): string
    {
        $colour = fn (string $key) => is_string($values[$key] ?? null) && preg_match(MarketingBrand::HEX_PATTERN, $values[$key])
            ? MarketingBrand::normaliseHex($values[$key]) : MarketingBrand::DEFAULTS[$key];
        $vars = sprintf("--accent:%s;--ink:%s;--surface:%s;--font:'%s';--radius:%dpx",
            $colour('accent'), $colour('ink'), $colour('surface'), $font, max(0, min(32, (int) $values['radius'])));
        $shadow = self::SHADOWS[$values['shadow'] ?? 'halo'] ?? null;

        return $shadow === null ? $vars : $vars.';--halo:'.$shadow;
    }

    /**
     * The shared CSS plus `$style`'s, from resources/views/marketing/ads/css.
     */
    private function css(string $style): string
    {
        $dir = resource_path('views/marketing/ads/css');

        return file_get_contents("{$dir}/base.css")."\n".file_get_contents("{$dir}/{$style}.css");
    }

    /**
     * @font-face rules embedding `$family`'s files as data URLs, from the fonts the app's own build ships
     * (public/build/fonts-manifest.json, written by Vite's font plugin). A family the build does not ship gets no
     * rule, and the ad falls back to the stack in css/base.css.
     */
    private function fontFaces(string $family): string
    {
        $key = strtolower($family);
        if (isset(self::$fontFaces[$key])) {
            return self::$fontFaces[$key];
        }

        $manifest = public_path('build/fonts-manifest.json');
        $preloads = is_file($manifest) ? (json_decode((string) file_get_contents($manifest), true)['preloads'] ?? []) : [];
        $rules = '';
        foreach (is_array($preloads) ? $preloads : [] as $font) {
            $file = public_path('build/'.($font['file'] ?? ''));
            if (strcasecmp((string) ($font['family'] ?? ''), $family) !== 0 || ! str_ends_with($file, '.woff2') || ! is_file($file)) {
                continue;
            }
            $rules .= sprintf("@font-face{font-family:'%s';font-style:%s;font-weight:%d;src:url(data:font/woff2;base64,%s) format('woff2')}\n",
                $family, ($font['style'] ?? 'normal') === 'italic' ? 'italic' : 'normal', (int) ($font['weight'] ?? 400), base64_encode((string) file_get_contents($file)));
        }

        return self::$fontFaces[$key] = $rules;
    }
}
