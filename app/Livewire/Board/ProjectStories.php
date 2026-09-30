<?php

namespace App\Livewire\Board;

use App\Actions\Board\CheckProjectShown;
use App\Actions\Board\ReadProjectStories;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A project's stories by initiative at `/p/{project}/stories` (SB-15, option C):
 * initiatives with their counts on the left, the chosen initiative's stories on
 * the right, each tagged with its status; status filter chips and Expand all above.
 *
 * The whole project's list is rendered once. Selection, filters and Expand all are
 * Alpine over that markup, so this component has no actions of its own and never
 * re-renders in a normal visit; a story row opens the SB-8 modal it embeds. The
 * route's EnsureProjectIsShown has already refused an unknown or disabled project
 * before this mounts (ADR-013).
 */
#[Layout('layouts.board')]
class ProjectStories extends Component
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
     * Keep the project's name. The story list is read in render(), not held in a
     * public property, so 946 rows are never serialised into the component snapshot.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * Re-check, on every request after the first, that the project is still on
     * the board. Route middleware never sees Livewire update requests (ADR-019
     * amendment), so a project switched off or removed mid-visit would otherwise
     * keep rendering its stories. The owner is sent home instead.
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
     * Render every on-ref story of the project, grouped (one stories query).
     * Side effect: logs `board.stories_viewed` with the story count.
     */
    public function render(ReadProjectStories $read): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        $data = $read->handle($model);
        Log::info('board.stories_viewed', ['project' => $this->project, 'stories' => $data['stories']]);

        return view('livewire.board.project-stories', [...$data, 'model' => $model])
            ->title($model->name.' · Stories');
    }
}
