<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Keeps a post's rendered ad (MK-5): each size's PNG becomes one of the project's marketing assets with purpose
 * `render` (StoreMarketingAsset, so files land beside the photos under `marketing/{project}/renders/`), the post's
 * `ad.render` points at them, and the renders they replace are deleted with their files (DeleteMarketingAsset). New
 * files are stored before the old ones go, so a post never shows a broken image mid-render.
 */
class StorePostRenders
{
    /** The file-name label for each size, as Facebook names its shapes. */
    public const LABELS = ['square' => '1x1', 'portrait' => '4x5'];

    /**
     * @param  StoreMarketingAsset  $store  writes each PNG and its asset row
     * @param  DeleteMarketingAsset  $delete  removes a replaced render, row then file
     */
    public function __construct(private StoreMarketingAsset $store, private DeleteMarketingAsset $delete) {}

    /**
     * Store `$pngs` (PNG bytes by MarketingPost::RENDER_SIZES key) as `$post`'s render and drop the previous one.
     *
     * Side effects: writes files and `marketing_assets` rows (removed again if any write fails), the post's `ad`, and
     * deletes the replaced renders' rows and files; StoreMarketingAsset and DeleteMarketingAsset log each.
     *
     * @param  array<string, string>  $pngs
     */
    public function handle(MarketingPost $post, array $pngs): MarketingPost
    {
        $project = $post->project()->firstOrFail();
        $new = [];
        try {
            foreach (array_keys(MarketingPost::RENDER_SIZES) as $size) {
                $new[$size] = $this->storePng($post, $project, $size, $pngs[$size] ?? '');
            }
        } catch (Throwable $e) {
            // Half a render is no render: keep the post on its previous images.
            array_map(fn (MarketingAsset $asset) => $this->delete->handle($asset), $new);

            throw $e;
        }

        // Re-read: the spec may have been rewritten while this render ran (its own render is queued after this one).
        $post->refresh();
        $old = array_filter(array_map(fn (string $size) => $post->renderId($size), array_keys(MarketingPost::RENDER_SIZES)));
        $post->update(['ad' => [...(array) $post->ad, 'render' => [
            'status' => MarketingPost::RENDER_RENDERED, ...array_map(fn (MarketingAsset $a) => $a->id, $new), 'at' => now()->toIso8601String(),
        ]]]);

        // Only this project's renders: an id that somehow pointed at a photo must never delete it.
        MarketingAsset::whereKey($old)->where('project_id', $post->project_id)->where('purpose', MarketingAsset::PURPOSE_RENDER)
            ->get()->each(fn (MarketingAsset $asset) => $this->delete->handle($asset));

        return $post;
    }

    /**
     * Record that `$post`'s render failed with `$reason`; its previous images, if any, stay.
     *
     * Side effects: writes the post's `ad.render` (status failed, reason).
     */
    public function failed(MarketingPost $post, string $reason): void
    {
        // A post deleted while it rendered has nothing left to mark.
        $post = MarketingPost::find($post->id);
        if ($post === null) {
            return;
        }
        $render = is_array($post->ad['render'] ?? null) ? $post->ad['render'] : [];
        $post->update(['ad' => [...(array) $post->ad, 'render' => [
            ...$render, 'status' => MarketingPost::RENDER_FAILED, 'reason' => $reason, 'at' => now()->toIso8601String(),
        ]]]);
    }

    /**
     * Write one size's PNG through StoreMarketingAsset, from a temporary file it can read like an upload.
     */
    private function storePng(MarketingPost $post, Project $project, string $size, string $png): MarketingAsset
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ad-render-');
        try {
            file_put_contents($tmp, $png);
            $file = new UploadedFile($tmp, "post-{$post->id}-".self::LABELS[$size].'.png', 'image/png', null, true);

            return $this->store->handle($project, $file, MarketingAsset::PURPOSE_RENDER, favourite: false);
        } finally {
            @unlink($tmp);
        }
    }
}
