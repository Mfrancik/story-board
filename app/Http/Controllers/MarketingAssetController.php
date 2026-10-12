<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\FindProjectAsset;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves one marketing asset's bytes (MK-2: favourite photos in Settings; later the studio and library). The route's
 * EnsureProjectIsShown has already refused an unknown or disabled project; FindProjectAsset answers only that
 * project's own ids, so another project's asset is a 404, the same as one that does not exist.
 */
class MarketingAssetController extends Controller
{
    /**
     * Return asset `$asset` of `$project`, or 404 (logged by FindProjectAsset).
     */
    public function __invoke(Project $project, int $asset, FindProjectAsset $find): StreamedResponse
    {
        $found = $find->handle($project, $asset, needsFile: true);
        if ($found === null) {
            abort(404);
        }

        return Storage::disk('local')->response($found->path, $found->original_name, [
            // The stored type, never one sniffed from the bytes: only JPEG, PNG and WebP are ever stored.
            'Content-Type' => $found->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }
}
