<?php

namespace App\Actions\Board;

use App\Exceptions\ProductionRefusedException;
use App\Models\ProdConnection;
use App\Models\Project;
use App\Services\Production\ConnectionCheck;
use Illuminate\Support\Facades\Log;

/**
 * Turns the Production form into an unsaved ProdConnection for a test or a save
 * (SB-17). A blank password means "keep the saved one" — but only while the host
 * and username are unchanged, so a stored password is never sent to a server
 * the owner has just typed in.
 */
class PrepareProdConnection
{
    /**
     * Build the connection the form describes, without storing it.
     *
     * Side effects: none, except logging board.prod_connection_refused
     * (reason password_needed) when the password must be typed again.
     *
     * @param  array{host: string, port: string|int, database: string, username: string, password: string, useSsl: bool}  $input
     *
     * @throws ProductionRefusedException with reason `password_needed` when the password is blank and cannot be kept.
     */
    public function handle(Project $project, array $input): ProdConnection
    {
        $saved = $project->prodConnection;
        // A copy, so a refused test or save never changes the saved connection in memory.
        $connection = $saved ? clone $saved : new ProdConnection(['project_id' => $project->id]);
        $connection->setRelation('project', $project);

        $host = trim($input['host']);
        $username = trim($input['username']);
        $sameServer = $saved !== null && $saved->host === $host && $saved->username === $username;

        $connection->fill([
            'host' => $host,
            'port' => (int) $input['port'],
            'database' => trim($input['database']),
            'username' => $username,
            'use_ssl' => $input['useSsl'],
        ]);

        if ($input['password'] !== '') {
            $connection->password = $input['password'];
        } elseif (! $sameServer) {
            Log::warning('board.prod_connection_refused', ['project' => $project->name, 'reason' => 'password_needed']);

            throw new ProductionRefusedException(new ConnectionCheck(ConnectionCheck::FAILED, 'password_needed',
                $saved ? 'Enter the password again: the host or username changed.' : 'Enter the password.'));
        }

        return $connection;
    }
}
