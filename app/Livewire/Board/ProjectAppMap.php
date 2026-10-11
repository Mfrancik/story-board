<?php

namespace App\Livewire\Board;

use App\Actions\Board\ReadJourneyMap;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A project's app map at `/p/{project}/map` (SB-24, option A, the storyboard
 * strip): every journey as a numbered row of screens under a large stage with
 * Back/Next, plus an All flows overview marking the screens journeys share.
 *
 * The map is read once per page load. Picking a flow, stepping, full screen and
 * the shared-screen hover are Alpine over the page's own markup, so this
 * component has no actions and never re-renders in a normal visit. The route's
 * EnsureProjectIsShown has already refused an unknown or disabled project
 * before this mounts (ADR-013).
 */
#[Layout('layouts.board')]
class ProjectAppMap extends Component
{
    use GuardsProjectPage;

    /**
     * Keep the project's name. The map is built in render(), not held in a public
     * property, so it is never serialised into the component snapshot.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * Render the project's journeys as screens.
     * Side effect: reads the journey docs, story files and journey shots, and logs
     * `board.app_map_viewed` with the journey, step and shot counts.
     */
    public function render(ReadJourneyMap $read): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        $map = $read->handle($model);
        Log::info('board.app_map_viewed', ['project' => $this->project, 'journeys' => count($map['journeys']), 'steps' => $map['steps'], 'shots' => $map['shots']]);

        return view('livewire.board.project-app-map', [...$map, 'model' => $model])
            ->title($model->name.' · App map');
    }
}
