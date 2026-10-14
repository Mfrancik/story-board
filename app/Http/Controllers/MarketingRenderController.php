<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\FindProjectAsset;
use App\Actions\Marketing\SaveBrand;
use App\Actions\Marketing\StorePostRenders;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Download 1:1" / "Download 4:5" in the studio (MK-5): one post's rendered ad as a PNG attachment, named for the
 * brand, the date and the shape. The route's EnsureProjectIsShown has refused an unknown or disabled project; the
 * post must be this project's and have a render at that size, else it is a logged 404 — another project's post id
 * looks exactly like one that does not exist.
 */
class MarketingRenderController extends Controller
{
    /**
     * Download post `$post`'s render at `$size` (a MarketingPost::RENDER_SIZES key), or 404.
     *
     * Side effects: a refusal logs `marketing.render.refused` (project, post_id, size, reason `unknown_size` |
     * `unknown_post` | `not_rendered` | `missing_file`).
     */
    public function __invoke(Project $project, int $post, string $size, FindProjectAsset $find, SaveBrand $brands): StreamedResponse
    {
        $refuse = function (string $reason) use ($project, $post, $size): never {
            Log::warning('marketing.render.refused', ['project' => $project->name, 'post_id' => $post, 'size' => $size, 'reason' => $reason]);
            abort(404);
        };

        if (! array_key_exists($size, MarketingPost::RENDER_SIZES)) {
            $refuse('unknown_size');
        }
        $found = $project->marketingPosts()->with('campaign')->find($post) ?? $refuse('unknown_post');
        $id = $found->renderId($size) ?? $refuse('not_rendered');
        $asset = $find->handle($project, $id, needsFile: true) ?? $refuse('missing_file');

        $zone = $found->campaign->timezone ?? (string) config('board.timezone');
        $name = Str::slug((string) ($brands->brandOf($project)->values['name'] ?? '') ?: $project->name)
            .($found->scheduled_for ? '-'.$found->scheduled_for->copy()->setTimezone($zone)->format('Y-m-d') : '')
            .'-'.StorePostRenders::LABELS[$size].'.png';

        return Storage::disk('local')->download($asset->path, $name, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
