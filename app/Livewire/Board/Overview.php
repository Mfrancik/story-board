<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListLiveSessions;
use App\Actions\Board\ListNeedsMe;
use App\Actions\Board\ReadOverviewFigures;
use App\Actions\Board\ResolveProjectFilter;
use App\Models\Project;
use App\Services\ProjectFilter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * The home overview at `/` (RD-5, Home A "command center") in the RD-2 shell: a
 * sentence of counts, four headline figures, Needs me oldest first and Live now with
 * what each session is doing and how full its context is. Live now's `wire:poll.30s`
 * re-renders the whole component, so the figures and the sentence follow the cards.
 * Each panel's data is fetched on its own, so one failing source shows that panel's
 * error state and the rest of the page still renders. Writes nothing.
 *
 * RD-6 adds the Projects filter in the header: `?projects=a,b` narrows every panel
 * to those projects. The URL value is resolved once per render into one
 * ProjectFilter, and that one value is handed to each panel's action, so the panels
 * can never disagree on which projects they cover.
 */
#[Layout('layouts.shell')]
#[Title('Home')]
class Overview extends Component
{
    /**
     * The chosen project names, comma-separated, as the URL's `?projects=` — so a
     * filtered view can be bookmarked. Pushed to history so Back undoes a change;
     * empty (every project) leaves the URL bare. Raw: names in it may be unknown or
     * disabled until ResolveProjectFilter drops them.
     */
    #[Url(history: true, except: '')]
    public string $projects = '';

    /**
     * Why this request should log the filter, if it should: `load` on the page load,
     * `change` when the selection changed; null on a poll. Not public, so it lives
     * for one request only and a poll never repeats the log lines.
     */
    private ?string $announce = null;

    /**
     * Mark the page load, so its filter (if any) is logged once.
     */
    public function mount(): void
    {
        $this->announce = 'load';
    }

    /**
     * The URL value changed from the browser (Back or Forward through the history):
     * log the filter it now names.
     */
    public function updatedProjects(): void
    {
        $this->announce = 'change';
    }

    /**
     * Tick or untick one project (a dropdown checkbox, or a chip's remove button).
     * Starts from the resolved names, so a name the filter ignored drops out of
     * the URL with the next change; an unknown name passed in is kept only until
     * the render resolves and ignores it, like one typed into the URL.
     */
    public function toggleProject(string $name, ResolveProjectFilter $resolve): void
    {
        $names = $resolve->handle($this->projects, log: false)->names;
        $names = in_array($name, $names, true) ? array_values(array_diff($names, [$name])) : [...$names, $name];

        $this->projects = implode(',', $names);
        $this->announce = 'change';
    }

    /**
     * Clear the filter: every project again.
     */
    public function clearProjects(): void
    {
        $this->projects = '';
        $this->announce = 'change';
    }

    /**
     * Render the overview, or the one "no projects" empty state when the board has
     * no enabled project.
     *
     * Side effects: reads the stored snapshot, the cached session scan and the
     * preflight CSVs; logs `board.overview_viewed` (info, counts) on the page load
     * (not on each poll) and `board.overview_panel_failed` (error) for a panel whose
     * source threw. On the page load and on a filter change, logs
     * `board.overview_filtered` (info, the chosen names) and, through
     * ResolveProjectFilter, `board.overview_filter_ignored` (warning).
     */
    public function render(ListNeedsMe $needs, ListLiveSessions $sessions, ReadOverviewFigures $figures, ResolveProjectFilter $resolve): View
    {
        $options = Project::enabled()->orderBy('name')->pluck('name')->all();
        $projects = count($options);
        if ($projects === 0) {
            $this->logViewed(['projects' => 0]);

            return view('livewire.board.overview', ['empty' => true]);
        }

        $filter = $resolve->handle($this->projects, log: $this->announce !== null);
        $this->logFiltered($filter);

        $data = [
            'empty' => false,
            'filter' => $filter,
            'options' => $options,
            'needs' => $this->attempt('needs', fn () => $needs->handle($filter)),
            'live' => $this->attempt('live', fn () => $sessions->overview($filter)),
            'figures' => $this->attempt('figures', fn () => $figures->handle($filter)),
        ];

        $this->logViewed([
            'projects' => $projects,
            'needs' => $data['needs'] === null ? null : count($data['needs']['rows']),
            'picks' => $data['needs']['counts']['pick'] ?? null,
            'drafts' => $data['needs']['counts']['draft'] ?? null,
            'ready' => $data['needs']['counts']['ready'] ?? null,
            'sessions' => $data['live'] === null ? null : count($data['live']['sessions']),
            'preflight_runs' => $data['figures']['preflight']['runs'] ?? null,
            'in_flight' => $data['figures']['in_flight'] ?? null,
        ]);

        return view('livewire.board.overview', $data);
    }

    /**
     * A duration in the overview's words: `1h 12m`, `3m 12s`, `40s`. Hours drop the
     * seconds, which a figure read at a glance does not need; with `$seconds` false
     * (a session's running time, which ticks on every poll) minutes drop them too,
     * and under a minute reads `<1m`.
     */
    public static function duration(int|float $value, bool $seconds = true): string
    {
        $value = max(0, (int) round($value));
        [$h, $m, $s] = [intdiv($value, 3600), intdiv($value % 3600, 60), $value % 60];

        return match (true) {
            $h > 0 => "{$h}h {$m}m",
            ! $seconds => $m > 0 ? "{$m}m" : '<1m',
            $m > 0 && $s > 0 => "{$m}m {$s}s",
            $m > 0 => "{$m}m",
            default => "{$s}s",
        };
    }

    /**
     * Run one panel's data source. A source that throws gives null — that panel's
     * error state — and is logged with the panel's name and the exception's class,
     * so a broken session file or query never takes the rest of the page down.
     *
     * @template T
     *
     * @param  Closure(): T  $source
     * @return T|null
     */
    private function attempt(string $panel, Closure $source): mixed
    {
        try {
            return $source();
        } catch (Throwable $e) {
            Log::error('board.overview_panel_failed', ['panel' => $panel, 'error' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Log `board.overview_filtered` with the chosen names when the filter was set:
     * on a page load that names projects, and on every change (an empty list there
     * is a change back to every project). Never on a poll.
     */
    private function logFiltered(ProjectFilter $filter): void
    {
        if ($this->announce === 'change' || ($this->announce === 'load' && $this->projects !== '')) {
            Log::info('board.overview_filtered', ['projects' => $filter->names]);
        }
    }

    /**
     * Log `board.overview_viewed` with the page's counts on the page load only: Live
     * now polls every 30 s, and a line per poll would be info spam (logging-standards).
     *
     * @param  array<string, int|null>  $counts
     */
    private function logViewed(array $counts): void
    {
        if (! Livewire::isLivewireRequest()) {
            Log::info('board.overview_viewed', $counts);
        }
    }
}
