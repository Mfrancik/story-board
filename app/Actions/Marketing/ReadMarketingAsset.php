<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingAiException;
use App\Models\MarketingAsset;
use App\Services\Marketing\MarketingAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one vision read of a marketing image (MK-18), run by the ReadInspiration job. The image goes to Claude once,
 * downscaled, through MK-8's MarketingAi; what comes back is stored in `ai_notes` and never asked for again:
 * - an inspiration image → `headline` (its words), `style` (one or two notes on how it is written) and `topic`, which
 *   Fill quotes as examples of tone and copy (PickInspirationExamples);
 * - a favourite photo → `description` (what is in it, at most DESCRIPTION_MAX characters), which Fill's photo list
 *   shows instead of the file name, so Claude picks photos by what they show.
 *
 * One read per image, not per draft, is what keeps this cheap. Nothing about layout is asked for: matching an ad's
 * look waits for the designer (phase 3).
 */
class ReadMarketingAsset
{
    /** The long side the image is cut to before it is sent: plenty to read a headline, a fraction of the tokens. */
    public const MAX_SIDE = 1024;

    /** The longest photo description kept, so the photo list in every prompt stays short. */
    public const DESCRIPTION_MAX = 120;

    /** The fixed system part of every read. */
    public const SYSTEM = "You read images for a small business's own Facebook marketing. Be literal and brief. Reply with JSON only, in the schema given.";

