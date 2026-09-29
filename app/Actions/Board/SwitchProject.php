<?php

namespace App\Actions\Board;

use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Switches a project on or off the board (SB-12), from the Manage projects page
 * or `board:project enable|disable`. Off hides it everywhere and stops its
 * refreshes but keeps its rows; on brings it back and queues one refresh, since
 * its snapshot has not been updated while it was off.
 */
class SwitchProject
{
    /** The Manage projects page. */
    public const SOURCE_UI = 'ui';

    /** `board:project enable|disable`. */
    public const SOURCE_CLI = 'cli';

    /**
     * Set the project's `is_enabled`.
     *
     * Side effects: updates the row; logs board.project_enabled or board.project_disabled
     * with `source`; switching on dispatches RefreshProjectJob.
     *
     * @param  self::SOURCE_*  $source
     */
    public function handle(Project $project, bool $enabled, string $source): void
    {
        $project->update(['is_enabled' => $enabled]);

        if (! $enabled) {
            Log::info('board.project_disabled', ['project' => $project->name, 'source' => $source]);

            return;
        }

        Log::info('board.project_enabled', ['project' => $project->name, 'source' => $source]);
        RefreshProjectJob::dispatch($project);
    }
}
