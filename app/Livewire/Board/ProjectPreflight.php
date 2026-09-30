<?php

namespace App\Livewire\Board;

use App\Actions\Board\CheckProjectShown;
use App\Actions\Board\ReadPreflightHistory;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
 */
#[Layout('layouts.board')]
class ProjectPreflight extends Component
{
    /**
     * The project's name. A name, not the model, so a project removed mid-visit is
     * refused, not a crash. Locked: only the server may set it.
     */
    #[Locked]
    public string $project = '';

    /** Set by hydrate() when the project left the board mid-visit: render nothing, the redirect is on its way. */
    private bool $gone = false;

    /**
     * Keep the project's name. The runs are read in render(), not held in a public
     * property, so they are never serialised into the component snapshot.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * Re-check, on every request after the first, that the project is still on
     * the board. Route middleware never sees Livewire update requests (ADR-019
     * amendment), so a project switched off or removed mid-visit would otherwise
     * keep rendering its runs. The owner is sent home instead.
     */
    public function hydrate(CheckProjectShown $check): void
    {
        $refusal = $check->refusal($this->project);
        if ($refusal === null) {
            return;
        }

        Log::info('board.project_page_refused', ['project' => $this->project, 'reason' => $refusal, 'request' => 'update']);
        $this->gone = true;
        $this->redirectRoute('home', navigate: true);
    }

    /**
     * Render the project's preflight runs.
     * Side effect: reads the project's CSVs and logs `board.preflight_history_viewed`
     * with the run and skipped-row counts.
     */
    public function render(ReadPreflightHistory $read): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        $history = $read->handle($model);
        Log::info('board.preflight_history_viewed', ['project' => $this->project, 'runs' => count($history['runs']), 'skipped' => $history['skipped']]);

        return view('livewire.board.project-preflight', [...$history, 'model' => $model])
            ->title($model->name.' · Preflight');
    }
}
