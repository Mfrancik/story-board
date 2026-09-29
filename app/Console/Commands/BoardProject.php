<?php

namespace App\Console\Commands;

use App\Actions\Board\RegisterAlias;
use App\Actions\Board\RegisterProject;
use App\Exceptions\ProjectRegistrationException;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `board:project add|list|disable` — manage which projects the board reads.
 */
class BoardProject extends Command
{
    /** @var string */
    protected $signature = 'board:project
        {action : add, alias, list or disable}
        {path? : add/alias: the checkout path · disable: the project name}
        {--name= : add: project name (defaults to the directory name) · alias: the project it belongs to}
        {--ref=origin/main : add: the ref stories are read from}';

    /** @var string */
    protected $description = 'Register, list or disable projects on the board';

    /**
     * Dispatch to the chosen action.
     */
    public function handle(RegisterProject $register, RegisterAlias $alias): int
    {
        return match ($this->argument('action')) {
            'add' => $this->add($register),
            'alias' => $this->alias($alias),
            'list' => $this->list(),
            'disable' => $this->disable(),
            default => $this->fail('Unknown action: use add, alias, list or disable.'),
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
     * Take a project out of refreshes and off the board, keeping its row.
     */
    private function disable(): int
    {
        $project = Project::where('name', $this->argument('path'))->first();
        if (! $project) {
            $this->error("No project named {$this->argument('path')}.");

            return self::FAILURE;
        }

        $project->update(['is_enabled' => false]);
        Log::info('board.project_disabled', ['project' => $project->name]);
        $this->info("Disabled {$project->name}.");

        return self::SUCCESS;
    }
}