    /** The reply to an inspiration read. */
    public const INSPIRATION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'headline' => ['type' => 'string'],
            'style' => ['type' => 'array', 'items' => ['type' => 'string']],
            'topic' => ['type' => 'string'],
        ],
        'required' => ['headline', 'style', 'topic'],
        'additionalProperties' => false,
    ];

    /** The reply to a favourite photo's read. */
    public const PHOTO_SCHEMA = [
        'type' => 'object',
        'properties' => ['description' => ['type' => 'string']],
        'required' => ['description'],
        'additionalProperties' => false,
    ];

    /**
     * @param  MarketingAiClient  $ai  the model
     */
    public function __construct(private MarketingAiClient $ai) {}

    /**
     * Read `$asset` once and store what Claude saw. A failure is recorded on the asset (status `failed`, the reason
     * in `error`) for the page's Retry, and logged; it is not thrown.
     *
     * Side effects: one Claude call with the downscaled image; writes `ai_notes`; logs
     * `marketing.inspiration.read_succeeded|read_failed` or `marketing.photo.read_succeeded|read_failed` with tokens
     * and `cost_cents`.
     */
    public function handle(MarketingAsset $asset): MarketingAsset
    {
        $photo = $asset->purpose === MarketingAsset::PURPOSE_PHOTO;

        try {
            $image = $this->image($asset);
            $text = $photo
                ? 'This is one of the owner\'s favourite photos. Say what is in the picture in one plain sentence of at most '
                    .self::DESCRIPTION_MAX.' characters, so captions can pick the right photo by what it shows.'
                : "This is an ad or post the owner likes. Read it once so later captions can follow its tone and copy:\n"
                    ."- headline: the main words on the image, exactly as written (empty if it has none)\n"
                    ."- style: one or two short notes on how it is written, e.g. \"question to the audience\", \"one bold number\"\n"
                    .'- topic: what it is about, in two to four words';
            $reply = $this->ai->complete(self::SYSTEM, [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $image]],
                ['type' => 'text', 'text' => $text],
            ], $photo ? self::PHOTO_SCHEMA : self::INSPIRATION_SCHEMA);
            $notes = $photo ? $this->photoNotes($reply['data']) : $this->inspirationNotes($reply['data']);
        } catch (MarketingAiException $e) {
            Log::error($this->event($asset, 'read_failed'), ['asset_id' => $asset->id, 'project_id' => $asset->project_id, 'reason' => $e->reason,
                'status' => $e->status, 'error_type' => $e->errorType, 'duration_ms' => $e->durationMs]);
            $this->markFailed($asset, $e->reason);

            return $asset;
        }

        $meta = $reply['meta'];
        // Re-read first: the owner may have hearted the image while Claude was reading it.
        $asset->refresh();
        $stored = [...($asset->ai_notes ?? []), ...$notes, 'status' => MarketingAsset::READ_DONE,
            'read' => ['model' => $meta['model'], 'input_tokens' => $meta['input_tokens'], 'output_tokens' => $meta['output_tokens'],
                'cost_micro_usd' => $meta['cost_micro_usd'], 'read_at' => now()->toIso8601String()]];
        unset($stored['error']);
        $asset->ai_notes = $stored;
        $asset->save();

        Log::info($this->event($asset, 'read_succeeded'), ['asset_id' => $asset->id, 'project_id' => $asset->project_id, 'model' => $meta['model'],
            'input_tokens' => $meta['input_tokens'], 'output_tokens' => $meta['output_tokens'],
            'cost_cents' => $meta['cost_micro_usd'] === null ? null : round($meta['cost_micro_usd'] / 10000, 4), 'duration_ms' => $meta['duration_ms']]);

        return $asset;
    }

    /**
     * Record that `$asset`'s read failed, with `$reason`, so the page offers Retry.
     *
     * Side effects: writes `ai_notes`.
     */
    public function markFailed(MarketingAsset $asset, string $reason): void
    {
        $asset->refresh();
        $asset->ai_notes = [...($asset->ai_notes ?? []), 'status' => MarketingAsset::READ_FAILED, 'error' => $reason];
        $asset->save();
    }

    /**
     * The log event for `$asset`'s read: `marketing.photo.<suffix>` for a favourite photo, else
     * `marketing.inspiration.<suffix>`.
     */
    public function event(MarketingAsset $asset, string $suffix): string
    {
        return ($asset->purpose === MarketingAsset::PURPOSE_PHOTO ? 'marketing.photo.' : 'marketing.inspiration.').$suffix;
    }

    /**
     * The asset's image as a base64 JPEG whose long side is at most MAX_SIDE, on white (a PNG's transparency would
     * otherwise turn black).
     *
     * @throws MarketingAiException `missing_file` when the file is gone, `unreadable_image` when it is not an image
     */
    private function image(MarketingAsset $asset): string
    {
        $disk = Storage::disk('local');
        if ($asset->path === '' || ! $disk->exists($asset->path)) {
            throw new MarketingAiException('The image file is missing.', 'missing_file');
        }
        $source = @imagecreatefromstring((string) $disk->get($asset->path));
        if ($source === false) {
            throw new MarketingAiException('The file could not be read as an image.', 'unreadable_image');
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 80);

        return base64_encode((string) ob_get_clean());
    }

    /**
     * The inspiration notes from a reply: the headline (may be empty: an ad with no words), at most two style notes
     * and the topic, each trimmed to a sensible length.
     *
     * @param  array<string, mixed>  $data
     * @return array{headline: string, style: list<string>, topic: string}
     *
     * @throws MarketingAiException `invalid_reply` when a field is missing or of the wrong type
     */
    private function inspirationNotes(array $data): array
    {
        if (! is_string($data['headline'] ?? null) || ! is_array($data['style'] ?? null) || ! is_string($data['topic'] ?? null)) {
            throw new MarketingAiException('Claude did not send back a headline, style and topic.', 'invalid_reply');
        }
        $style = array_values(array_filter(array_map(fn ($s) => is_string($s) ? Str::limit(trim($s), 60, '') : '', $data['style']), fn ($s) => $s !== ''));

        return [
            'headline' => Str::limit(trim($data['headline']), 200, ''),
            'style' => array_slice($style, 0, 2),
            'topic' => Str::limit(trim($data['topic']), 80, ''),
        ];
    }

    /**
     * The photo note from a reply: its description, cut to DESCRIPTION_MAX characters.
     *
     * @param  array<string, mixed>  $data
     * @return array{description: string}
     *
     * @throws MarketingAiException `invalid_reply` when there is no description
     */
    private function photoNotes(array $data): array
    {
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : '';
        if ($description === '') {
            throw new MarketingAiException('Claude did not describe the photo.', 'invalid_reply');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            // Cut at the last word that fits, and say it was cut.
            $description = rtrim(Str::limit($description, self::DESCRIPTION_MAX - 1, '', preserveWords: true), ' ,.;').'…';
        }

        return ['description' => $description];
    }
}
