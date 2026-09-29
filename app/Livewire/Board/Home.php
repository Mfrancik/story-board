<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListWhatNeedsMe;
use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The all-projects dashboard at `/` (SB-9, design A): What needs me first — three
 * cards of what is waiting on the owner — then the In flight figures, one tile per
 * project, and the collapsible Not on main, Built and Parked drafts sections (SB-3,
 * SB-5). Reads the stored snapshot only; a row opens the story modal (SB-8), which
 * does its own git read. One project's page is ProjectPage (SB-10).
 */
#[Layout('layouts.board')]
class Home extends Component
{
    /** Rows a What needs me card shows before "Show all N" (design A: top 5). */
    public const PAGE = 5;

    /** Rows an open collapsible section shows before "Show N more" — Built runs to hundreds. */
    public const SECTION_PAGE = 50;

    /** The collapsible sections the owner asked for at the gate. */
    public const SECTIONS = ['offmain', 'built', 'parked'];

    /** The three What needs me cards, in page order (design A). */
    public const GROUPS = ['pick', 'approval', 'build'];

    /** Initiative filter, kept in the URL. */
    #[Url]
    public string $initiative = '';

    /** Search: an exact story ID, or text in an ID or title. Kept in the URL. */
    #[Url]
    public string $q = '';

    /*
     * Locked: public Livewire properties are otherwise settable from the browser —
     * only the server's own actions may change these.
     */

    /** @var list<string> groups the owner expanded past the first PAGE rows */
    #[Locked]
    public array $expandedGroups = [];

    /** @var list<string> collapsible sections currently open */
    #[Locked]
    public array $openSections = [];

    /** Confirmation after Refresh, in the button's own verb (design-standards §Four states). */
    #[Locked]
    public ?string $notice = null;

    /**
     * Log the view and queue a refresh for any project whose snapshot is stale.
     * Queued, not after-response: `artisan serve` cannot flush a response early,
     * so after-response work held the page open for the whole refresh (8–50 s).
     */
    public function mount(): void
    {
        Log::info('board.home_viewed', ['initiative' => $this->initiative, 'q' => $this->q]);

        Project::enabled()->get()
            ->filter(fn (Project $p) => $p->needsRefresh())
            ->each(fn (Project $p) => RefreshProjectJob::dispatch($p));
    }

    /**
     * The Refresh button: queue a fetch and re-index of every enabled project.
     */
    public function refresh(): void
    {
        $projects = Project::enabled()->get();
        Log::info('board.refresh_requested', ['projects' => $projects->pluck('name')->all()]);
        $projects->each(fn (Project $p) => RefreshProjectJob::dispatch($p));
        $this->notice = 'Refresh queued for '.$projects->count().' '.str('project')->plural($projects->count()).'. Reload in a minute to see it.';
    }

    /**
     * Open or close a collapsible section; its rows load only while it is open.
     */
    public function toggleSection(string $section): void
    {
        if (! in_array($section, self::SECTIONS, true)) {
            return;
        }

        $this->openSections = in_array($section, $this->openSections, true)
            ? array_values(array_diff($this->openSections, [$section]))
            : [...$this->openSections, $section];
    }

    /**
     * Show every row of a card (or open section) instead of the first PAGE.
     */
    public function showAll(string $group): void
    {
        if (in_array($group, [...self::GROUPS, ...self::SECTIONS], true) && ! in_array($group, $this->expandedGroups, true)) {
            $this->expandedGroups[] = $group;
        }
    }

    /**
     * Clear every filter.
     */
    public function clearFilters(): void
    {
        $this->reset('initiative', 'q');
    }

    /**
     * Render the page from the snapshot.
     */
    public function render(ListWhatNeedsMe $list): View
    {
        // Every enabled project: the project filter is the sidebar now (SB-9), and one project has its own page (SB-10).
        $filters = [null, $this->initiative ?: null, $this->q ?: null];
        $data = $list->handle(...$filters);

        $sections = [];
        foreach (self::SECTIONS as $section) {
            if (in_array($section, $this->openSections, true)) {
                $sections[$section] = $list->section($section, ...$filters);
            }
        }

        // An ID search that no visible group answers still leads to the story (a cancelled one, say).
        $inGroups = collect();
        foreach ([$data['approval'], $data['pick'], $data['build'], ...array_values($sections)] as $rows) {
            $inGroups = $inGroups->merge($rows->pluck('story_id'));
        }
        $goto = $this->q !== ''
            ? $list->withId($this->q)->reject(fn (Story $s) => $inGroups->contains($s->story_id))
            : collect();

        return view('livewire.board.home', [
            ...$data,
            'sections' => $sections,
            'goto' => $goto,
            // The header's "Refreshed N min ago": the stalest snapshot, since the page is only as fresh as that.
            'refreshedAt' => collect($data['projects'])->pluck('indexed_at')->filter()->min(),
            'initiatives' => Story::onRef()->whereNotNull('initiative')
                ->whereHas('project', fn ($q) => $q->where('is_enabled', true))
                ->distinct()->orderBy('initiative')->pluck('initiative'),
            'filtered' => $this->initiative !== '' || $this->q !== '',
        ])->title('What needs me');
    }
}
