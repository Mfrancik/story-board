<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingBrand;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * The studio's caption box (MK-9): the owner edits a draft's or approved post's caption directly. It stays the same
 * version (a hand edit is not a rewrite) and is linted again against the copy rules, warn-only.
 */
class EditPostCaption
{
    use RefusesPostActions;

    /** The longest caption kept: far past the two lines the copy rules ask for. */
    public const MAX_LENGTH = 2000;

    /**
     * @param  LintCaption  $lint  the copy-rules lint
     */
    public function __construct(private LintCaption $lint) {}

    /**
     * Set `$post`'s caption to `$caption` (trimmed) and re-lint it.
     *
     * Side effects: writes the post; logs `marketing.post.caption_edited` (lint codes) or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when the post has gone out, been skipped or discarded, or the caption is empty
     */
    public function handle(MarketingPost $post, string $caption): MarketingPost
    {
        $caption = trim($caption);
        if (! in_array($post->status, [MarketingPost::STATUS_DRAFT, MarketingPost::STATUS_APPROVED], true) || $post->isPosted()) {
            $this->refuse('edit_caption', $post, 'not_editable', 'Only a draft or an approved post can be edited.');
        }
        if ($caption === '') {
            $this->refuse('edit_caption', $post, 'empty_caption', 'A post needs a caption.');
        }

        $rules = MarketingBrand::where('project_id', $post->project_id)->value('copy_rules');
        $post->update(['caption' => $caption, 'lint' => $this->lint->handle($caption, is_array($rules) ? $rules : [])]);
        Log::info('marketing.post.caption_edited', [
            'post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'version' => $post->version, 'lint' => array_column((array) $post->lint, 'code'),
        ]);

        return $post;
    }
}
