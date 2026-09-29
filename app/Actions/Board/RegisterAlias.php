<?php

namespace App\Actions\Board;

use App\Exceptions\ProjectRegistrationException;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Registers a sibling clone as another checkout of a project (SB-5), so its
 * branches and untracked stories are scanned with the project's. Worktrees need
 * no alias: `git worktree list` finds them.
 */
class RegisterAlias
{
    /**
     * @param  GitReader  $git  used to confirm the path is a repository
     */
    public function __construct(private GitReader $git) {}

    /**
     * Record `$path` as an alias of the project named `$projectName`.
     *
     * Side effects: inserts a `project_locations` row and logs board.alias_registered.
     *
     * @throws ProjectRegistrationException when the project is unknown, the path is not a repo, or it is already registered.
     */
    public function handle(string $path, string $projectName): ProjectLocation
    {
        $project = Project::where('name', $projectName)->first();
        if (! $project) {
            throw $this->refuse("No project named {$projectName}");
        }

        $real = realpath($path);
        if ($real === false || ! $this->git->isRepository($real)) {
            throw $this->refuse("Not a git repository: {$path}");
        }
        if ($project->locations()->where('kind', ProjectLocation::KIND_ALIAS)->where('path', $real)->exists()) {
            throw $this->refuse("Already an alias of {$projectName}: {$real}");
        }

        $alias = $project->locations()->create(['kind' => ProjectLocation::KIND_ALIAS, 'path' => $real]);
        Log::info('board.alias_registered', ['project' => $projectName, 'path' => $real]);

        return $alias;
    }

    /**
     * Log why a registration was refused (codebase-standards: every guard logs), then hand back the exception.
     */
    private function refuse(string $reason): ProjectRegistrationException
    {
        Log::warning('board.alias_registration_refused', ['reason' => $reason]);

        return new ProjectRegistrationException($reason);
    }
}
