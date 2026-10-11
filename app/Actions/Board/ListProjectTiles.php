<?php

namespace App\Actions\Board;

use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use App\Models\Story;
use App\Services\ProjectFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The home overview's Projects grid (RD-7): one tile per covered project, with its
 * story progress (built · in flight · draft, counted with the Stories page's own
 * status→kind rule so the two always agree), how many versions live off main (the
 * rows IndexOffMain stored), its last preflight run, and — only with a production
 * connection — its first metric's latest stored value and 7 days of trend. Reads the
 * board's database and the preflight CSVs; never git, never a production database.
 */
class ListProjectTiles
{
    /** Days of production trend on a tile, today included. */
    public const PROD_DAYS = 7;

    /**
     * @param  ReadPreflightHistory  $history  the one reader of preflight CSVs (SB-16)
     */
    public function __construct(private ReadPreflightHistory $history) {}

    /**
     * Every covered project's tile, A–Z.
     *
     * `preflight.ran_on` is always null here: RD-8 fills it with where the run was made
     * ("on brain"), and the tile appends it when it is set.
     *
     * Side effects: reads each project's preflight CSVs; ReadPreflightHistory logs
     * `board.preflight_history_unreadable` for one it cannot read.
     *
     * @param  ProjectFilter|null  $filter  the projects to cover (RD-6); null is every enabled project
     * @return list<array{
     *     name: string, state: string,
     *     stories: array{built: int, approved: int, draft: int},
     *     offmain: int,
     *     preflight: array{at: CarbonImmutable, wall: int|null, mode: string|null, ran_on: string|null}|null,
     *     prod: array{label: string, value: float, text: string, series: list<array{day: string, value: float|null}>}|null,
     * }>
     */
    public function handle(?ProjectFilter $filter = null): array
    {
        $filter ??= ProjectFilter::all();
        $projects = $filter->projects()->orderBy('name')
            ->with(['prodConnection:id,project_id', 'prodMetrics' => fn ($q) => $q->where('is_enabled', true)->orderBy('position')->orderBy('id')])
            ->get();
        $ids = array_values(array_map(fn (Project $p): int => $p->id, $projects->all()));

        $kinds = $this->kinds($ids);
        $offMain = Story::offMain()->whereIn('project_id', $ids)->toBase()
            ->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');
        $prod = $this->prod($projects);

        $tiles = [];
        foreach ($projects as $project) {
            $run = $this->history->handle($project)['runs'][0] ?? null;
            $tiles[] = [
                'name' => $project->name,
                'state' => $project->state,
                'stories' => [
                    'built' => $kinds[$project->id]['built'] ?? 0,
                    'approved' => $kinds[$project->id]['approved'] ?? 0,
                    'draft' => $kinds[$project->id]['draft'] ?? 0,
                ],
                'offmain' => (int) ($offMain[$project->id] ?? 0),
                'preflight' => $run === null ? null : ['at' => $run['ts'], 'wall' => $run['wall'], 'mode' => $run['mode'], 'ran_on' => null],
                'prod' => $prod[$project->id] ?? null,
            ];
        }

        return $tiles;
    }

    /**
     * On-ref story counts by the Stories page's kind (ReadProjectStories::kind), per
     * project, from one grouped query: the tile and that page's tallies are the same sum.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function kinds(array $ids): array
    {
        $rows = Story::onRef()->whereIn('project_id', $ids)->toBase()
            ->selectRaw('project_id, status, count(*) as n')->groupBy('project_id', 'status')->get();

        $kinds = [];
        foreach ($rows as $row) {
            $kind = ReadProjectStories::kind($row->status);
            $kinds[$row->project_id][$kind] = ($kinds[$row->project_id][$kind] ?? 0) + (int) $row->n;
        }

        return $kinds;
    }

    /**
     * Each connected project's production headline: its first enabled metric's latest
     * stored value in the last PROD_DAYS days and that metric's daily series. A project
     * with no connection, no enabled metric or no snapshot in the window has none —
     * the tile shows no production row rather than an empty one. Stored snapshots only.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{label: string, value: float, text: string, series: list<array{day: string, value: float|null}>}>
     */
    private function prod(Collection $projects): array
    {
        $metrics = [];
        foreach ($projects as $project) {
            $first = $project->prodMetrics->first();
            // A connection removed leaves its metrics and snapshots behind; without one there is no production.
            if ($project->prodConnection !== null && $first instanceof ProdMetric) {
                $metrics[$first->id] = [$project->id, $first];
            }
        }
        if ($metrics === []) {
            return [];
        }

        // The board's local day, as the Production page and the snapshot writer count days.
        $today = CarbonImmutable::parse(ReadProductionProject::today());
        $since = $today->subDays(self::PROD_DAYS - 1);
        $byDay = [];
        $snapshots = ProdSnapshot::whereIn('prod_metric_id', array_keys($metrics))
            ->where('day', '>=', $since->toDateString())->orderBy('day')->get(['prod_metric_id', 'day', 'value']);
        foreach ($snapshots as $snapshot) {
            $byDay[$snapshot->prod_metric_id][$snapshot->day->toDateString()] = $snapshot->value;
        }

        $headlines = [];
        foreach ($metrics as $metricId => [$projectId, $metric]) {
            $days = $byDay[$metricId] ?? [];
            if ($days === []) {
                continue;
            }
            $series = [];
            for ($d = self::PROD_DAYS - 1; $d >= 0; $d--) {
                $day = $today->subDays($d);
                $series[] = ['day' => $day->format('D M j'), 'value' => $days[$day->toDateString()] ?? null];
            }
            $value = (float) end($days);
            $headlines[$projectId] = [
                'label' => $metric->label,
                'value' => $value,
                'text' => ListProductionDashboard::format($value),
                'series' => $series,
            ];
        }

        return $headlines;
    }
}
