<?php

namespace App\Models;

use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One story file at a project's ref, as bin/story-index reported it on the last refresh.
 *
 * @property int $id
 * @property int $project_id
 * @property string|null $story_id
 * @property string|null $title
 * @property string|null $status
 * @property string|null $initiative
 * @property bool $is_parked
 * @property string|null $journey
 * @property string $path
 * @property string|null $source
 * @property Carbon|null $dated_on
 * @property list<string> $depends_on
 * @property array{dir: string|null, options: list<string>, chosen: string|null} $mockups
 * @property list<string> $parse_errors
 * @property string $sha
 * @property string|null $location_kind
 * @property string|null $location
 * @property string|null $branch
 */
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    /** Committed on a branch that is not merged into the project's ref. */
    public const KIND_BRANCH = 'branch';

    /** Committed on an unmerged branch that is checked out in a worktree. */
    public const KIND_WORKTREE = 'worktree';

    /** An untracked file in one of the project's checkouts. */
    public const KIND_UNTRACKED = 'untracked';

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'story_id', 'title', 'status', 'initiative', 'is_parked', 'journey', 'path', 'source', 'dated_on',
        'depends_on', 'mockups', 'parse_errors', 'sha', 'location_kind', 'location', 'branch',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_parked' => 'boolean',
            'dated_on' => 'date',
            'depends_on' => 'array',
            'mockups' => 'array',
            'parse_errors' => 'array',
        ];
    }

    /**
     * The project this story was read from.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Only the ref's snapshot — what SB-2 indexed and what the home groups count.
     *
     * @param  Builder<Story>  $query
     */
    public function scopeOnRef(Builder $query): void
    {
        $query->whereNull('location_kind');
    }

    /**
     * Only work that is not on the ref yet (SB-5).
     *
     * @param  Builder<Story>  $query
     */
    public function scopeOffMain(Builder $query): void
    {
        $query->whereNotNull('location_kind');
    }

    /**
     * Whether this row's files can be read from git: the ref's snapshot, or a
     * branch commit. Untracked files are not in git, so the board cannot serve them.
     */
    public function isInGit(): bool
    {
        return $this->location_kind !== self::KIND_UNTRACKED;
    }

    /**
     * Where this version lives, as a phrase: "on branch x", "in worktree /p",
     * "untracked in /p" — null for the ref's own row.
     */
    public function placePhrase(): ?string
    {
        return match ($this->location_kind) {
            self::KIND_BRANCH => "on {$this->location}",
            self::KIND_WORKTREE => "in {$this->location}",
            self::KIND_UNTRACKED => $this->location,
            default => null,
        };
    }

    /**
     * URL of one of this row's mockup files. Off-main rows name their version
     * (`v`), so the file is read from that branch's commit, not the ref.
     */
    public function mockupUrl(string $file): string
    {
        $params = ['project' => $this->project->name, 'storyId' => (string) $this->story_id, 'file' => $file];

        return route('mockups.file', $this->location_kind === null ? $params : [...$params, 'v' => $this->id]);
    }

    /**
     * Whether this row stands for a mockup directory alone (no story file beside it
     * in that location): it has no status of its own, and that is not an error.
     */
    public function isMockupOnly(): bool
    {
        return str_starts_with($this->path, 'docs/mockups/');
    }

    /**
     * Whether the story ID is well formed (CLAUDE.md step 6), so it can have a page.
     */
    public function hasPage(): bool
    {
        return $this->story_id !== null && preg_match('/^[A-Z]{2,}-[0-9]+[a-z]?$/D', $this->story_id) === 1;
    }
}
