<?php

namespace App\Models;

use Database\Factories\MarketingFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of the owner's taste (MK-8): a like or dislike of a post (with reasons), or the written feedback given
 * when revising it. BuildTasteExamples turns the latest of these into examples in every prompt: taste is examples,
 * not training, and it shapes the words only.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $campaign_id
 * @property int $post_id
 * @property int|null $vote 1 liked, -1 disliked, null for written feedback only
 * @property list<string>|null $reasons
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class MarketingFeedback extends Model
{
    /** @use HasFactory<MarketingFeedbackFactory> */
    use HasFactory;

    /** A like. */
    public const LIKED = 1;

    /** A dislike. */
    public const DISLIKED = -1;

    /** Feedback is never edited, only added, so it carries only a creation time. */
    public const UPDATED_AT = null;

    /** "Feedback" is uncountable: the table is not "feedbacks". */
    protected $table = 'marketing_feedback';

    /** @var list<string> */
    protected $fillable = ['project_id', 'campaign_id', 'post_id', 'vote', 'reasons', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vote' => 'integer',
            'reasons' => 'array',
        ];
    }

    /**
     * The project whose taste this is.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The campaign the post was for, or null for a one-off (or a deleted campaign).
     *
     * @return BelongsTo<MarketingCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    /**
     * The post the feedback is about.
     *
     * @return BelongsTo<MarketingPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(MarketingPost::class, 'post_id');
    }
}
