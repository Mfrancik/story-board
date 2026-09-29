<?php

namespace App\Actions\Board;

use App\Exceptions\ProductionRefusedException;
use App\Models\Project;
use App\Services\Production\ConnectionCheck;
use App\Services\ProductionReader;
use Illuminate\Support\Facades\Log;

/**
 * "Test connection" on the Production panel (SB-17): connect with what the
 * form holds, prove the user read-only, and report — without storing anything.
 */
class TestProdConnection
{
    public function __construct(private PrepareProdConnection $prepare, private ProductionReader $reader) {}

    /**
     * Check the form's connection.
     *
     * Side effects: one production connection through ProductionReader; logs
     * board.prod_connection_tested with the result (and reason when not ok).
     *
     * @param  array{host: string, port: string|int, database: string, username: string, password: string, useSsl: bool}  $input
     *
     * @throws ProductionRefusedException when the password must be typed again (PrepareProdConnection).
     */
    public function handle(Project $project, array $input): ConnectionCheck
    {
        $check = $this->reader->check($this->prepare->handle($project, $input));

        Log::info('board.prod_connection_tested', array_filter([
            'project' => $project->name,
            'result' => $check->status,
            'reason' => $check->reason,
            'privilege' => $check->privilege,
            'duration_ms' => $check->ms,
        ], fn ($v) => $v !== null));

        return $check;
    }
}
