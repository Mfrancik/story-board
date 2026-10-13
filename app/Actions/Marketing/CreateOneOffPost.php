<?php

namespace App\Actions\Marketing;

use App\Actions\Marketing\Concerns\RefusesPostActions;
use App\Exceptions\MarketingPostRefusedException;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * "+ One-off post" (MK-9): a manual post on a date the owner chooses, in no campaign — a show this weekend, a
 * one-time offer. It is written by the owner (`source` manual) and starts as a draft, so it is approved in the
 * studio like any other post.
 */
class CreateOneOffPost
{
    use RefusesPostActions;

    /**
     * @param  LintCaption  $lint  the copy-rules lint
     */
    public function __construct(private LintCaption $lint) {}

    /**
     * Create a draft one-off post for `$project` at `$at` with `$caption`.
     *
     * Side effects: writes the post; logs `marketing.post.one_off_created` or `marketing.post.refused`.
     *
     * @throws MarketingPostRefusedException when `$at` is not after `$now`, or the caption is empty
     */
    public function handle(Project $project, CarbonImmutable $at, string $caption, CarbonInterface $now): MarketingPost
    {
        $caption = trim($caption);
        if ($at->lessThanOrEqualTo($now)) {
            $this->refuse('one_off', null, 'past', 'That time has passed. Pick a later one.', ['project' => $project->name]);
        }
        if ($caption === '') {
            $this->refuse('one_off', null, 'empty_caption', 'A post needs a caption.', ['project' => $project->name]);
        }

        $rules = $project->marketingBrand()->value('copy_rules');
        $post = MarketingPost::create([
            'project_id' => $project->id, 'campaign_id' => null, 'scheduled_for' => $at->utc(), 'status' => MarketingPost::STATUS_DRAFT,
            'caption' => $caption, 'source' => 'manual', 'lint' => $this->lint->handle($caption, is_array($rules) ? $rules : []),
        ]);
        Log::info('marketing.post.one_off_created', ['post_id' => $post->id, 'campaign_id' => null, 'version' => $post->version, 'project' => $project->name]);

        return $post;
    }
}
