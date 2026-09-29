<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListWhatNeedsMe;
use App\Actions\Board\RenderStory;
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
 * The board's home (SB-3, mockup A "Inbox"): what is waiting on the owner
 * across every project, then collapsible Built and Parked drafts, then one card
 * per project. Reads the stored snapshot; git only for an expanded story's text.
 * Also serves `/p/{project}` (SB-7), pinned to that project until SB-10 gives
 * the single-project page its own dashboard.
 */
#[Layout('layouts.board')]
class Home extends Component
{
    /** Rows a group shows before "Show N more". */
    public const PAGE = 10;

    /** Rows an open collapsible section shows before "Show N more" — Built runs to hundreds. */
    public const SECTION_PAGE = 50;

    /** The collapsible sections the owner asked for at the gate. */
    public const SECTIONS = ['offmain', 'built', 'parked'];

    /** The three action groups, in page order. */
    public const GROUPS = ['approval', 'pick', 'build'];

    /** Project filter (a project name), kept in the URL. */
    #[Url]
    public string $project = '';

    /** Initiative filter, kept in the URL. */
    #[Url]
    public string $initiative = '';

    /** Search: an exact story ID, or text in an ID or title. Kept in the URL. */
    #[Url]
    public string $q = '';

    /*
     * Locked: public Livewire properties are otherwise settable from the browser, and
     * `bodies` is rendered as HTML — only the server's own actions may change these three.
     */

    /** @var list<string> groups the owner expanded past the first PAGE rows */
    #[Locked]
    public array $expandedGroups = [];

    /** @var list<string> collapsible sections currently open */
    #[Locked]
    public array $openSections = [];

    /** @var array<int, string> rendered story bodies by stories.id, fetched on first expand */
    #[Locked]
    public array $bodies = [];

    /**
     * The project this page is fixed to on `/p/{project}`, null on `/`. Locked and
     * separate from the `project` filter, so neither the query string nor
     * Clear filters can move the project page off its project.
     */
    #[Locked]
    public ?string $pinned = null;

    /** Confirmation after Refresh, in the button's own verb (design-standards §Four states). */
    #[Locked]
    public ?string $notice = null;

    /**
     * Log the view and queue a refresh for any project whose snapshot is stale.
     * Queued, not after-response: `artisan serve` cannot flush a response early,
     * so after-response work held the page open for the whole refresh (8–50 s).
     *
     * @param  string|null  $project  the `/p/{project}` route's name, already checked
     *                                by EnsureProjectIsShown; null on `/`
     */
    public function mount(?string $project = null): void
    {
        if ($project !== null) {
            $this->pinned = $project;
            Log::info('board.project_viewed', ['project' => $project]);
        } else {
            Log::info('board.home_viewed', ['project' => $this->project, 'initiative' => $this->initiative, 'q' => $this->q]);
        }

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
     * Show every row of a group instead of the first PAGE.
     */
    public function showAll(string $group): void
    {
        if (in_array($group, [...self::GROUPS, ...self::SECTIONS], true) && ! in_array($group, $this->expandedGroups, true)) {
            $this->expandedGroups[] = $group;
        }
    }

    /**
     * Load a story's text for its expanded row. Opening and closing the row is
     * Alpine state; this runs once per story, the first time it is opened.
     */
    public function expand(int $storyId, RenderStory $render): void
    {
        if (isset($this->bodies[$storyId])) {
            return;
        }

        $story = Story::onRef()->with('project')->whereHas('project', fn ($q) => $q->where('is_enabled', true))->find($storyId);
        if ($story === null) {
            Log::info('board.story_not_found', ['row' => $storyId, 'reason' => 'no such row on an enabled project']);
        }
        $this->bodies[$storyId] = $story ? ($render->handle($story) ?? '') : '';
    }

    /**
     * Clear every filter.
     */
    public function clearFilters(): void
    {
        $this->reset(...($this->pinned === null ? ['project', 'initiative', 'q'] : ['initiative', 'q']));
    }

    /**
     * Render the page from the snapshot.
     */
    public function render(ListWhatNeedsMe $list): View
    {
        $project = $this->pinned ?? ($this->project ?: null);
        $filters = [$project, $this->initiative ?: null, $this->q ?: null];
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
            ? $list->withId($this->q, $project)->reject(fn (Story $s) => $inGroups->contains($s->story_id))
            : collect();

        return view('livewire.board.home', [
            ...$data,
            'sections' => $sections,
            'goto' => $goto,
            'projectNames' => Project::enabled()->orderBy('name')->pluck('name'),
            'initiatives' => Story::onRef()->whereNotNull('initiative')
                ->whereHas('project', fn ($q) => $q->where('is_enabled', true)->when($project, fn ($q) => $q->where('name', $project)))
                ->distinct()->orderBy('initiative')->pluck('initiative'),
            'filtered' => ($this->pinned === null && $this->project !== '') || $this->initiative !== '' || $this->q !== '',
        ])->title($this->pinned ?? 'What needs me');
    }
}
