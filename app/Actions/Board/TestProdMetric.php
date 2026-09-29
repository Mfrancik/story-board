<?php

namespace App\Actions\Board;

use App\Models\ProdMetric;
use App\Models\Project;
use App\Services\Production\MetricReading;
use App\Services\ProductionReader;
use Illuminate\Support\Facades\Log;

/**
 * A metric's Test button on the Production panel (SB-17): run the metric as the
 * form currently has it — saved or not — on the project's saved connection.
 */
class TestProdMetric
{
    public function __construct(private ProductionReader $reader) {}

    /**
     * Read the metric's value once.
     *
     * Side effects: at most one production connection; logs board.prod_metric_tested
     * with the result, and for a failed query its redacted error. Never the value.
     */
    public function handle(Project $project, ProdMetric $metric): MetricReading
    {
        $connection = $project->prodConnection;
        $reading = $connection === null
            ? new MetricReading(MetricReading::REFUSED, message: 'Save a connection first — metrics run on the saved connection.', reason: 'no_connection')
            : $this->reader->testMetric($connection->setRelation('project', $project), $metric);

        Log::info('board.prod_metric_tested', array_filter([
            'project' => $project->name,
            'metric' => $metric->key,
            'kind' => $metric->kind,
            'result' => $reading->status,
            'reason' => $reading->reason,
            // Only a query's own error: connection messages name the host, which is never logged.
            'error' => $reading->reason === 'query_failed' ? $reading->message : null,
            'duration_ms' => $reading->ms,
        ], fn ($v) => $v !== null));

        return $reading;
    }
}
