<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingBrand;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Undo" next to a revised draft's version mark in the studio (MK-9): the draft goes back to the version ReviseDraft
 * (MK-8) kept before rewriting it — caption, photo and generation meta — and that kept version is removed, so the
 * post row is again the current version. The feedback given on it stays: it is still the owner's taste.
 */
class UndoRevision
{
    use RefusesPostActions;

    /**
     * @param  LintCaption  $lint  re-run on the restored caption
     * @param  QueuePostRender  $render  queues the restored version's ad render (MK-5)
     */
    public function __construct(private LintCaption $lint, private QueuePostRender $render) {}

    /**
     * Put draft `$post` back to its previous version.
     *
     * Side effects: writes the post, deletes its latest kept version; queues its ad render (MK-5); logs
     * `marketing.post.revision_undone` or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post is not a draft or has no earlier version
     */
    public function handle(MarketingPost $post): MarketingPost
    {
        if ($post->status !== MarketingPost::STATUS_DRAFT) {
            $this->refuse('undo', $post, 'not_draft', 'Only a draft can be undone.');
        }
        $previous = $post->versions()->reorder('version', 'desc')->first();
        if ($previous === null) {
            $this->refuse('undo', $post, 'no_version', 'There is no earlier version.');
        }

        $rules = MarketingBrand::where('project_id', $post->project_id)->value('copy_rules');
        DB::transaction(function () use ($post, $previous, $rules) {
            $post->update([
                'caption' => $previous->caption, 'asset_id' => $previous->asset_id, 'version' => $previous->version,
                'generation_meta' => $previous->generation_meta, 'lint' => $this->lint->handle($previous->caption, is_array($rules) ? $rules : []),
            ]);
            $previous->delete();
        });

        Log::info('marketing.post.revision_undone', ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version]);
        $this->render->handle($post);

        return $post;
    }
}
