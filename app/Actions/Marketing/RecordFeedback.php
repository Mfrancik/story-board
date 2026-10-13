<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Thumbs up / down on a post in the studio (MK-9), with optional reasons on a dislike: taste that BuildTasteExamples
 * (MK-8) feeds into later prompts. A post has at most one vote: a new vote replaces the old one (delete and add, as
 * feedback rows are never edited), so a change of mind is never counted twice. Written notes from Revise are kept.
 */
class RecordFeedback
{
    use RefusesPostActions;

    /** The dislike reasons the studio offers, as stored. */
    public const REASONS = ['too wordy', 'wrong tone', 'boring', 'off-brand'];

    /**
     * Set `$post`'s vote to `$vote` (1 like, -1 dislike, 0 none) with `$reasons` (dislikes only, from REASONS).
     *
     * Side effects: replaces the post's vote row; logs `marketing.feedback.recorded` (vote, reasons) or
     * `marketing.post.refused`.
     *
     * @param  list<string>  $reasons
     *
     * @throws MarketingPostRefusedException when the vote is not 1, -1 or 0, or a reason is unknown or on a like
     */
    public function handle(MarketingPost $post, int $vote, array $reasons = []): void
    {
        $reasons = array_values(array_unique($reasons));
        if (! in_array($vote, [MarketingFeedback::LIKED, MarketingFeedback::DISLIKED, 0], true)) {
            $this->refuse('feedback', $post, 'bad_vote', 'Like or dislike.');
        }
        if (array_diff($reasons, self::REASONS) !== [] || ($reasons !== [] && $vote !== MarketingFeedback::DISLIKED)) {
            $this->refuse('feedback', $post, 'unknown_reason', 'Pick one of the reasons shown.');
        }

        DB::transaction(function () use ($post, $vote, $reasons) {
            $post->feedback()->whereNotNull('vote')->delete();
            if ($vote !== 0) {
                MarketingFeedback::create([
                    'project_id' => $post->project_id, 'campaign_id' => $post->campaign_id, 'post_id' => $post->id,
                    'vote' => $vote, 'reasons' => $reasons !== [] ? $reasons : null,
                ]);
            }
        });

        Log::info('marketing.feedback.recorded', [
            'post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version, 'vote' => $vote, 'reasons' => $reasons,
        ]);
    }
}
