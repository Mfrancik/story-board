<?php

namespace App\Models;

use Database\Factories\ProdMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One number the board reads from a project's production database (SB-17): a
 * preset pointed at a table and columns, or a custom single SELECT. SB-18 reads
 * every enabled one, in `position` order, through ProductionReader.
 *
 * @property int $id
 * @property int $project_id
 * @property string $key
 * @property string $kind
 * @property string $label
 * @property array{table?: string, column?: string, distinct?: string}|null $config
 * @property string|null $sql
 * @property int $position
 * @property bool $is_enabled
 * @property-read Project $project
 */
class ProdMetric extends Model
{
    /** @use HasFactory<ProdMetricFactory> */
    use HasFactory;

    /** A built-in count pointed at a table (and a timestamp column). */
    public const KIND_PRESET = 'preset';

    /** The owner's own single SELECT returning one number. */
    public const KIND_CUSTOM = 'custom';

    /**
     * The presets, in display order. `fields` are what the owner points it at:
     * `table` always, `column` (a timestamp, counted from local midnight) for the
     * "today" ones, `distinct` (count distinct values instead of rows) for logins.
     * The defaults are coins' real columns (checked 2026-09-29).
     *
     * @var array<string, array{label: string, hint: string, fields: list<string>, table: string, column?: string, distinct?: string}>
     */
    public const PRESETS = [
        'total_users' => ['label' => 'Total users', 'hint' => 'Every row in a table.', 'fields' => ['table'], 'table' => 'users'],
        'new_today' => ['label' => 'New today', 'hint' => 'Rows whose timestamp is today.', 'fields' => ['table', 'column'],
            'table' => 'users', 'column' => 'created_at'],
        'active_today' => ['label' => 'Active today', 'hint' => 'Users seen since local midnight.', 'fields' => ['table', 'column'],
            'table' => 'users', 'column' => 'last_seen_at'],
        'logged_in_today' => ['label' => 'Logged in today', 'hint' => 'Distinct users with a login event today.',
            'fields' => ['table', 'column', 'distinct'], 'table' => 'user_login_events', 'column' => 'occurred_at', 'distinct' => 'user_id'],
    ];

    /** @var list<string> */
    protected $fillable = ['project_id', 'key', 'kind', 'label', 'config', 'sql', 'position', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * The project whose production database this metric reads.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether this is one of the built-in presets rather than custom SQL.
     */
    public function isPreset(): bool
    {
        return $this->kind === self::KIND_PRESET;
    }
}
