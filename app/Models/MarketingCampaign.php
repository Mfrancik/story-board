<?php

namespace App\Models;

use Database\Factories\MarketingCampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A project's recurring marketing campaign (MK-1): a named weekly schedule
 * ("TrueView Tuesday · Tue 19:00") whose dates are filled with posts. Generic: any
 * project can run campaigns, nothing here is coin-shaped.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property array<int, mixed> $days ISO weekdays, 1 = Monday … 7 = Sunday; stored JSON, so CampaignSchedule checks each
 * @property string $time wall-clock time in `timezone`, "HH:MM:SS"
 * @property string $timezone
 * @property bool $is_enabled
 * @property array{ideas: list<string>, own: list<array{text: string, detail: string|null}>} $topics
 * @property string|null $brief
 * @property string $photo_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingCampaign extends Model
{
    /** @use HasFactory<MarketingCampaignFactory> */
    use HasFactory;

    /** The owner adds a photo to each post (a new coin, a fresh shot). */
    public const PHOTO_PER_POST = 'per_post';

    /** Posts use one of the project's favourite photos (MK-2). */
    public const PHOTO_FAVOURITES = 'favourites';

    /** Posts are caption only. */
    public const PHOTO_TEXT_ONLY = 'text_only';

    /** The AI picks a photo or none (MK-8). */
    public const PHOTO_AI_DECIDES = 'ai_decides';

    /** @var list<string> */
    protected $fillable = ['project_id', 'name', 'slug', 'days', 'time', 'timezone', 'is_enabled', 'topics', 'brief', 'photo_mode'];

    /**
     * A new campaign starts with an empty topic pool, so `topics` always has both keys.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'topics' => '{"ideas":[],"own":[]}',
        'is_enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'array',
            'topics' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * The schedule in a few words, "Tue 19:00 weekly" or "Mon, Thu 10:00 weekly".
     * Needs no timezone, so a campaign whose timezone is broken still reads.
     */
    public function scheduleLabel(): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = collect($this->days)->sort()->map(fn ($d) => is_int($d) ? ($names[$d] ?? '?') : '?')->implode(', ');

        return trim($days.' '.substr((string) $this->time, 0, 5).' weekly');
    }

    /**
     * The project this campaign posts for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The campaign's posts, every status.
     *
     * @return HasMany<MarketingPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(MarketingPost::class, 'campaign_id');
    }
}
