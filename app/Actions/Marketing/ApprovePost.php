<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingPost;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Approve for <date>" in the studio (MK-9): the owner picks one draft for its date. It becomes `approved` and the
 * date's other drafts are `discarded` in the same transaction, so a date never has two candidates and a pick at
 * once. Approved-through (MK-1) is derived, so it advances by itself.
 */
class ApprovePost
{
    use RefusesPostActions;

    /**
     * @param  FindOccurrencePosts  $occurrence  the date's other drafts
     * @param  QueuePostRender  $render  renders a post approved before it ever was (MK-5)
     */
    public function __construct(private FindOccurrencePosts $occurrence, private QueuePostRender $render) {}

    /**
     * Approve draft `$post` and discard its date's other drafts.
     *
     * Side effects: writes the post and its siblings; queues its ad render when it has none (MK-5); logs
     * `marketing.post.approved` (post_id, campaign_id, version, discarded) or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post is not a draft, or still needs its photo
     */
    public function handle(MarketingPost $post): MarketingPost
    {
        if ($post->status !== MarketingPost::STATUS_DRAFT) {
            $this->refuse('approve', $post, 'not_draft', 'Only a draft can be approved.');
        }
        if ($post->needsPhoto()) {
            $this->refuse('approve', $post, 'needs_photo', 'Add a photo first: upload one, use a favourite, or choose Text only.');
        }

        $campaign = $post->campaign;
        $siblings = $campaign === null || $post->scheduled_for === null ? new Collection : $this->occurrence
            ->handle($campaign, $post->scheduled_for->copy()->setTimezone($campaign->timezone)->toDateString())
            ->where('status', MarketingPost::STATUS_DRAFT)
            ->where('id', '!=', $post->id);

        DB::transaction(function () use ($post, $siblings) {
            MarketingPost::whereKey($siblings->modelKeys())->update(['status' => MarketingPost::STATUS_DISCARDED]);
            $post->update(['status' => MarketingPost::STATUS_APPROVED]);
        });

        Log::info('marketing.post.approved', [
            'post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version, 'discarded' => $siblings->modelKeys(),
        ]);
        // An approved post must have its ad to post; one that has it (or is rendering) keeps it as it is.
        if (in_array($post->renderState(), [MarketingPost::RENDER_NONE, MarketingPost::RENDER_FAILED], true)) {
            $this->render->handle($post, respec: false);
        }

        return $post;
    }
}
