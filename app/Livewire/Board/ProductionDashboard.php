<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListProductionDashboard;
use App\Actions\Board\ReadProductionProject;
use App\Jobs\ReadProductionMetrics;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Production page at `/prod` (SB-18, option B): every shown project with a
 * production connection as a row of metrics — value, change against yesterday
 * and 7 days ago, 30-day trend, read time — and the rest under "Not connected".
 * Values are read live on load, one queued ReadProductionMetrics per project
 * (ADR-006's pattern), so each row shows a skeleton until its own read is back;
 * the component polls only while some read is outstanding.
 */
#[Layout('layouts.board')]
#[Title('Production')]
class ProductionDashboard extends Component
{
    /** How long a row waits for its queued read before saying no worker answered. */
    public const STALL_SECONDS = 60;

    /**
     * Reads asked for and not yet back, by project id: the project's read count
     * when asked (a higher one means a read has finished since) and when.
     * Locked: only the server's own actions change it.
     *
     * @var array<int, array{seq: int, at: int}>
     */
    #[Locked]
    public array $pending = [];

    /** @var list<int> projects whose read never came back within STALL_SECONDS */
    #[Locked]
    public array $stalled = [];

    /**
     * Log the view and queue a read of every shown, connected project.
     */
    public function mount(): void
    {
        $projects = Project::enabled()->has('prodConnection')->orderBy('name')->get();
        Log::info('board.prod_viewed', ['projects' => $projects->count()]);

        $projects->each(fn (Project $p) => $this->queue($p));
        // Under a sync queue (tests, a bare setup) the reads have already finished.
        $this->settle();
    }

    /**
     * wire:poll while reads are outstanding: drop the ones that are back, and
     * give up on any that has waited longer than STALL_SECONDS.
     */
    public function poll(): void
    {
        $this->settle();
    }

    /**
     * A row's Refresh button: re-read that one project. A project that is not on
     * the page (switched off, disconnected, or an id typed into the request) is
     * refused and logged.
     */
    public function refresh(int $projectId): void
    {
        $project = Project::enabled()->has('prodConnection')->find($projectId);
        if ($project === null) {
            Log::warning('board.prod_refresh_refused', ['project_id' => $projectId]);

            return;
        }

        Log::info('board.prod_refresh_requested', ['project' => $project->name]);
        $this->queue($project);
        $this->settle();
    }

    /**
     * Render the rows from the board's own database.
     */
    public function render(ListProductionDashboard $list): View
    {
        return view('livewire.board.production-dashboard', [
            ...$list->handle(array_keys($this->pending), $this->stalled),
            'polling' => $this->pending !== [],
        ]);
    }

    /**
     * Queue one project's read and mark its row loading.
     */
    private function queue(Project $project): void
    {
        $this->pending[$project->id] = ['seq' => ReadProductionProject::outcome($project->id)['seq'] ?? 0, 'at' => now()->getTimestamp()];
        $this->stalled = array_values(array_diff($this->stalled, [$project->id]));

        // Unique per project: if a read is already queued this adds nothing, and that read settles the row.
        ReadProductionMetrics::dispatch($project);
    }

    /**
     * Settle the rows whose read has finished since it was asked for, or has waited too long.
     */
    private function settle(): void
    {
        $gaveUp = [];
        foreach ($this->pending as $id => $asked) {
            if ((ReadProductionProject::outcome($id)['seq'] ?? 0) > $asked['seq']) {
                unset($this->pending[$id]);
            } elseif (now()->getTimestamp() - $asked['at'] > self::STALL_SECONDS) {
                unset($this->pending[$id]);
                $gaveUp[] = $id;
            }
        }

        if ($gaveUp !== []) {
            $this->stalled = array_values(array_unique([...$this->stalled, ...$gaveUp]));
            foreach (Project::whereIn('id', $gaveUp)->pluck('name') as $name) {
                // Nothing came back: most likely no queue worker is running (ADR-006, "no worker, no refresh").
                Log::warning('board.prod_read_stalled', ['project' => $name]);
            }
        }
    }
}
