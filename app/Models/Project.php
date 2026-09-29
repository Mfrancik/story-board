<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A registered project: a local checkout whose stories the board reads from a git ref.
 *
 * @property int $id
 * @property string $name
 * @property string $path
 * @property string $ref
 * @property bool $is_enabled
 * @property string $state
 * @property string|null $sha
 * @property Carbon|null $indexed_at
 * @property string|null $last_error
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** Registered, never refreshed. */
    public const STATE_PENDING = 'pending';

    /** The snapshot matches the ref as of `indexed_at`. */
    public const STATE_OK = 'ok';

    /** The last refresh failed; the previous snapshot is kept and shown. */
    public const STATE_STALE = 'stale';

    /** The path is gone or is no longer a git repository. */
    public const STATE_UNREACHABLE = 'unreachable';

    /** A snapshot older than this is refreshed when the board is loaded. */
    public const STALE_AFTER_MINUTES = 5;

    /** @var list<string> */
    protected $fillable = ['name', 'path', 'ref', 'is_enabled', 'state', 'sha', 'indexed_at', 'last_error'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * The stories in this project's latest snapshot.
     *
     * @return HasMany<Story, $this>
     */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    /**
     * Only projects that take part in refreshes and on the board.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /**
     * Whether the snapshot is missing or old enough to refresh on page load.
     */
    public function needsRefresh(): bool
    {
        return $this->indexed_at === null
            || $this->indexed_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
