<?php

namespace App\Models;

use Database\Factories\ProjectLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another checkout of a project the board scans for work not on the ref (SB-5):
 * a sibling clone registered as an alias, or a worktree git reported.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind
 * @property string $path
 * @property string|null $branch
 */
class ProjectLocation extends Model
{
    /** @use HasFactory<ProjectLocationFactory> */
    use HasFactory;

    /** A sibling clone registered with `board:project alias`. */
    public const KIND_ALIAS = 'alias';

    /** A worktree found by `git worktree list` on the last refresh. */
    public const KIND_WORKTREE = 'worktree';

    /** @var list<string> */
    protected $fillable = ['project_id', 'kind', 'path', 'branch'];

    /**
     * The project this checkout belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
