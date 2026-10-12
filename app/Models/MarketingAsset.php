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
 * MK-9) or, with MK-18, an inspiration image. The bytes are on the `local` disk at `path`, served only by the
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

    /** The image types a marketing photo may be: what Facebook takes and every browser shows. */
    public const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

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
