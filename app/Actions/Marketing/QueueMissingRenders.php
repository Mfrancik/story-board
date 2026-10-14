<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingKitException;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * The one-time backfill for posts from before MK-5 (`marketing:render`): one render for each draft or approved post
 * still to go out that has no render yet. Safe to run again: a post rendered, or being rendered, is left alone, and
 * a spec already on a post is kept.
 */
class QueueMissingRenders
{
    /**
     * @param  QueuePostRender  $render  marks a post rendering and queues its job
     */
    public function __construct(private QueuePostRender $render) {}

    /**
     * Queue a render for each of `$projectName`'s draft and approved posts with no render that is not rendering.
     * Returns how many were queued (a post still waiting for its photo is not).
     *
     * Side effects: one RenderPostAd job per post (QueuePostRender logs each); logs `marketing.render.backfill_queued`.
     *
     * @throws MarketingKitException for an unknown project (logged as `marketing.render.backfill_refused`)
     */
    public function handle(string $projectName): int
    {
        $project = Project::where('name', $projectName)->first();
        if ($project === null) {
            Log::warning('marketing.render.backfill_refused', ['project' => $projectName, 'reason' => 'unknown_project']);

            throw new MarketingKitException("No project named \"{$projectName}\".", 'unknown_project');
        }

        $queued = $project->marketingPosts()->with('campaign')
            ->whereIn('status', [MarketingPost::STATUS_DRAFT, MarketingPost::STATUS_APPROVED])->whereNull('posted_at')->orderBy('id')->get()
            ->filter(fn (MarketingPost $p) => $p->renderId('square') === null && $p->renderState() !== MarketingPost::RENDER_RENDERING)
            ->filter(fn (MarketingPost $p) => $this->render->handle($p, respec: false))
            ->count();

        Log::info('marketing.render.backfill_queued', ['project' => $project->name, 'queued' => $queued]);

        return $queued;
    }
}
