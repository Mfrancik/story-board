<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListLiveSessions;
use App\Actions\Board\ListNeedsMe;
use App\Actions\Board\ReadOverviewFigures;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
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
 */
#[Layout('layouts.shell')]
#[Title('Home')]
class Overview extends Component
{
    /**
     * Render the overview, or the one "no projects" empty state when the board has
     * no enabled project.
     *
     * Side effects: reads the stored snapshot, the cached session scan and the
     * preflight CSVs; logs `board.overview_viewed` (info, counts) on the page load
     * (not on each poll) and `board.overview_panel_failed` (error) for a panel whose
     * source threw.
     */
    public function render(ListNeedsMe $needs, ListLiveSessions $sessions, ReadOverviewFigures $figures): View
    {
        $projects = Project::enabled()->count();
        if ($projects === 0) {
            $this->logViewed(['projects' => 0]);

            return view('livewire.board.overview', ['empty' => true]);
        }

        $data = [
            'empty' => false,
            'needs' => $this->attempt('needs', fn () => $needs->handle()),
            'live' => $this->attempt('live', fn () => $sessions->overview()),
            'figures' => $this->attempt('figures', fn () => $figures->handle()),
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
