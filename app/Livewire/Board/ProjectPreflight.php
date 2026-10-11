<?php

namespace App\Livewire\Board;

use App\Actions\Board\ReadPreflightHistory;
use App\Actions\Board\ReadPreflightRuns;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A project's preflight history at `/p/{project}/preflight` (SB-16, option A, the
 * dense ledger): trend figures and two line charts over one wide table of every
 * recorded run, newest first, read from the project's `preflight-cost.csv` files.
 *
 * The CSVs are read once per page load. The filters, the charts and the row hover
 * are Alpine over the page's own run data, so this component has no actions and
 * never re-renders in a normal visit. The route's EnsureProjectIsShown has already
 * refused an unknown or disabled project before this mounts (ADR-013).
 *
 * RD-8 adds where each run happened (brain or Mac), read from the project's run
 * records (ADR-055) and matched to the CSV rows here, once per load.
 */
#[Layout('layouts.shell')]
class ProjectPreflight extends Component
{
    use GuardsProjectPage;

    /**
     * Keep the project's name. The runs are read in render(), not held in a public
     * property, so they are never serialised into the component snapshot.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * Render the project's preflight runs, each with where it ran.
     * Side effect: reads the project's CSVs and run records and logs
     * `board.preflight_history_viewed` with the run, skipped-row, matched and
     * unmatched counts (ReadPreflightRuns logs each record it skips).
     */
    public function render(ReadPreflightHistory $read, ReadPreflightRuns $records): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        $history = $read->handle($model);
        $found = $records->handle($model);
        $ranOn = $records->match($history['runs'], $found['records']);
        foreach ($history['runs'] as $i => $run) {
            $history['runs'][$i]['ran_on'] = $ranOn[$i];
        }
        $matched = count(array_filter($ranOn, fn (?string $r) => $r !== null));
        Log::info('board.preflight_history_viewed', [
            'project' => $this->project, 'runs' => count($history['runs']), 'skipped' => $history['skipped'],
            'matched' => $matched, 'unmatched' => count($ranOn) - $matched,
        ]);

        return view('livewire.board.project-preflight', [
            ...$history,
            'model' => $model,
            'recordsSkipped' => $found['skipped'],
            'runnerWall' => $this->runnerWall($read, $history['runs'], $history['now']),
        ])->title($model->name.' · Preflight');
    }

    /**
     * Median wall time per runner over the trend strip's current window, or null
     * unless both brain and Mac runs fall in it: one runner alone has nothing to be
     * compared with. Alpine recomputes the same figures when a filter narrows the runs.
     *
     * @param  list<array{ts: CarbonImmutable, wall: int|null, tokens: int|null, ran_on: string|null}>  $runs
     * @return array{brain: float|null, mac: float|null}|null
     */
    private function runnerWall(ReadPreflightHistory $read, array $runs, CarbonImmutable $now): ?array
    {
        $from = $now->subDays(ReadPreflightHistory::WINDOW_DAYS);
        $figures = [];
        foreach (['brain', 'mac'] as $runner) {
            $figures[$runner] = $read->figures(array_values(array_filter($runs, fn (array $r) => $r['ran_on'] === $runner)), $from, $now);
        }

        return $figures['brain']['runs'] > 0 && $figures['mac']['runs'] > 0
            ? ['brain' => $figures['brain']['wall'], 'mac' => $figures['mac']['wall']]
            : null;
    }
}
