<?php

namespace App\Actions\Board;

use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use App\Services\Production\ConnectionCheck;
use App\Services\Production\MetricReading;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the Production page shows (SB-18, option B), from the board's own
 * database only — never production: one row per shown, connected project with
 * each enabled metric's latest snapshot, its change against the snapshots one
 * and seven days before, 30 days of trend, and the outcome of the project's last
 * read (ReadProductionProject::outcome). Two queries whatever the project count.
 */
class ListProductionDashboard
{
    /** Days of trend line, today included. */
    public const DAYS = 30;

    /** Trend colour slots (app.css `--color-series-*`); a project keeps its slot everywhere on the page. */
    public const SLOTS = 3;

    /**
     * Build the page's rows.
     *
     * @param  list<int>  $loading  projects with a read queued and not yet back (shown as a skeleton)
     * @param  list<int>  $stalled  projects whose queued read never came back (no worker)
     * @return array{rows: list<array<string, mixed>>, columns: list<array{key: string, label: string}>, notConnected: Collection<int, Project>}
     */
    public function handle(array $loading = [], array $stalled = []): array
    {
        $projects = Project::enabled()
            ->with(['prodConnection:id,project_id', 'prodMetrics' => fn ($q) => $q->where('is_enabled', true)->orderBy('position')->orderBy('id')])
            ->orderBy('name')
            ->get();
        [$connected, $notConnected] = $projects->partition(fn (Project $p) => $p->prodConnection !== null);

        $today = CarbonImmutable::parse(ReadProductionProject::today());
        $since = $today->subDays(self::DAYS - 1);
        $snapshots = [];
        $window = ProdSnapshot::whereIn('prod_metric_id', $connected->flatMap->prodMetrics->pluck('id'))
            ->where('day', '>=', $since->toDateString())
            ->orderBy('day')
            ->get();
        foreach ($window as $snapshot) {
            $snapshots[$snapshot->prod_metric_id][$snapshot->day->toDateString()] = $snapshot;
        }

        $rows = [];
        foreach ($connected->values() as $i => $project) {
            $rows[] = $this->row($project, $i % self::SLOTS + 1, $snapshots, $today, $since,
                in_array($project->id, $loading, true), in_array($project->id, $stalled, true));
        }

        return ['rows' => $rows, 'columns' => $this->columns($connected), 'notConnected' => $notConnected->values()];
    }

    /**
     * A number as the page writes it: whole numbers with thousands separators, anything else to two places.
     */
    public static function format(float $value): string
    {
        return floor($value) === $value ? number_format($value) : rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    /**
     * One project's row.
     *
     * @param  array<int, array<string, ProdSnapshot>>  $snapshots  by metric id, then local day, oldest first
     * @return array<string, mixed>
     */
    private function row(Project $project, int $slot, array $snapshots, CarbonImmutable $today, CarbonImmutable $since, bool $loading, bool $stalled): array
    {
        $outcome = ReadProductionProject::outcome($project->id);
        // Which failure, if any, the row shows: a queue that never answered, else the last read's own.
        $error = match (true) {
            $stalled => ['label' => 'No answer', 'message' => 'No answer from the queue worker after a minute — is composer run dev running? Showing the last good values.'],
            $outcome !== null && $outcome['status'] !== ConnectionCheck::OK => self::connectionError($outcome),
            default => null,
        };
        $state = $loading ? 'loading' : ($error !== null ? ($stalled ? 'stalled' : 'error') : 'ok');

        $metrics = [];
        foreach ($project->prodMetrics as $metric) {
            $metricError = $error === null ? ($outcome['metrics'][$metric->key] ?? null) : null;
            $metrics[$metric->key] = $this->stat($metric, $snapshots[$metric->id] ?? [], $today, $error !== null, $metricError);
        }

        $reads = collect($metrics)->pluck('readAt')->filter();
        $first = $project->prodMetrics->map(fn (ProdMetric $m) => array_key_first($snapshots[$m->id] ?? []))->filter()->min();

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slot' => $slot,
            'state' => $state,
            'error' => $error,
            'readAgo' => $reads->isEmpty() ? null : $reads->max()->diffForHumans(),
            // A project with under 30 days of history says where its line starts.
            'historySince' => $first !== null && $first > $since->toDateString() ? CarbonImmutable::parse($first)->format('M j') : null,
            'metrics' => $metrics,
        ];
    }

