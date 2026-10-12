<?php

namespace App\Models;

use Database\Factories\MarketingPostVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An earlier version of a marketing post (MK-8): what the post said before ReviseDraft rewrote it, with the feedback
 * the owner gave on it. Written once, never changed.
 *
 * @property int $id
 * @property int $post_id
 * @property int $version
 * @property string $caption
 * @property int|null $asset_id
 * @property string|null $feedback what the owner said about this version
 * @property list<string>|null $chips the quick-feedback chips the owner ticked on it
 * @property array<string, mixed>|null $generation_meta
 * @property Carbon|null $created_at
 */
class MarketingPostVersion extends Model
{
    /** @use HasFactory<MarketingPostVersionFactory> */
    use HasFactory;

    /** History rows are never updated, so they carry only a creation time. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['post_id', 'version', 'caption', 'asset_id', 'feedback', 'chips', 'generation_meta'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'chips' => 'array',
            'generation_meta' => 'array',
        ];
    }

    /**
     * The post this is an earlier version of.
     *
     * @return BelongsTo<MarketingPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(MarketingPost::class, 'post_id');
    }

    /**
     * The photo this version carried, if any.
     *
     * @return BelongsTo<MarketingAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }
}
