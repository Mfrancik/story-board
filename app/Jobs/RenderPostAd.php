<?php

namespace App\Jobs;

use App\Actions\Marketing\BuildAdHtml;
use App\Actions\Marketing\BuildDefaultAdSpec;
use App\Actions\Marketing\StorePostRenders;
use App\Exceptions\MarketingRenderException;
use App\Models\MarketingPost;
use App\Services\Marketing\AdRendererClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders one post's ad on the queue (MK-5): both sizes from the post's stored spec, in its campaign's ad style, then
 * StorePostRenders keeps them and drops the old ones. It never calls Claude.
 *
 * Unique per post until it starts: a second request while one is waiting is the same render (the job reads the post
 * when it runs), but one made while it runs — a rebuild mid-render — queues its own, so the last change always gets
 * drawn. Tried once: a failure shows "Couldn't render · Retry" and the post keeps its HTML preview.
 */
class RenderPostAd implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** One attempt; the owner's Retry is the retry. */
    public int $tries = 1;

    /** Past AdRenderer's own timeout, so the renderer's error, not the worker's kill, is what gets logged. */
    public int $timeout = 120;

    /** Long enough for a busy queue; a lock never outlives a lost job by more. */
    public int $uniqueFor = 600;

    /** The request that asked for the render, so this job's log lines trace back to it. */
    public ?string $requestId;

    /**
     * @param  int  $postId  the post to render
     */
    public function __construct(public int $postId)
    {
        $this->requestId = Context::get('request_id');
    }

    /**
     * One waiting render per post.
     */
    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    /**
     * Render and store both sizes. A renderer failure is recorded on the post and logged; an unexpected error is too,
     * then rethrown so the queue records it.
     *
     * Side effects: runs the browser (AdRendererClient); writes files, assets and the post (StorePostRenders); logs
     * `marketing.render.succeeded` (post, layout, ms, bytes) or `marketing.render.failed` (warning: post, layout,
     * reason), or `marketing.render.skipped` (reason `gone`) for a post deleted before the worker got to it.
     */
    public function handle(BuildAdHtml $html, AdRendererClient $renderer, StorePostRenders $store, BuildDefaultAdSpec $default): void
    {
        // On a worker the originating request's Context is gone; restore its ID for this job's logs.
        if ($this->requestId !== null) {
            Context::add('request_id', $this->requestId);
        }

        $post = MarketingPost::find($this->postId);
        if ($post === null) {
            Log::info('marketing.render.skipped', ['post_id' => $this->postId, 'reason' => 'gone']);

            return;
        }

        $spec = $post->adSpec() ?? $default->handle($post);
        $layout = $html->layoutFor($post, $spec);
        $context = ['post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'layout' => $layout];
        $started = hrtime(true);

        try {
            $pages = [];
            foreach (MarketingPost::RENDER_SIZES as $size => [$width, $height]) {
                $pages[$size] = ['html' => $html->handle($post, $spec, $size), 'width' => $width, 'height' => $height];
            }
            $images = $renderer->render($pages);
            $store->handle($post, array_map(fn (array $image) => $image['png'], $images));
        } catch (MarketingRenderException $e) {
            Log::warning('marketing.render.failed', [...$context, 'reason' => $e->reason, 'message' => $e->getMessage()]);
            $store->failed($post, $e->reason);

            return;
        } catch (Throwable $e) {
            Log::warning('marketing.render.failed', [...$context, 'reason' => 'exception', 'message' => $e->getMessage()]);
            $store->failed($post, 'exception');

            throw $e;
        }

        Log::info('marketing.render.succeeded', [...$context,
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'bytes' => array_sum(array_map(fn (array $image) => strlen($image['png']), $images)),
            // The CSS shrink-to-fit should never let a headline overflow; if one does, this is where it shows.
            'overflow' => in_array(true, array_column($images, 'overflow'), true),
        ]);
    }
}
