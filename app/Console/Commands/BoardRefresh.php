<?php

namespace App\Console\Commands;

use App\Actions\Board\RefreshProject;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * `board:refresh [project]` — fetch and re-index every enabled project, or one by name.
 */
class BoardRefresh extends Command
{
    /** @var string */
    protected $signature = 'board:refresh {project? : Refresh only this project (by name)}';

    /** @var string */
    protected $description = 'Fetch each project and re-read its stories from its ref';

    /**
     * Refresh the selected projects one after another. A failing project is
     * recorded in its state and never stops the others, so this always succeeds
     * unless the named project does not exist.
     */
    public function handle(RefreshProject $refresh): int
    {
        $name = $this->argument('project');
        $projects = Project::enabled()
            ->when($name, fn ($q) => $q->where('name', $name))
            ->orderBy('name')
            ->get();

        if ($name && $projects->isEmpty()) {
            $this->error("No enabled project named {$name}.");

            return self::FAILURE;
        }

        foreach ($projects as $project) {
            $refresh->handle($project);
            $project->refresh();
            $this->line(sprintf('%-20s %-12s %5d stories  %s', $project->name, $project->state,
                $project->stories()->count(), $project->last_error ?? substr((string) $project->sha, 0, 12)));
        }

        return self::SUCCESS;
    }
}
