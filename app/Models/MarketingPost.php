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
 * @property bool $is_text_only the owner chose "Text only" in the studio (MK-9): no photo is wanted
 * @property array<string, mixed>|null $ad the ad spec and its render (MK-5): {layout, headline, subline, cta, asset_id, crop, badge, render: {status, square, portrait, reason, at}}
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

    /** The ad layouts (MK-5), each drawn in every ad style (MarketingCampaign::AD_STYLES). */
    public const AD_LAYOUTS = ['photo_headline', 'text_card', 'question', 'spotlight', 'stat'];

    /** The layouts built around the post's photo; without one they are drawn as the text card. */
    public const PHOTO_LAYOUTS = ['photo_headline', 'spotlight'];

    /** The keys of an ad spec, in order: what MK-19's art director will fill and BuildDefaultAdSpec fills until then. */
    public const AD_SPEC_KEYS = ['layout', 'headline', 'subline', 'cta', 'asset_id', 'crop', 'badge'];

    /** Every post is rendered at these sizes (px): Facebook's square and its tallest feed shape. */
    public const RENDER_SIZES = ['square' => [1080, 1080], 'portrait' => [1080, 1350]];

    /** No render has been asked for (a post from before MK-5, or one still waiting for its photo). */
    public const RENDER_NONE = 'none';

    /** A render is queued or running. */
    public const RENDER_RENDERING = 'rendering';

    /** Both sizes are stored. */
    public const RENDER_RENDERED = 'rendered';

    /** The last render failed; the studio offers Retry. */
    public const RENDER_FAILED = 'failed';

    /**
     * Minutes after which a render still "rendering" counts as failed: past the job's timeout, so the job is gone (no
     * worker, or it died) and the owner should get Retry rather than "Rendering…" forever.
     */
    public const RENDER_STALE_MINUTES = 10;

    /** @var list<string> */
    protected $fillable = ['project_id', 'campaign_id', 'scheduled_for', 'status', 'caption', 'version', 'asset_id', 'is_text_only', 'ad', 'source', 'generation_meta', 'lint', 'posted_at'];

    /**
     * A new post is version 1, so an unsaved or just-created post reads its version without a re-fetch.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'is_text_only' => false,
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
            'is_text_only' => 'boolean',
            'ad' => 'array',
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
     * Where the post went out (MK-9): one row per network, Facebook's holding the link "Mark as posted" saved.
     *
     * @return HasMany<MarketingPostNetwork, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(MarketingPostNetwork::class, 'post_id');
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

    /**
     * Whether the post is missed as of `$now`: approved, its time passed, not marked posted. Derived, never stored
     * (the same rule as BuildCampaignRunway's `missed`).
     */
    public function isMissed(CarbonInterface $now): bool
    {
        return $this->status === self::STATUS_APPROVED && ! $this->isPosted() && $this->scheduled_for !== null && $this->scheduled_for < $now;
    }

    /**
     * Whether the post is held back from approval for want of a photo (MK-9): its campaign wants a photo per post,
     * it has none, and the owner has not chosen "Text only". Reads `campaign` (eager-load it in lists).
     */
    public function needsPhoto(): bool
    {
        return $this->asset_id === null && ! $this->is_text_only
            && $this->campaign?->photo_mode === MarketingCampaign::PHOTO_PER_POST;
    }

    /**
     * The post's ad spec (MK-5) — layout, headline, subline, cta, asset_id, crop, badge — or null before its first
     * render asked for one.
     *
     * @return array{layout: string, headline: string, subline: string|null, cta: string|null, asset_id: int|null, crop: string|null, badge: string|null}|null
     */
    public function adSpec(): ?array
    {
        $ad = $this->ad;
        if (! is_array($ad) || ! is_string($ad['layout'] ?? null)) {
            return null;
        }
        $spec = [];
        foreach (self::AD_SPEC_KEYS as $key) {
            $spec[$key] = $ad[$key] ?? null;
        }
        $spec['headline'] = (string) $spec['headline'];

        /** @var array{layout: string, headline: string, subline: string|null, cta: string|null, asset_id: int|null, crop: string|null, badge: string|null} $spec */
        return $spec;
    }

    /**
     * Where the post's render stands: RENDER_NONE, RENDER_RENDERING, RENDER_RENDERED or RENDER_FAILED. A render left
     * "rendering" for more than RENDER_STALE_MINUTES reads as failed, so a lost job can be retried.
     */
    public function renderState(): string
    {
        $render = is_array($this->ad['render'] ?? null) ? $this->ad['render'] : [];
        $status = $render['status'] ?? null;
        if ($status === self::RENDER_RENDERING) {
            $at = is_string($render['at'] ?? null) ? Carbon::parse($render['at']) : null;

            return $at !== null && $at->lt(now()->subMinutes(self::RENDER_STALE_MINUTES)) ? self::RENDER_FAILED : self::RENDER_RENDERING;
        }

        return in_array($status, [self::RENDER_RENDERED, self::RENDER_FAILED], true) ? $status : self::RENDER_NONE;
    }

    /**
     * The id of the post's stored render at `$size` (a RENDER_SIZES key), or null when it has none — including while
     * a first render is still running. A re-render keeps the previous ids until the new files are stored.
     */
    public function renderId(string $size): ?int
    {
        $id = $this->ad['render'][$size] ?? null;

        return is_int($id) ? $id : null;
    }
}
