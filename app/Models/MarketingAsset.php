<?php

namespace App\Models;

use Database\Factories\MarketingAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One marketing file of a project (MK-2): a photo (a favourite from Settings, or one uploaded for a single post in
 * MK-9), with MK-18 an inspiration image, and with MK-5 a post's rendered ad. The bytes are on the `local` disk at `path`, served only by the
 * `marketing.asset` route and only under the asset's own project.
 *
 * @property int $id
 * @property int $project_id
 * @property string $purpose
 * @property bool $is_favourite
 * @property string $path relative to the local disk's root, under `marketing/{project}/`
 * @property string $original_name
 * @property string $mime
 * @property int|null $width
 * @property int|null $height
 * @property array<string, mixed>|null $ai_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingAsset extends Model
{
    /** @use HasFactory<MarketingAssetFactory> */
    use HasFactory;

    /** A photo a post can carry. */
    public const PURPOSE_PHOTO = 'photo';

    /** A past ad the AI reads for examples (MK-18). */
    public const PURPOSE_INSPIRATION = 'inspiration';

    /** A post's rendered ad, one per size (MK-5); the post's `ad.render` holds the ids. */
    public const PURPOSE_RENDER = 'render';

    /** The image types a marketing photo may be: what Facebook takes and every browser shows. */
    public const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * The largest image accepted anywhere in Marketing, in KB (10 MB): far above a phone photo or a saved ad, well
     * below what Facebook refuses. Settings, the studio and the Inspiration wall all validate against it.
     */
    public const MAX_KB = 10 * 1024;

    /**
     * The `mime` of an inspiration saved from a post with no photo (MK-18): there is no file (`path` is empty), the
     * caption in `ai_notes` is drawn as the brand text card instead.
     */
    public const TEXT_CARD_MIME = 'text/x-marketing-text-card';

    /** `ai_notes.status` while the one vision read is queued or running (MK-18). */
    public const READ_READING = 'reading';

    /** `ai_notes.status` once the read stored its notes (or the notes came with the asset: kit, saved post). */
    public const READ_DONE = 'read';

    /** `ai_notes.status` when the read failed; the owner may Retry. */
    public const READ_FAILED = 'failed';

    /** No read has been asked for (a favourite photo from before MK-18, until the backfill). */
    public const READ_UNREAD = 'unread';

    /**
     * Minutes after which a read still "reading" is treated as failed: past the job's timeout, so the job is gone (no
     * worker, or it died) and the owner should get Retry rather than a spinner forever.
     */
    public const READ_STALE_MINUTES = 10;

    /** @var list<string> */
    protected $fillable = ['project_id', 'purpose', 'is_favourite', 'path', 'original_name', 'mime', 'width', 'height', 'ai_notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_favourite' => 'boolean',
            'ai_notes' => 'array',
        ];
    }

    /**
     * The project's favourite photos, newest first (Settings' grid; MK-9's "Use a favourite").
     *
     * @param  Builder<MarketingAsset>  $query
     */
    public function scopeFavouritePhotos(Builder $query): void
    {
        $query->where('purpose', self::PURPOSE_PHOTO)->where('is_favourite', true)->latest('id');
    }

    /**
     * The project's inspiration images (MK-18), newest first.
     *
     * @param  Builder<MarketingAsset>  $query
     */
    public function scopeInspiration(Builder $query): void
    {
        $query->where('purpose', self::PURPOSE_INSPIRATION)->latest('id');
    }

    /**
     * Where the asset's one AI read stands: READ_UNREAD, READ_READING, READ_DONE or READ_FAILED. A read left
     * "reading" for more than READ_STALE_MINUTES reads as failed, so a lost job can be retried.
     */
    public function readState(): string
    {
        $status = $this->ai_notes['status'] ?? null;
        if ($status === self::READ_READING && $this->updated_at !== null && $this->updated_at->lt(now()->subMinutes(self::READ_STALE_MINUTES))) {
            return self::READ_FAILED;
        }

        return in_array($status, [self::READ_READING, self::READ_DONE, self::READ_FAILED], true) ? $status : self::READ_UNREAD;
    }

    /**
     * Whether this is an inspiration saved from a text-only post: no file, drawn as the brand text card.
     */
    public function isTextCard(): bool
    {
        return $this->mime === self::TEXT_CARD_MIME;
    }

    /**
     * The project the file belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The posts that use this file as their photo.
     *
     * @return HasMany<MarketingPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(MarketingPost::class, 'asset_id');
    }
}
