<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MarketingPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One marketing post (MK-1): a caption (and later a photo) for a date, from a
 * campaign or one-off. "Missed" is not a status: an approved post whose time has
 * passed without being marked posted is missed, and that is derived on read
 * (BuildCampaignRunway), so it can never disagree with the clock.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $campaign_id
 * @property Carbon|null $scheduled_for
 * @property string $status
 * @property string $caption
 * @property int $version 1 as drafted, +1 per revision (MK-8); earlier versions are in `versions`
 * @property int|null $asset_id
 * @property string $source
 * @property array<string, mixed>|null $generation_meta
 * @property list<array{code: string, match: string}>|null $lint copy-rules warnings on the current caption (MK-8)
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingPost extends Model
{
    /** @use HasFactory<MarketingPostFactory> */
    use HasFactory;

    /** A candidate waiting for the owner's approval. */
    public const STATUS_DRAFT = 'draft';

    /** Picked and confirmed by the owner; goes out at `scheduled_for`. */
    public const STATUS_APPROVED = 'approved';

    /** Marked as posted (by hand in phase 1). */
    public const STATUS_POSTED = 'posted';

    /** The owner chose not to post on this date. */
    public const STATUS_SKIPPED = 'skipped';

    /** A candidate the owner threw away; ignored everywhere. */
    public const STATUS_DISCARDED = 'discarded';

    /** Every stored status. There is deliberately no "missed". */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_POSTED, self::STATUS_SKIPPED, self::STATUS_DISCARDED];

    /** @var list<string> */
    protected $fillable = ['project_id', 'campaign_id', 'scheduled_for', 'status', 'caption', 'version', 'asset_id', 'source', 'generation_meta', 'lint', 'posted_at'];

    /**
     * A new post is version 1, so an unsaved or just-created post reads its version without a re-fetch.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'posted_at' => 'datetime',
            'generation_meta' => 'array',
            'lint' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * The project the post belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The campaign that scheduled it, or null for a one-off.
     *
     * @return BelongsTo<MarketingCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    /**
     * The post's photo, if it has one (MK-2). Deleting the photo leaves the post photo-less.
     *
     * @return BelongsTo<MarketingAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }

    /**
     * The post's earlier versions, oldest first (MK-8). The post row itself is always the current version.
     *
     * @return HasMany<MarketingPostVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(MarketingPostVersion::class, 'post_id')->orderBy('version');
    }

    /**
     * The owner's likes, dislikes and written feedback on this post (MK-8).
     *
     * @return HasMany<MarketingFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(MarketingFeedback::class, 'post_id');
    }

    /**
     * The posts a campaign's runway reads (BuildCampaignRunway): every dated, kept
     * post from a day before `$now` on (the day's slack covers a date that starts
     * earlier in the campaign's timezone than in UTC), plus every approved post still
     * not marked posted, however old, because that one is missed until the owner acts.
     *
     * @param  Builder<MarketingPost>  $query
     */
    public function scopeRunwayWindow(Builder $query, CarbonInterface $now): void
    {
        $query->whereNotNull('scheduled_for')
            ->where('status', '!=', self::STATUS_DISCARDED)
            ->where(fn (Builder $q) => $q->where('scheduled_for', '>=', $now->copy()->subDay())
                ->orWhere(fn (Builder $q) => $q->where('status', self::STATUS_APPROVED)->whereNull('posted_at')))
            ->orderBy('scheduled_for');
    }

    /**
     * Whether the post went out: its status says so, or it carries a posted time.
     */
    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED || $this->posted_at !== null;
    }
}
