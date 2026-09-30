<?php

namespace App\Actions\Board;

use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use App\Services\Production\MetricReading;
use App\Services\ProductionReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read one project's enabled production metrics and keep what came back (SB-18):
 * each good value upserts today's snapshot, and the outcome of the read — ok, or
 * why not — is remembered for the Production page. Shared by the queued
 * ReadProductionMetrics job (page loads, Refresh) and `board:prod-snapshot`.
 * Every production access goes through ProductionReader and its grants check.
 */
class ReadProductionProject
{
    /**
     * @param  ProductionReader  $reader  the only way the board reaches a production database (SB-17)
     */
    public function __construct(private ProductionReader $reader) {}

    /**
     * Read, snapshot and record one project.
     *
     * Side effects: one production connection; upserts prod_snapshots rows for
     * today (board.timezone); sets prod_connections.verified_at when the grants
     * check passes; stores the outcome under outcomeKey(). Logs board.prod_read
     * for every read and board.prod_read_failed (project and a reason code —
     * never a value, a host or MySQL's own text, which can quote data) when the
     * connection or a metric failed; board.prod_read_skipped when the project was
     * switched off or disconnected since the read was asked for.
     *
     * @return array{seq: int, at: int, ok: bool, status: string, reason: string|null, message: string, metrics: array<string, array{status: string, message: string|null}>}|null
     *                                                                                                                                                                            the recorded outcome, or null when skipped
     */
    public function handle(Project $project): ?array
    {
        $connection = $project->prodConnection;
        // The page and the schedule only ask for shown, connected projects, but a queued read can outlive either.
        if (! $project->is_enabled || $connection === null) {
            Log::info('board.prod_read_skipped', ['project' => $project->name, 'reason' => $project->is_enabled ? 'not_connected' : 'disabled']);

            return null;
        }

        $started = hrtime(true);
        $read = $this->reader->readEnabledMetrics($connection->setRelation('project', $project));
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        $failed = array_filter($read->readings, fn (MetricReading $r) => ! $r->ok());
        $ok = $read->check->ok() && $failed === [];

        if ($read->check->ok()) {
            $connection->forceFill(['verified_at' => now()])->save();
            $this->snapshot($project, array_filter($read->readings, fn (MetricReading $r) => $r->ok()));
        }

        Log::info('board.prod_read', ['project' => $project->name, 'metrics' => count($read->readings), 'ms' => $ms, 'ok' => $ok]);
        if (! $read->check->ok()) {
            Log::warning('board.prod_read_failed', ['project' => $project->name, 'reason' => $read->check->reason]);
        } elseif ($failed !== []) {
            // Reason codes only, per metric key: a failed query's message can quote production data.
            Log::warning('board.prod_read_failed', ['project' => $project->name, 'reason' => 'metrics_failed',
                'metrics' => array_map(fn (MetricReading $r) => $r->reason, $failed)]);
        }

        $previous = self::outcome($project->id);
        $outcome = [
            // Counts reads, so the page can tell "a read finished since I asked" without comparing clocks.
            'seq' => ($previous['seq'] ?? 0) + 1,
            'at' => now()->getTimestamp(),
            'ok' => $ok,
            'status' => $read->check->status,
            'reason' => $read->check->reason,
            'message' => $read->check->message,
            'metrics' => array_map(fn (MetricReading $r) => ['status' => $r->status, 'message' => $r->message], $failed),
        ];
        Cache::forever(self::outcomeKey($project->id), $outcome);

        return $outcome;
    }

    /**
     * The outcome of the project's last read, or null if none is remembered.
     *
     * @return array{seq: int, at: int, ok: bool, status: string, reason: string|null, message: string, metrics: array<string, array{status: string, message: string|null}>}|null
     */
    public static function outcome(int $projectId): ?array
    {
        $raw = Cache::get(self::outcomeKey($projectId));
        // Rebuilt field by field: the cache hands back whatever was stored, possibly by an older build.
        if (! is_array($raw) || ! is_int($raw['seq'] ?? null) || ! is_int($raw['at'] ?? null) || ! is_string($raw['status'] ?? null)) {
            return null;
        }

        $metrics = [];
        foreach (is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [] as $key => $metric) {
            if (is_array($metric) && is_string($metric['status'] ?? null)) {
                $metrics[(string) $key] = ['status' => $metric['status'], 'message' => is_string($metric['message'] ?? null) ? $metric['message'] : null];
            }
        }

        return [
            'seq' => $raw['seq'],
            'at' => $raw['at'],
            'ok' => ($raw['ok'] ?? false) === true,
            'status' => $raw['status'],
            'reason' => is_string($raw['reason'] ?? null) ? $raw['reason'] : null,
            'message' => is_string($raw['message'] ?? null) ? $raw['message'] : '',
            'metrics' => $metrics,
        ];
    }

    /**
     * The cache key of a project's last read outcome.
     */
    public static function outcomeKey(int $projectId): string
    {
        return 'board:prod-read:'.$projectId;
    }

    /**
     * The owner's calendar day now (board.timezone): the day a snapshot belongs to.
     */
    public static function today(): string
    {
        return Carbon::now(ProductionReader::today()['timezone'])->toDateString();
    }

    /**
     * Upsert today's snapshot for every metric that read a number.
     *
     * @param  array<string, MetricReading>  $readings  good readings, keyed by metric key
     */
    private function snapshot(Project $project, array $readings): void
    {
        if ($readings === []) {
            return;
        }

        $ids = ProdMetric::where('project_id', $project->id)->whereIn('key', array_keys($readings))->pluck('id', 'key');
        $day = self::today();
        $rows = [];
        foreach ($readings as $key => $reading) {
            // A metric removed on /projects while its read was in flight has no row left to snapshot.
            if (! isset($ids[$key])) {
                continue;
            }
            $rows[] = ['project_id' => $project->id, 'prod_metric_id' => $ids[$key], 'day' => $day,
                'value' => $reading->value, 'read_at' => now()];
        }

        if ($rows === []) {
            return;
        }

        // The latest read of the day wins: same metric and day updates the row instead of adding one.
        ProdSnapshot::upsert($rows, ['prod_metric_id', 'day'], ['value', 'read_at']);
    }
}
