<?php

namespace App\Console\Commands;

use App\Actions\Board\RegisterAlias;
use App\Actions\Board\RegisterProject;
use App\Actions\Board\SwitchProject;
use App\Exceptions\ProjectRegistrationException;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `board:project add|alias|list|enable|disable` — manage which projects the board reads.
 */
class BoardProject extends Command
{
    /** @var string */
    protected $signature = 'board:project
        {action : add, alias, list, enable or disable}
        {path? : add/alias: the checkout path · enable/disable: the project name}
        {--name= : add: project name (defaults to the directory name) · alias: the project it belongs to}
        {--ref=origin/main : add: the ref stories are read from}';

    /** @var string */
    protected $description = 'Register, list, enable or disable projects on the board';

    /**
     * Dispatch to the chosen action.
     */
    public function handle(RegisterProject $register, RegisterAlias $alias, SwitchProject $switch): int
    {
        return match ($this->argument('action')) {
            'add' => $this->add($register),
            'alias' => $this->alias($alias),
            'list' => $this->list(),
            'enable' => $this->switch($switch, true),
            'disable' => $this->switch($switch, false),
            default => $this->fail('Unknown action: use add, alias, list, enable or disable.'),
        };
    }

    /**
     * Register a checkout path.
     */
    private function add(RegisterProject $register): int
    {
        try {
            $project = $register->handle((string) $this->argument('path'), $this->option('name'), (string) $this->option('ref'));
        } catch (ProjectRegistrationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Registered project {$project->name} ({$project->path} @ {$project->ref}).");

        return self::SUCCESS;
    }

    /**
     * Register a sibling clone as another checkout of an existing project (SB-5).
     */
    private function alias(RegisterAlias $register): int
    {
        try {
            $alias = $register->handle((string) $this->argument('path'), (string) $this->option('name'));
        } catch (ProjectRegistrationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Registered {$alias->path} as an alias of {$this->option('name')}.");

        return self::SUCCESS;
    }

    /**
     * Print every registered project and its last refresh.
     */
    private function list(): int
    {
        $this->table(['name', 'path', 'ref', 'enabled', 'state', 'indexed_at'],
            Project::orderBy('name')->get()->map(fn (Project $p) => [
                $p->name, $p->path, $p->ref, $p->is_enabled ? 'yes' : 'no', $p->state, $p->indexed_at?->toDateTimeString() ?? '—',
            ]));

        return self::SUCCESS;
    }

    /**
     * `enable` puts a project back on the board and queues one refresh (SB-12);
     * `disable` takes it out of refreshes and off the board, keeping its row.
     */
    private function switch(SwitchProject $switch, bool $enabled): int
    {
        $name = (string) $this->argument('path');
        $project = Project::where('name', $name)->first();
        if (! $project) {
            Log::info('board.project_switch_refused', ['project' => $name, 'reason' => 'unknown', 'source' => SwitchProject::SOURCE_CLI]);
            $this->error("No project named {$name}.");

            return self::FAILURE;
        }

        $switch->handle($project, $enabled, SwitchProject::SOURCE_CLI);
        $this->info(($enabled ? 'Enabled' : 'Disabled')." {$project->name}.".($enabled ? ' A refresh is queued.' : ''));

        return self::SUCCESS;
    }
}
