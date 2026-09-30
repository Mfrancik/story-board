<?php

namespace App\Livewire\Board;

use App\Actions\Board\CheckProjectShown;
use App\Actions\Board\ListWhatNeedsMe;
use App\Actions\Board\ReadMockupSets;
use App\Actions\Board\ReadProjectProgress;
use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One project's dashboard at `/p/{project}` (SB-10, design A view 2): a header
 * with a one-project refresh, the What needs me cards scoped to the project,
 * Progress by initiative and Not on main by where the work lives. Reads the
 * stored snapshot only; every story row opens the SB-8 modal.
 * The route's EnsureProjectIsShown middleware has already refused an unknown or
 * disabled project before this mounts (ADR-013).
 */
#[Layout('layouts.board')]
class ProjectPage extends Component
{
    /** Rows an initiative list shows before "Show all N" (design A). */
    public const INITIATIVE_PAGE = 8;

    /** Not on main's location kinds, in the order the panel lists them (SB-5). */
    public const KINDS = [Story::KIND_BRANCH, Story::KIND_WORKTREE, Story::KIND_UNTRACKED];

    /*
     * Locked: public Livewire properties are otherwise settable from the browser —
     * only the server's own actions may change these.
     */

    /** The project's name. A name, not the model, so a project removed mid-visit is refused, not a crash. */
    #[Locked]
    public string $project = '';

    /** @var list<string> What needs me cards the owner expanded past their first rows */
    #[Locked]
    public array $expandedGroups = [];

    /** @var list<string> location kinds whose off-main rows are shown */
    #[Locked]
    public array $openKinds = [];

    /** Confirmation after Refresh this project, in the button's own verb (design-standards §Four states). */
    #[Locked]
    public ?string $notice = null;

    /** Set by hydrate() when the project left the board mid-visit: render nothing, the redirect is on its way. */
    private bool $gone = false;

    /**
     * Log the view and queue a refresh of this project if its snapshot is stale —
     * only this one: the others refresh when `/` or their own page loads.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
        Log::info('board.project_viewed', ['project' => $project->name]);

        if ($project->needsRefresh()) {
            RefreshProjectJob::dispatch($project);
        }
    }

    /**
     * Re-check, on every request after the first, that the project is still on
     * the board. The route middleware only guards the initial GET (ADR-013), so a
     * project switched off or removed mid-visit would otherwise keep rendering its
     * rollup, or 404 with no log line (L-5). The owner is sent home instead.
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
     * "Refresh this project": queue one fetch and re-index of this project only.
     * A project taken off the board (or removed) since the page loaded is not
     * refreshed; the owner is sent to the all-projects page, where it no longer is.
     */
    public function refresh(CheckProjectShown $check): void
    {
        $refusal = $check->refusal($this->project);
        if ($refusal !== null) {
            Log::warning('board.refresh_refused', ['project' => $this->project, 'reason' => $refusal]);
            $this->skipRender();
            $this->redirectRoute('home', navigate: true);

            return;
        }

        Log::info('board.refresh_requested', ['project' => $this->project]);
        RefreshProjectJob::dispatch(Project::where('name', $this->project)->firstOrFail());
        $this->notice = "Refresh queued for {$this->project}. Reload in a minute to see it.";
    }

    /**
     * Show every row of a What needs me card instead of the first few.
     */
    public function showAll(string $group): void
    {
        if (! in_array($group, Home::GROUPS, true)) {
            $this->refuse('showAll', $group);

            return;
        }

        if (! in_array($group, $this->expandedGroups, true)) {
            $this->expandedGroups[] = $group;
        }
    }

    /**
     * Show or hide the off-main rows of one location kind. Rows load only while
     * a kind is open — coins has 262 of them.
     */
    public function toggleKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            $this->refuse('toggleKind', $kind);

            return;
        }

        $this->openKinds = in_array($kind, $this->openKinds, true)
            ? array_values(array_diff($this->openKinds, [$kind]))
            : [...$this->openKinds, $kind];
    }

    /**
     * Render the page from the snapshot. The query count is fixed: the cards, one
     * grouped initiative query, one grouped off-main count, and the off-main rows
     * (one query) only while a kind is open.
     */
    public function render(ListWhatNeedsMe $list, ReadProjectProgress $progress, ReadMockupSets $mockups): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render, not even its rollup.
            return '<div></div>';
        }

        $project = Project::where('name', $this->project)->firstOrFail();
        $data = $list->handle($project->name);
        $summary = $data['projects'][0] ?? null;

        return view('livewire.board.project-page', [
            ...$data,
            ...$progress->handle($project->id),
            'model' => $project,
            // SB-21: the header's Mockups button, only when the project has sets (a snapshot count, no git).
            'mockupSets' => $mockups->countFor($project),
            'counts' => $summary['counts'] ?? [],
            'parseErrors' => $summary['parse_errors'] ?? 0,
            'offmainRows' => $this->openKinds === []
                ? collect()
                : $list->section('offmain', $project->name)->groupBy('location_kind'),
        ])->title($project->name);
    }

    /**
     * Log a request for something the page does not have. Only a hand-made call
     * can send one (every button names a real card or kind), so it is a warning.
     */
    private function refuse(string $action, string $value): void
    {
        Log::warning('board.project_action_refused', ['project' => $this->project, 'action' => $action, 'value' => $value]);
    }
}
