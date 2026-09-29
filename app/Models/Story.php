<?php

namespace App\Models;

use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One story file at a project's ref, as bin/story-index reported it on the last refresh.
 *
 * @property int $id
 * @property int $project_id
 * @property string|null $story_id
 * @property string|null $title
 * @property string|null $status
 * @property string|null $initiative
 * @property string|null $journey
 * @property string $path
 * @property string|null $source
 * @property list<string> $depends_on
 * @property array{dir: string|null, options: list<string>, chosen: string|null} $mockups
 * @property list<string> $parse_errors
 * @property string $sha
 */
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'story_id', 'title', 'status', 'initiative', 'journey', 'path', 'source',
        'depends_on', 'mockups', 'parse_errors', 'sha',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
