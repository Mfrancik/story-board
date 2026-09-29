<?php

namespace App\Actions\Board;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes a project off the board for good (SB-12): deletes its row, and through
 * the foreign-key cascades its stories and locations. Board database only — the
 * project's folder and git are never touched.
 */
class RemoveProject
{
    /**
     * Delete the project and everything the board stored about it.
     *
     * Side effects: deletes `projects`, `stories` and `project_locations` rows in one
     * transaction; logs board.project_removed (warning: the data is gone).
     *
     * @return int the number of story rows deleted with it (on the ref and off it)
     */
    public function handle(Project $project): int
    {
        $stories = DB::transaction(function () use ($project) {
            // Counted inside the transaction so the logged figure is what the cascade removed.
            $count = $project->stories()->count();
            $project->delete();

            return $count;
        });

        Log::warning('board.project_removed', ['project' => $project->name, 'stories' => $stories]);

        return $stories;
    }
}
