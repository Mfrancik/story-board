<?php

namespace App\Actions\Board;

use App\Exceptions\ProjectRegistrationException;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Adds a local checkout to the board. Registration only records the path; the
 * first refresh is what reads it.
 */
class RegisterProject
{
    /**
     * @param  GitReader  $git  used to confirm the path is a repository
     */
    public function __construct(private GitReader $git) {}

    /**
     * Register `$path` under `$name` (default: the directory name), reading from `$ref`.
     *
     * Side effects: inserts a `projects` row and logs board.project_registered.
     *
     * @throws ProjectRegistrationException when the ref is invalid, or the path is missing, not a repo, or taken.
     */
    public function handle(string $path, ?string $name = null, string $ref = 'origin/main'): Project
    {
        if (! GitReader::isValidRef($ref)) {
            throw $this->refuse("Invalid ref: {$ref}");
        }

        $real = realpath($path);
        if ($real === false || ! $this->git->isRepository($real)) {
            throw $this->refuse("Not a git repository: {$path}");
        }

        $name = $name ?: basename($real);
        if (Project::where('path', $real)->orWhere('name', $name)->exists()) {
            throw $this->refuse("Already registered: {$name} ({$real})");
        }

        $project = Project::create(['name' => $name, 'path' => $real, 'ref' => $ref]);
        Log::info('board.project_registered', ['project' => $name, 'path' => $real, 'ref' => $ref]);

        return $project;
    }

    /**
     * Log why a registration was refused (codebase-standards: every guard logs), then hand back the exception.
     */
    private function refuse(string $reason): ProjectRegistrationException
    {
        Log::warning('board.project_registration_refused', ['reason' => $reason]);

        return new ProjectRegistrationException($reason);
    }
}
