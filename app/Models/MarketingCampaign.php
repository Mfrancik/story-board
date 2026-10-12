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

    /** Every photo choice, in the order the campaign form offers them (MK-7). */
    public const PHOTO_MODES = [self::PHOTO_PER_POST, self::PHOTO_FAVOURITES, self::PHOTO_TEXT_ONLY, self::PHOTO_AI_DECIDES];

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
     * The campaign in one sentence (MK-7), "Every Tuesday at 7pm, post about TrueView Tuesday, with a photo you add".
     */
    public function sentence(): string
    {
        return self::describe($this->days, (string) $this->time, $this->topics, $this->photo_mode);
    }

    /**
     * The one-sentence description of a campaign's criteria, saved or still being typed (the create form shows it
     * from its inputs). A missing day or topic reads as a gap to fill, never as an error.
     *
     * Topics: a category with three or more ticked ideas is named by its category, otherwise by the ideas' titles;
     * one own topic is quoted, more are counted, as the prototype words it.
     *
     * @param  array<int, mixed>  $days  ISO weekdays
     * @param  array{ideas?: list<string>, own?: list<array{text: string, detail?: string|null}>}  $topics
     */
    public static function describe(array $days, string $time, array $topics, string $photoMode): string
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $picked = collect($days)->map(fn ($d) => (int) $d)->filter(fn (int $d) => isset($names[$d]))->unique()->sort()->map(fn (int $d) => $names[$d]);
        $when = $picked->isEmpty() ? 'Every … (pick a day)' : 'Every '.$picked->join(', ', ' and ');

        $at = 'at …';
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            $hour = (int) $m[1];
            $at = 'at '.(($hour % 12) ?: 12).($m[2] === '00' ? '' : ':'.$m[2]).($hour < 12 ? 'am' : 'pm');
        }

        $byCategory = [];
        foreach ($topics['ideas'] ?? [] as $key) {
            [$category, $title] = array_pad(explode('|', (string) $key, 2), 2, '');
            $byCategory[$category][] = $title !== '' ? $title : $category;
        }
        $bits = array_map(fn (string $category, array $titles) => count($titles) >= 3 ? $category : collect($titles)->join(', ', ' and '), array_keys($byCategory), $byCategory);
        $own = array_values(array_filter($topics['own'] ?? [], fn ($o) => trim($o['text']) !== ''));
        if (count($own) === 1) {
            $bits[] = '“'.trim($own[0]['text']).'”';
        } elseif (count($own) > 1) {
            $bits[] = count($own).' topics in your own words';
        }
        $about = $bits === [] ? '… (add a topic)' : collect($bits)->join(', ', ' and ');

        $photo = [
            self::PHOTO_PER_POST => 'with a photo you add',
            self::PHOTO_FAVOURITES => 'with a photo from your favourites',
            self::PHOTO_TEXT_ONLY => 'as text only',
            self::PHOTO_AI_DECIDES => 'with a photo or as text, as the AI decides',
        ][$photoMode] ?? 'with a photo you add';

        return "{$when} {$at}, post about {$about}, {$photo}";
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
