<?php

namespace App\Actions\Board;

use App\Exceptions\ProductionRefusedException;
use App\Models\Project;
use App\Services\Production\ConnectionCheck;
use App\Services\ProductionReader;
use Illuminate\Support\Facades\Log;

/**
 * "Save connection" on the Production panel (SB-17). Saving runs the same
 * check as Test: only a reachable, proven read-only connection is stored, with
 * its credentials encrypted (ProdConnection's casts).
 */
class SaveProdConnection
{
    public function __construct(private PrepareProdConnection $prepare, private ProductionReader $reader) {}

    /**
     * Check the form's connection and store it as the project's one connection.
     *
     * Side effects: one production connection; inserts or updates the project's
     * `prod_connections` row with `verified_at` now; logs board.prod_connection_saved.
     * A refusal stores nothing (ProductionReader has logged board.prod_connection_refused).
     *
     * @param  array{host: string, port: string|int, database: string, username: string, password: string, useSsl: bool}  $input
     *
     * @throws ProductionRefusedException when the connection is unreachable, fails, is not read-only, or needs its password again.
     */
    public function handle(Project $project, array $input): ConnectionCheck
    {
        $connection = $this->prepare->handle($project, $input);
        $check = $this->reader->check($connection);
        if (! $check->ok()) {
            throw new ProductionRefusedException($check);
        }

        $created = ! $connection->exists;
        $connection->verified_at = now();
        $connection->save();
        $project->setRelation('prodConnection', $connection);

        Log::info('board.prod_connection_saved', ['project' => $project->name, 'created' => $created, 'duration_ms' => $check->ms]);

        return $check;
    }
}
