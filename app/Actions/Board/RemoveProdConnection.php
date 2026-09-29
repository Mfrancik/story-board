<?php

namespace App\Actions\Board;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Remove connection" on the Production panel (SB-17): deletes the project's
 * stored credentials and every metric set up for it. Board database only —
 * nothing in production is touched, and the read-only user still exists there.
 */
class RemoveProdConnection
{
    /**
     * Delete the project's production connection and metrics.
     *
     * Side effects: deletes `prod_connections` and `prod_metrics` rows in one
     * transaction; logs board.prod_connection_removed (warning: the credentials are gone).
     *
     * @return int the number of metrics deleted with it
     */
    public function handle(Project $project): int
    {
        $metrics = DB::transaction(function () use ($project) {
            $count = $project->prodMetrics()->delete();
            $project->prodConnection()->delete();

            return $count;
        });
        $project->unsetRelation('prodConnection');

        Log::warning('board.prod_connection_removed', ['project' => $project->name, 'metrics' => $metrics]);

        return $metrics;
    }
}
