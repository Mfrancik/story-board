<?php

namespace App\Console\Commands;

use App\Actions\Board\ReadProductionProject;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `board:prod-snapshot` — read every shown, connected project's production
 * metrics once and snapshot them (SB-18). Scheduled daily at 23:55 in
 * board.timezone (routes/console.php), so a day nobody opened /prod still has a
 * point on the trend line. Needs the scheduler running (`schedule:work`).
 */
class BoardProdSnapshot extends Command
{
    /** @var string */
    protected $signature = 'board:prod-snapshot';

    /** @var string */
    protected $description = "Read every connected project's production metrics once and save today's snapshot";

    /**
     * Read the projects one after another, in this process rather than on the
     * queue: the schedule needs no page open and no worker. A failing project is
     * logged by ReadProductionProject and never stops the rest, so this always
     * succeeds.
     *
     * Side effects: one production connection per project; snapshot upserts; logs
     * board.prod_snapshot_run with the counts.
     */
    public function handle(ReadProductionProject $read): int
    {
        $projects = Project::has('prodConnection')->with('prodConnection')->orderBy('name')->get();
        // Switched-off projects keep their connection but are not read (the same rule as /prod).
        [$shown, $off] = $projects->partition(fn (Project $p) => $p->is_enabled);

        $ok = 0;
        foreach ($shown as $project) {
            $outcome = $read->handle($project);
            $ok += ($outcome['ok'] ?? false) ? 1 : 0;
            $this->line(sprintf('%-20s %s', $project->name, ($outcome['ok'] ?? false) ? 'ok' : 'failed: '.($outcome['reason'] ?? 'metrics_failed')));
        }

        Log::info('board.prod_snapshot_run', ['projects' => $shown->count(), 'ok' => $ok, 'failed' => $shown->count() - $ok, 'skipped' => $off->count()]);

        return self::SUCCESS;
    }
}
