<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProdSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One production metric's value on one local day (SB-18). Written only by
 * ReadProductionProject, which upserts today's row on every good read, so the
 * last read of the day is the one kept. The Production page compares against
 * these rows and draws its trend lines from them.
 *
 * @property int $id
 * @property int $project_id
 * @property int $prod_metric_id
 * @property CarbonImmutable $day
 * @property float $value
 * @property CarbonImmutable $read_at
 * @property-read Project $project
 * @property-read ProdMetric $metric
 */
class ProdSnapshot extends Model
{
    /** @use HasFactory<ProdSnapshotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['project_id', 'prod_metric_id', 'day', 'value', 'read_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'value' => 'float',
            'read_at' => 'immutable_datetime',
        ];
    }

    /**
     * The project whose production database this value came from.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The metric this is a value of.
     *
     * @return BelongsTo<ProdMetric, $this>
     */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(ProdMetric::class, 'prod_metric_id');
    }
}
