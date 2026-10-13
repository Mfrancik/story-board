<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingPost;
use App\Models\MarketingPostNetwork;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Mark as posted" (and "Post it now" on a missed post) in the studio (MK-9): in phase 1 the owner posts by hand,
 * then tells the app. The optional Facebook post link is kept as the post's `facebook` network row, so phase 2 can
 * backfill engagement without asking again. The caller has validated the link.
 */
class MarkPostPosted
{
    use RefusesPostActions;

    /**
     * Mark approved `$post` posted at `$now`, with the Facebook link `$url` when given.
     *
     * Side effects: writes the post and, with a link, its facebook network row; logs `marketing.post.posted`
     * (has_link) or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post is not approved
     */
    public function handle(MarketingPost $post, ?string $url, CarbonInterface $now): MarketingPost
    {
        if ($post->status !== MarketingPost::STATUS_APPROVED || $post->isPosted()) {
            $this->refuse('mark_posted', $post, 'not_approved', 'Only an approved post can be marked as posted.');
        }
        $url = $url === null || trim($url) === '' ? null : trim($url);

        DB::transaction(function () use ($post, $url, $now) {
            $post->update(['status' => MarketingPost::STATUS_POSTED, 'posted_at' => $now]);
            if ($url !== null) {
                MarketingPostNetwork::updateOrCreate(
                    ['post_id' => $post->id, 'network' => MarketingPostNetwork::FACEBOOK],
                    ['status' => MarketingPostNetwork::STATUS_POSTED, 'url' => $url],
                );
            }
        });

        Log::info('marketing.post.posted', ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version, 'has_link' => $url !== null]);

        return $post;
    }
}
