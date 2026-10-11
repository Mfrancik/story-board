<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;
use Carbon\CarbonImmutable;

/**
 * The overview's two headline figures that are not lists on the page (RD-5):
 * preflight runs of the last 7 days across every enabled project, with their median
 * wall time, and In flight — approved stories not yet built. Live sessions and Needs
 * me are counted from their own panels' data. Reads the board's database and the
 * projects' preflight CSVs (through ReadPreflightHistory); writes nothing.
 */
class ReadOverviewFigures
{
    /** The Preflight figure's window, in days. */
    public const PREFLIGHT_DAYS = 7;

    /**
     * @param  ReadPreflightHistory  $history  the one reader of preflight CSVs (SB-16)
     */
    public function __construct(private ReadPreflightHistory $history) {}

    /**
     * The Preflight and In flight figures.
     *
     * Side effects: reads every enabled project's preflight CSVs; ReadPreflightHistory
     * logs `board.preflight_history_unreadable` for a CSV it cannot read.
     *
     * @return array{preflight: array{runs: int, wall: float|null}, in_flight: int}
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $runs = [];
        foreach (Project::enabled()->get() as $project) {
            array_push($runs, ...$this->history->handle($project)['runs']);
        }
        $week = $this->history->figures($runs, $now->subDays(self::PREFLIGHT_DAYS), $now);

        return [
            'preflight' => ['runs' => $week['runs'], 'wall' => $week['wall']],
            // The owner's definition: approved and not yet built, on the ref — the same set Needs me calls
            // ready plus the approved stories still awaiting a pick.
            'in_flight' => ListNeedsMe::readyToBuild(Story::onRef())
                ->whereHas('project', fn ($q) => $q->where('is_enabled', true))->count(),
        ];
    }
}