    /**
     * One metric's stat: latest value (the last good one when the read failed),
     * its changes and its trend.
     *
     * @param  array<string, ProdSnapshot>  $days  its snapshots in the window, by local day, oldest first
     * @param  array{status: string, message: string|null}|null  $error  this metric's own failure on the last read
     * @return array<string, mixed>
     */
    private function stat(ProdMetric $metric, array $days, CarbonImmutable $today, bool $rowFailed, ?array $error): array
    {
        $latest = $days === [] ? null : end($days);
        $value = $latest?->value;
        // Changes are measured from the value's own day, so a greyed last-good value is never compared with itself.
        $from = $latest->day ?? $today;

        $series = [];
        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $day = $today->subDays($d);
            $series[] = ['day' => $day->format('D M j').($d === 0 ? ' (today)' : ''), 'value' => ($days[$day->toDateString()] ?? null)?->value];
        }

        return [
            'key' => $metric->key,
            'label' => $metric->label,
            'custom' => ! $metric->isPreset(),
            'value' => $value,
            'text' => $value === null ? '—' : self::format($value),
            'stale' => $value !== null && ($rowFailed || $error !== null),
            'readAt' => $latest?->read_at,
            'readAgo' => $latest?->read_at->diffForHumans(),
            'day' => self::change($value, ($days[$from->subDay()->toDateString()] ?? null)?->value),
            'week' => self::change($value, ($days[$from->subDays(7)->toDateString()] ?? null)?->value),
            'series' => $series,
            'error' => $error === null ? null : ($error['status'] === MetricReading::TIMED_OUT ? 'Timed out. ' : '').$error['message'],
        ];
    }

    /**
     * A signed change against an earlier snapshot, "—" when there is none that day.
     *
     * @return array{text: string, dir: 'up'|'down'|'flat'|'none'}
     */
    private static function change(?float $value, ?float $then): array
    {
        if ($value === null || $then === null) {
            return ['text' => '—', 'dir' => 'none'];
        }

        $delta = $value - $then;

        return match (true) {
            $delta > 0 => ['text' => '+'.self::format($delta), 'dir' => 'up'],
            $delta < 0 => ['text' => '−'.self::format(-$delta), 'dir' => 'down'],
            default => ['text' => '±0', 'dir' => 'flat'],
        };
    }

    /**
     * The row's badge and sentence for a read the connection check stopped.
     *
     * @param  array{status: string, reason: string|null, message: string}  $outcome
     * @return array{label: string, message: string}
     */
    private static function connectionError(array $outcome): array
    {
        $label = match (true) {
            $outcome['status'] === ConnectionCheck::UNREACHABLE => 'Unreachable',
            $outcome['reason'] === 'can_write' => 'Refused: this user can now write',
            $outcome['status'] === ConnectionCheck::REFUSED => 'Refused: this user can do more than SELECT',
            default => 'Could not connect',
        };

        return ['label' => $label, 'message' => $outcome['message']];
    }

    /**
     * The table's metric columns: each preset any row tracks, in preset order,
     * then one Custom column holding each project's own SELECTs.
     *
     * @param  Collection<int, Project>  $connected
     * @return list<array{key: string, label: string}>
     */
    private function columns(Collection $connected): array
    {
        $metrics = $connected->flatMap->prodMetrics;
        $keys = $metrics->filter(fn (ProdMetric $m) => $m->isPreset())->pluck('key')->unique();

        $columns = [];
        foreach (ProdMetric::PRESETS as $key => $preset) {
            if ($keys->contains($key)) {
                $columns[] = ['key' => $key, 'label' => $preset['label']];
            }
        }
        if ($metrics->contains(fn (ProdMetric $m) => ! $m->isPreset())) {
            $columns[] = ['key' => 'custom', 'label' => 'Custom'];
        }

        return $columns;
    }
}
