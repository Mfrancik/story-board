<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Save to Inspiration (MK-18, owner round 5: "if i generate an ad i like i should have a quick way to add to
 * inspirations"): one click on a post in the studio or the library makes it a favourite inspiration, so later drafts
 * follow it. Its photo is copied (deleting either one never takes the other's file); a post with no photo is kept as
 * its text card (no file, TEXT_CARD_MIME). The caption becomes the notes, with no AI read: the words are already
 * known. A post is saved at most once.
 */
class SaveToInspiration
{
    /**
     * Save `$post` as a favourite inspiration. Returns the asset and whether it was created now; a post already saved
     * returns the existing asset and creates nothing.
     *
     * Side effects: copies the photo file and writes a `marketing_assets` row; logs
     * `marketing.inspiration.saved_from_post`, or `marketing.inspiration.save_skipped` (reason `already_saved`).
     *
     * @return array{asset: MarketingAsset, created: bool}
     */
    public function handle(MarketingPost $post): array
    {
        $existing = self::savedFrom($post);
        if ($existing !== null) {
            Log::info('marketing.inspiration.save_skipped', ['post_id' => $post->id, 'asset_id' => $existing->id, 'reason' => 'already_saved']);

            return ['asset' => $existing, 'created' => false];
        }

        $project = $post->project()->firstOrFail();
        $photo = $post->asset;
        $disk = Storage::disk('local');
        $file = $photo !== null && $photo->path !== '' && $disk->exists($photo->path) ? $photo : null;
        $path = '';
        if ($file !== null) {
            $path = "marketing/{$project->name}/inspirations/".Str::uuid().'.'.pathinfo($file->path, PATHINFO_EXTENSION);
            $disk->copy($file->path, $path);
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $post->caption) ?: []), fn (string $l) => $l !== ''));
        $asset = $project->marketingAssets()->create([
            'purpose' => MarketingAsset::PURPOSE_INSPIRATION,
            'is_favourite' => true,
            'path' => $path,
            'original_name' => $file->original_name ?? 'post-'.$post->id.'-text-card',
            'mime' => $file->mime ?? MarketingAsset::TEXT_CARD_MIME,
            'width' => $file?->width,
            'height' => $file?->height,
            'ai_notes' => [
                'status' => MarketingAsset::READ_DONE, 'source' => 'post', 'post_id' => $post->id, 'note' => 'From your post',
                'headline' => $lines[0] ?? '', 'caption' => $post->caption, 'style' => [],
                'topic' => $post->generation_meta['topic'] ?? $post->campaign?->name,
            ],
        ]);

        Log::info('marketing.inspiration.saved_from_post', ['project' => $project->name, 'post_id' => $post->id, 'asset_id' => $asset->id, 'has_photo' => $file !== null]);

        return ['asset' => $asset, 'created' => true];
    }

    /**
     * The inspiration saved from `$post`, if any.
     */
    public static function savedFrom(MarketingPost $post): ?MarketingAsset
    {
        return MarketingAsset::where('project_id', $post->project_id)->where('purpose', MarketingAsset::PURPOSE_INSPIRATION)
            ->where('ai_notes->post_id', $post->id)->first();
    }

    /**
     * Which of `$postIds` (all of `$projectId`) are saved as inspiration, in one query: the post ids that are.
     *
     * @param  list<int>  $postIds
     * @return Collection<int, int>
     */
    public static function savedPostIds(int $projectId, array $postIds): Collection
    {
        if ($postIds === []) {
            return collect();
        }

        return MarketingAsset::where('project_id', $projectId)->where('purpose', MarketingAsset::PURPOSE_INSPIRATION)
            ->whereIn('ai_notes->post_id', $postIds)->pluck('ai_notes')
            ->map(fn ($notes) => (int) ($notes['post_id'] ?? 0))->values();
    }
}
