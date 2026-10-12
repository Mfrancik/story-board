<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Looks up a marketing asset by id for one project only (MK-2). The asset route and Settings' delete both ask
 * through here, so an id of another project's asset is refused in one place, never served or deleted.
 */
class FindProjectAsset
{
    /**
     * `$project`'s asset `$id`, or null — logged as `marketing.asset.refused` with why: `unknown`, `other_project`,
     * or (when `$needsFile`) `missing_file`.
     */
    public function handle(Project $project, int $id, bool $needsFile = false): ?MarketingAsset
    {
        $asset = MarketingAsset::find($id);
        $reason = match (true) {
            $asset === null => 'unknown',
            $asset->project_id !== $project->id => 'other_project',
            $needsFile && ! Storage::disk('local')->exists($asset->path) => 'missing_file',
            default => null,
        };

        if ($reason !== null) {
            Log::warning('marketing.asset.refused', ['project' => $project->name, 'asset_id' => $id, 'reason' => $reason]);

            return null;
        }

        return $asset;
    }
}
