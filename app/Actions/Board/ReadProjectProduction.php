<?php

namespace App\Actions\Board;

use App\Models\ProdSnapshot;
use App\Models\Project;
use App\Services\Production\MetricNumber;
use Carbon\CarbonImmutable;

/**
 * A project's production numbers for its overview (RD-11): each enabled metric
 * with its latest stored value and a daily series, from the snapshots
 * `board:prod-snapshot` keeps (SB-18). Stored snapshots only — never a production
 * database, so loading the overview never opens a connection (ProductionReader is
 * the only code that does, and it runs on the schedule and on /projects).
 */
class ReadProjectProduction
{
    /** Days of trend per metric, today included: the project tiles' window (RD-7), so the two lines agree. */
    public const DAYS = ListProjectTiles::PROD_DAYS;

    /**
     * Null when the project has no production connection; otherwise its enabled
     * metrics in their set order, each with the latest value in the window (null
     * when none was stored) and one series point per day, a missing day as null.
     *
     * @return list<array{label: string, value: float|null, text: string|null, series: list<array{day: string, value: float|null}>}>|null
     */
    public function handle(Project $project): ?array
    {
        // A connection removed leaves its metrics and snapshots behind; without one there is no production.
        if (! $project->prodConnection()->exists()) {
            return null;
        }

        $metrics = $project->prodMetrics()->where('is_enabled', true)->orderBy('position')->orderBy('id')->get(['id', 'label']);
        if ($metrics->isEmpty()) {
            return [];
        }

        // The board's local day, as the snapshot writer counts days.
        $today = CarbonImmutable::parse(ReadProductionProject::today());
        $byDay = [];
        $snapshots = ProdSnapshot::whereIn('prod_metric_id', $metrics->modelKeys())
            ->where('day', '>=', $today->subDays(self::DAYS - 1)->toDateString())
            ->orderBy('day')->get(['prod_metric_id', 'day', 'value']);
        foreach ($snapshots as $snapshot) {
            $byDay[$snapshot->prod_metric_id][$snapshot->day->toDateString()] = (float) $snapshot->value;
        }

        $out = [];
        foreach ($metrics as $metric) {
            $days = $byDay[$metric->id] ?? [];
            $series = [];
            for ($d = self::DAYS - 1; $d >= 0; $d--) {
                $day = $today->subDays($d);
                $series[] = ['day' => $day->format('D M j'), 'value' => $days[$day->toDateString()] ?? null];
            }
            // Snapshots come oldest first, so the last one kept is the latest.
            $value = $days === [] ? null : end($days);
            $out[] = [
                'label' => $metric->label,
                'value' => $value,
                'text' => $value === null ? null : MetricNumber::format($value),
                'series' => $series,
            ];
        }

        return $out;
    }
}
