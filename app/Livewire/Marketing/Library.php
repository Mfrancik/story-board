<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\ListLibraryPosts;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The marketing library at `/p/{project}/marketing/library` (MK-10, MK-1 option B reorganised in prototype round 5):
 * a calm archive of every approved and posted post — Coming up by week, Posted by month — as brand-drawn cards
 * (marketing/post-preview) with a campaign chip or "One-off". Campaign filter chips and a caption search narrow both
 * sections; the grid / list toggle is Alpine. A card opens the post in the studio (MK-9), which sits on this page
 * unchanged and tells it to re-read (`marketing-changed`) when it changes a post.
 *
 * The route's EnsureProjectIsShown refuses an unknown or disabled project before mount; GuardsProjectPage re-checks on
 * updates.
 */
#[Layout('layouts.shell')]
class Library extends Component
{
    use GuardsProjectPage;

    /** The campaign filter: ListLibraryPosts::ALL, ::ONE_OFF, or one of the project's campaign ids. */
    public string $filter = ListLibraryPosts::ALL;

    /** The caption search, as typed. */
    public string $search = '';

    /**
     * Keep the project's name.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * A filter chip was picked: keep it only if it is All, One-off or one of this project's campaigns — anything else
     * would quietly show an empty library, so it falls back to All.
     *
     * Side effects: a refused filter logs `marketing.library.filter_refused` (warning).
     */
    public function updatedFilter(string $value): void
    {
        $known = in_array($value, [ListLibraryPosts::ALL, ListLibraryPosts::ONE_OFF], true)
            || (ctype_digit($value) && $this->model()->marketingCampaigns()->whereKey((int) $value)->exists());
        if (! $known) {
            Log::warning('marketing.library.filter_refused', ['project' => $this->project, 'filter' => $value]);
            $this->filter = ListLibraryPosts::ALL;
        }
    }

    /**
     * The post studio (MK-9) changed a post: re-render so the library shows it. The body is empty on purpose; the
     * request itself re-renders the page.
     */
    #[On('marketing-changed')]
    public function refreshFromStudio(): void {}

    /**
     * Render the library.
     *
     * Side effects: `marketing.library.viewed` (debug: the sections' counts) on the page load.
     */
    public function render(ListLibraryPosts $list): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = $this->model();
        $library = $list->handle($model, CarbonImmutable::now(), $this->filter, $this->search);

        if (! Livewire::isLivewireRequest()) {
            Log::debug('marketing.library.viewed', ['project' => $model->name, 'coming_up' => $library['counts']['coming'], 'posted' => $library['counts']['posted']]);
        }

        return view('livewire.marketing.library', [
            'model' => $model,
            'library' => $library,
            'campaigns' => $model->marketingCampaigns()->orderBy('name')->get(['id', 'name', 'slug']),
            'brand' => $model->marketingBrand()->first(),
            'narrowed' => $this->filter !== ListLibraryPosts::ALL || trim($this->search) !== '',
        ])->title('Library · '.$model->name.' · Marketing');
    }

    /**
     * The project being shown.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }
}
