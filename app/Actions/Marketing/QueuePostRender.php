<?php

namespace App\Actions\Marketing;

use App\Jobs\RenderPostAd;
use App\Models\MarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * Asks for a post's ad to be rendered (MK-5). Every way in comes through here — a draft written (Fill, Regenerate,
 * a one-off), a rewrite (Revise, Undo, a caption edit), a photo change, Approve on a post with no render, the
 * studio's Retry and the `marketing:render` backfill — so the spec is stored and "rendering" is marked before the job
 * is queued (on the sync queue the job has already finished by the time dispatch returns).
 *
 * The job never calls Claude. When re-speccing, the spec is the one Claude chose for the post (MK-19, recorded in
 * `generation_meta.ad` by Fill and Revise) cleaned against the post as it is now (NormaliseAdSpec), or MK-5's default
 * (BuildDefaultAdSpec) for a post Claude never art-directed.
 */
class QueuePostRender
{
    /**
     * @param  BuildDefaultAdSpec  $default  the spec for a post Claude never art-directed
     * @param  NormaliseAdSpec  $normalise  Claude's recorded spec, made drawable against the post's current photo (MK-19)
     */
    public function __construct(private BuildDefaultAdSpec $default, private NormaliseAdSpec $normalise) {}

    /**
     * Store `$post`'s spec, mark it rendering and queue RenderPostAd. With `$respec` the spec is built again (the
     * words, photo or chosen spec changed): from Claude's recorded choice when there is one, else from the caption and
     * photo; without, a stored spec is kept (Retry, Approve, the backfill, and Fill and Revise, which store the spec
     * they normalised themselves). Returns false,
     * queueing nothing, for a post that is not a draft or approved post still to go out, or that still needs its photo.
     *
     * Side effects: writes the post's `ad`; queues a job; logs `marketing.render.queued` (post, campaign, layout) or
     * `marketing.render.skipped` (reason `not_renderable` | `needs_photo`); re-speccing from Claude's choice logs
     * NormaliseAdSpec's `marketing.ai.ad_spec_fixed` for anything it had to fix.
     */
    public function handle(MarketingPost $post, bool $respec = true): bool
    {
        // Only a post that can still go out is worth an image; a skipped, discarded or posted one never changes again.
        if (! in_array($post->status, [MarketingPost::STATUS_DRAFT, MarketingPost::STATUS_APPROVED], true) || $post->isPosted()) {
            return $this->skip($post, 'not_renderable');
        }
        // Its photo is still to come; the photo change renders it. A text card now would show an ad it will not be.
        if ($post->needsPhoto()) {
            return $this->skip($post, 'needs_photo');
        }

        $spec = $respec ? null : $post->adSpec();
        $chosen = $post->generation_meta['ad'] ?? null;
        // Claude's choice outlives a photo or caption change (MK-19); only a post it never art-directed gets the default.
        $spec ??= is_array($chosen) ? $this->normalise->handle($post, $chosen) : $this->default->handle($post);
        $render = is_array($post->ad['render'] ?? null) ? $post->ad['render'] : [];
        // The previous images stay until the new ones are stored, so the studio has something to show meanwhile.
        $kept = array_intersect_key($render, MarketingPost::RENDER_SIZES);
        $post->update(['ad' => [...$spec, 'render' => [...$kept, 'status' => MarketingPost::RENDER_RENDERING, 'at' => now()->toIso8601String()]]]);

        Log::info('marketing.render.queued', ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'layout' => $spec['layout']]);
        RenderPostAd::dispatch($post->id);

        return true;
    }

    /**
     * Log why `$post` is not rendered, and say so.
     */
    private function skip(MarketingPost $post, string $reason): bool
    {
        Log::info('marketing.render.skipped', ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'status' => $post->status, 'reason' => $reason]);

        return false;
    }
}
