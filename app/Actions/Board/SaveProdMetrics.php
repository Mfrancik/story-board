<?php

namespace App\Actions\Board;

use App\Exceptions\ProdMetricsRefusedException;
use App\Models\ProdMetric;
use App\Models\Project;
use App\Services\ProductionReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Save metrics" on the Production panel (SB-17): stores the four presets (on
 * or off, with their table and columns) and the custom metrics, in display
 * order. Every enabled metric must pass the same pre-flight ProductionReader
 * runs before a read, so nothing is stored that could never run.
 */
class SaveProdMetrics
{
    public function __construct(private ProductionReader $reader) {}

    /**
     * Replace the project's metrics with the form's.
     *
     * Side effects: upserts `prod_metrics` rows and deletes custom ones no longer
     * in the form, in one transaction; logs board.prod_metrics_saved, or
     * board.prod_metrics_refused (warning) and stores nothing.
     *
     * @param  array<string, array{enabled: bool, table: string, column: string, distinct: string}>  $presets  keyed by ProdMetric::PRESETS key
     * @param  list<array{key: string, label: string, sql: string}>  $customs
     * @return int how many metrics are enabled
     *
     * @throws ProdMetricsRefusedException when there is no connection, or a metric is incomplete or unsafe.
     */
    public function handle(Project $project, array $presets, array $customs): int
    {
        $rows = $this->rows($project, $presets, $customs);

        DB::transaction(function () use ($project, $rows) {
            $project->prodMetrics()->whereNotIn('key', array_column($rows, 'key'))->delete();
            foreach ($rows as $row) {
                $project->prodMetrics()->updateOrCreate(['key' => $row['key']], $row);
            }
        });

        $enabled = count(array_filter($rows, fn (array $r) => $r['is_enabled']));
        Log::info('board.prod_metrics_saved', ['project' => $project->name, 'enabled' => $enabled, 'custom' => count($customs)]);

        return $enabled;
    }

    /**
     * The rows to store, presets first, each checked.
     *
     * @param  array<string, array{enabled: bool, table: string, column: string, distinct: string}>  $presets
     * @param  list<array{key: string, label: string, sql: string}>  $customs
     * @return list<array{key: string, kind: string, label: string, config: array<string, string>|null, sql: string|null, position: int, is_enabled: bool}>
     */
    private function rows(Project $project, array $presets, array $customs): array
    {
        if ($project->prodConnection === null) {
            throw $this->refuse($project, 'presets', 'no_connection', 'Save a connection first.');
        }

        $rows = [];
        foreach (ProdMetric::PRESETS as $key => $preset) {
            $form = $presets[$key] ?? ['enabled' => false, 'table' => '', 'column' => '', 'distinct' => ''];
            $config = [];
            foreach ($preset['fields'] as $field) {
                $config[$field] = trim($form[$field]);
            }
            $rows[] = ['key' => $key, 'kind' => ProdMetric::KIND_PRESET, 'label' => $preset['label'], 'config' => $config,
                'sql' => null, 'position' => count($rows), 'is_enabled' => $form['enabled']];
        }
        foreach ($customs as $i => $custom) {
            if (trim($custom['label']) === '') {
                throw $this->refuse($project, "customs.{$i}.label", 'no_label', 'Give the metric a name.');
            }
            $rows[] = ['key' => $custom['key'], 'kind' => ProdMetric::KIND_CUSTOM, 'label' => trim($custom['label']), 'config' => null,
                'sql' => trim($custom['sql']), 'position' => count($rows), 'is_enabled' => true];
        }

        foreach ($rows as $row) {
            if (! $row['is_enabled']) {
                continue;
            }
            $refusal = $this->reader->refuseMetric(new ProdMetric($row));
            if ($refusal !== null) {
                $field = $row['kind'] === ProdMetric::KIND_CUSTOM
                    ? 'customs.'.($row['position'] - count(ProdMetric::PRESETS)).'.sql'
                    : "presets.{$row['key']}.table";

                throw $this->refuse($project, $field, (string) $refusal->reason, (string) $refusal->message);
            }
        }

        return $rows;
    }

    /**
     * Log a refusal and build the exception for the form.
     */
    private function refuse(Project $project, string $field, string $reason, string $message): ProdMetricsRefusedException
    {
        Log::warning('board.prod_metrics_refused', ['project' => $project->name, 'reason' => $reason, 'field' => $field]);

        return new ProdMetricsRefusedException($field, $reason, $message);
    }
}
