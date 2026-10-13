<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListLiveSessions;
use App\Actions\Board\ListNeedsMe;
use App\Actions\Board\ReadGitActivity;
use App\Actions\Board\ReadHandbook;
use App\Actions\Board\ReadLatestPreflight;
use App\Actions\Board\ReadProjectProduction;
use App\Actions\Board\ReadStoryProgress;
use App\Exceptions\GitReaderException;
use App\Jobs\RefreshProjectJob;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use App\Services\ProjectFilter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * A project's overview at `/p/{project}` (RD-11, option B "command center"): a
 * dense tile grid of its story progress, what needs the owner, its live sessions,
 * its latest preflight, its production numbers, the "What it is" of its brief and
 * its biggest initiatives, plus its git activity (RD-12: commits per day, last edits with
 * the working copy's uncommitted count, most-edited code files today), above the project
 * tabs with Overview first. Every tile
 * reads data the board already keeps, through the existing readers, each tile on
 * its own so one failing source shows that tile's error and the rest still render.
 *
 * The one write is "Refresh this project" (F-16): it queues RefreshProjectJob and
 * shows "Refreshing…" until the snapshot moves on, polling only while it waits.
 * The route's EnsureProjectIsShown has already refused an unknown or disabled
 * project before this mounts (ADR-013); GuardsProjectPage re-checks on updates.
 */
#[Layout('layouts.shell')]
class ProjectOverview extends Component
{
    use GuardsProjectPage;

    /**
     * Give up waiting on a refresh after this long: RefreshProjectJob's uniqueness
     * window, after which a queued copy that never ran is no longer holding the slot.
     */
    public const REFRESH_WAIT_SECONDS = 300;

    /**
     * When the owner pressed Refresh this project (ISO 8601), or null. Locked: only
     * refresh() may set it, so the browser cannot fake a refresh in progress.
     */
    #[Locked]
    public ?string $refreshQueuedAt = null;

    /**
     * The project's last refresh attempt when Refresh was pressed (ISO 8601), or
     * null if it had none. A refresh is known to have started once the attempt
     * moves past it — compared with the stored stamp, not the clock, because the
     * database keeps whole seconds and a sync refresh lands in the same second.
     */
    #[Locked]
    public ?string $refreshAttemptBefore = null;

    /**
     * Keep the project's name. Tile data is read in render(), never held in public
     * properties, so none of it is serialised into the component snapshot.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * "Refresh this project": queue one fetch and re-index of this project.
     *
     * Side effects: dispatches RefreshProjectJob (unique per project, so a second
     * press while one is queued adds nothing) and logs `board.project_refresh_requested`
     * (info: project, request id). A project taken off the board since the page
     * loaded is not refreshed: hydrate() has already refused it, logged why and set
     * the owner on his way home.
     */
    public function refresh(): void
    {
        if ($this->gone) {
            return;
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        // Read before the dispatch: a sync queue finishes the refresh inside dispatch().
        $this->refreshQueuedAt = now()->toIso8601String();
        $this->refreshAttemptBefore = $model->refresh_attempted_at?->toIso8601String();
        RefreshProjectJob::dispatch($model);
        Log::info('board.project_refresh_requested', ['project' => $this->project, 'request_id' => Context::get('request_id')]);
    }

    /**
     * Render the overview's tiles.
     *
     * Side effects: reads the stored snapshot, the cached session scan, the
     * preflight CSVs and run records, the brief from git at the snapshot SHA, and the
     * git activity through ReadGitActivity's cached read-only reads; logs
     * `board.project_overview_viewed` (info, counts) on the page load,
     * `board.overview_panel_failed` (error) for a tile whose source threw, and (from
     * ReadGitActivity) `board.git_activity_failed` (warning) for each failed git read.
     */
    public function render(
        ReadStoryProgress $progress,
        ListNeedsMe $needs,
        ListLiveSessions $sessions,
        ReadLatestPreflight $preflight,
        ReadProjectProduction $production,
        ReadHandbook $handbook,
        ReadGitActivity $activity,
    ): View|string {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        // The one project, through the home overview's own filter, so Needs you and Live now apply its exact rules.
        $only = ProjectFilter::only([$model->name]);

        $data = [
            'progress' => $this->attempt('progress', fn () => $progress->handle($model)),
            'needs' => $this->attempt('needs', fn () => $needs->handle($only)),
            'live' => $this->attempt('live', fn () => $sessions->overview($only)),
            'preflight' => $this->attempt('preflight', fn () => ['run' => $preflight->handle($model)]),
            'production' => $this->attempt('production', fn () => ['metrics' => $production->handle($model)]),
            'about' => $this->about($model, $handbook),
            // Each git read fails on its own inside the action; null here only if something else threw.
            'activity' => $this->attempt('activity', fn () => $activity->handle($model)),
        ];

        // On the page load only: while a refresh is awaited the page polls, and a line per poll would be info spam.
        if (! Livewire::isLivewireRequest()) {
            $this->logViewed($model, $data);
        }

        return view('livewire.board.project-overview', [
            ...$data,
            'model' => $model,
            'refreshing' => $this->refreshing($model),
        ])->title($model->name.' · Overview');
    }

    /**
     * Log `board.project_overview_viewed` with the page's counts (null for a tile whose source failed).
     *
     * @param  array<string, mixed>  $data
     */
    private function logViewed(Project $model, array $data): void
    {
        Log::info('board.project_overview_viewed', [
            'project' => $model->name,
            'stories' => $data['progress']['total'] ?? null,
            'needs' => $data['needs'] === null ? null : count($data['needs']['rows']),
            'sessions' => $data['live'] === null ? null : count($data['live']['sessions']),
        ]);
    }

    /**
     * Whether a refresh the owner asked for is still on its way: true from the press
     * until a new attempt has started and ended — the snapshot re-indexed at or after
     * it, or the project left stale or unreachable by it — or REFRESH_WAIT_SECONDS
     * pass (a queued copy that never ran no longer holds the slot by then).
     */
    private function refreshing(Project $model): bool
    {
        if ($this->refreshQueuedAt === null) {
            return false;
        }
        if (CarbonImmutable::parse($this->refreshQueuedAt)->addSeconds(self::REFRESH_WAIT_SECONDS)->isPast()) {
            return false;
        }

        $attempt = $model->refresh_attempted_at;
        $started = $attempt !== null && ($this->refreshAttemptBefore === null || $attempt->gt(CarbonImmutable::parse($this->refreshAttemptBefore)));
        if (! $started) {
            return true;
        }

        // RefreshProject stamps the attempt first and indexed_at last; a failure sets a failed state instead.
        $indexed = $model->indexed_at !== null && $model->indexed_at->gte($attempt);
        $failed = in_array($model->state, [Project::STATE_STALE, Project::STATE_UNREACHABLE], true);

        return ! ($indexed || $failed);
    }

    /**
     * The About tile's data: the brief's "What it is" HTML (an empty string when the
     * brief has no such section), whether there is a brief at all, and why it could
     * not be read. Reader failures log through the handbook's own event.
     *
     * @return array{state: string, html: string|null, error: string|null}
     */
    private function about(Project $model, ReadHandbook $handbook): array
    {
        if ($model->sha === null) {
            return ['state' => 'unread', 'html' => null, 'error' => null];
        }

        try {
            $html = $handbook->about($model);
        } catch (GitReaderException $e) {
            Log::warning('board.handbook_read_failed', ['project' => $model->name, 'section' => 'about', 'error' => $e->getMessage()]);

            return ['state' => 'failed', 'html' => null, 'error' => $e->getMessage()];
        }

        return match (true) {
            $html === null => ['state' => 'missing', 'html' => null, 'error' => null],
            $html === '' => ['state' => 'no-section', 'html' => null, 'error' => null],
            default => ['state' => 'ok', 'html' => $html, 'error' => null],
        };
    }

    /**
     * Run one tile's data source. A source that throws gives null — that tile's error
     * state — and is logged with the tile's name and the exception's class, the same
     * event the home overview uses, so one broken reader never takes the page down.
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
            Log::error('board.overview_panel_failed', ['project' => $this->project, 'panel' => $panel, 'error' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
