<?php

namespace App\Models;

use Database\Factories\MarketingPostNetworkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where one marketing post went out (MK-9): in phase 1 the Facebook row "Mark as posted" writes with the post's link,
 * so phase 2 can backfill engagement. One row per post and network.
 *
 * @property int $id
 * @property int $post_id
 * @property string $network
 * @property string $status
 * @property string|null $external_id
 * @property string|null $url
 * @property array<string, mixed>|null $engagement
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingPostNetwork extends Model
{
    /** @use HasFactory<MarketingPostNetworkFactory> */
    use HasFactory;

    /** The one network in phase 1. */
    public const FACEBOOK = 'facebook';

    /** The post is live on the network. */
    public const STATUS_POSTED = 'posted';

    /** @var list<string> */
    protected $fillable = ['post_id', 'network', 'status', 'external_id', 'url', 'engagement', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engagement' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * The post this is a network copy of.
     *
     * @return BelongsTo<MarketingPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(MarketingPost::class, 'post_id');
    }
}
