<?php

namespace App\Actions\Board;

use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * A project's latest preflight run for its overview (RD-11): the newest row of its
 * cost CSVs (ReadPreflightHistory) with the verdict and runner of the run record it
 * matches (ReadPreflightRuns, RD-8), and runs per day against all-pass runs per day
 * over the last CHART_DAYS days. A run with no matching record has no verdict —
 * the tile shows "—", never a guess. Reads only, like both readers it calls.
 */
class ReadLatestPreflight
{
    /** Days in the runs-vs-all-pass chart, today included (option B's 13-day strip). */
    public const CHART_DAYS = 13;

    /** The verdict a record writes for a run whose every gate passed (ADR-055). */
    public const ALL_PASS = 'GO';

    /**
     * @param  ReadPreflightHistory  $history  the one reader of preflight CSVs (SB-16)
     * @param  ReadPreflightRuns  $records  the one reader of preflight run records (RD-8)
     */
    public function __construct(private ReadPreflightHistory $history, private ReadPreflightRuns $records) {}

    /**
     * The latest run and the chart, or null when the project has no readable run.
     *
     * Side effects: reads the project's CSVs and run records; the readers log
     * `board.preflight_history_unreadable` and `board.preflight_run_record_skipped`.
     *
     * @return array{
     *     latest: array{at: CarbonImmutable, branch: string|null, mode: string|null, wall: int|null, tokens: int|null, ran_on: string|null, verdict: string|null},
     *     days: list<array{day: CarbonImmutable, runs: int, pass: int}>,
     * }|null
     */
    public function handle(Project $project): ?array
    {
        $runs = $this->history->handle($project)['runs'];
        if ($runs === []) {
            return null;
        }

        $records = $this->records->handle($project)['records'];
        $pairs = $this->records->pairs($runs, $records);
        $verdict = fn (int $i): ?string => $pairs[$i] === null ? null : $records[$pairs[$i]]['verdict'];
        $latest = $runs[0];

        return [
            'latest' => [
                'at' => $latest['ts'],
                'branch' => $latest['branch'],
                'mode' => $latest['mode'],
                'wall' => $latest['wall'],
                'tokens' => $latest['tokens'],
                'ran_on' => $pairs[0] === null ? null : $records[$pairs[0]]['ran_on'],
                'verdict' => $verdict(0),
            ],
            'days' => $this->days($runs, $verdict),
        ];
    }

    /**
     * Runs and all-pass runs per day, oldest first, in the board's time zone (the
     * day the owner lived it). A day with no run is a zero, not a gap: no run is a fact.
     *
     * @param  list<array{ts: CarbonImmutable}>  $runs
     * @param  callable(int): (string|null)  $verdict
     * @return list<array{day: CarbonImmutable, runs: int, pass: int}>
     */
    private function days(array $runs, callable $verdict): array
    {
        $tz = (string) config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $days = [];
        for ($d = self::CHART_DAYS - 1; $d >= 0; $d--) {
            $day = $today->subDays($d);
            $days[$day->toDateString()] = ['day' => $day, 'runs' => 0, 'pass' => 0];
        }

        foreach ($runs as $i => $run) {
            $key = $run['ts']->setTimezone($tz)->toDateString();
            if (! isset($days[$key])) {
                continue;
            }
            $days[$key]['runs']++;
            if ($verdict($i) === self::ALL_PASS) {
                $days[$key]['pass']++;
            }
        }

        return array_values($days);
    }
}
