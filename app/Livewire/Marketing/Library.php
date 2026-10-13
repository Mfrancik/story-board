<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\ListLibraryPosts;
use App\Actions\Marketing\RemoveSavedInspiration;
use App\Actions\Marketing\SaveToInspiration;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Flux\Flux;
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
 * unchanged and tells it to re-read (`marketing-changed`) when it changes a post. Each card also has MK-18's
 * "♡ Save to Inspiration" (with Undo once saved).
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
     * A card's "♡ Save to Inspiration" (MK-18): post `$id` becomes a favourite inspiration. Saving it again changes
     * nothing.
     *
     * Side effects: SaveToInspiration copies the photo, writes the asset and logs; toasts. Another project's post is
     * a logged 404.
     */
    public function saveToInspiration(int $id, SaveToInspiration $save): void
    {
        if ($save->handle($this->post($id))['created']) {
            Flux::toast(variant: 'success', text: 'Saved to Inspiration');
        }
    }

    /**
     * "Undo" on a saved card: remove the inspiration saved from post `$id`.
     *
     * Side effects: RemoveSavedInspiration deletes and logs; toasts. Another project's post is a logged 404.
     */
    public function undoInspiration(int $id, RemoveSavedInspiration $remove): void
    {
        if ($remove->handle($this->post($id))) {
            Flux::toast(variant: 'success', text: 'Removed from Inspiration');
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
            // MK-18: which cards are already saved as inspiration, in one query.
            'saved' => SaveToInspiration::savedPostIds($model->id, array_values(collect([...$library['coming'], ...$library['posted']])
                ->flatMap(fn (array $group) => $group['posts']->pluck('id'))->map(fn ($id) => (int) $id)->all())),
        ])->title('Library · '.$model->name.' · Marketing');
    }

    /**
     * This project's post `$id`, or a 404 logged as `marketing.library.save_refused` (another project's or no post).
     */
    private function post(int $id): MarketingPost
    {
        $post = $this->model()->marketingPosts()->whereKey($id)->first();
        if ($post === null) {
            Log::warning('marketing.library.save_refused', ['project' => $this->project, 'post_id' => $id]);
            abort(404);
        }

        return $post;
    }

    /**
     * The project being shown.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }
}
