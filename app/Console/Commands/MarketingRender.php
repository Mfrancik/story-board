<?php

namespace App\Console\Commands;

use App\Actions\Marketing\QueueMissingRenders;
use App\Exceptions\MarketingKitException;
use Illuminate\Console\Command;

/**
 * `marketing:render {project}` — the one-time backfill (MK-5): queue one ad render for each draft and approved post
 * that has none, so posts from before the renderer show as finished ads too. Run once after deploying MK-5, with a
 * queue worker running; safe to run again (see QueueMissingRenders).
 */
class MarketingRender extends Command
{
    /** @var string */
    protected $signature = 'marketing:render {project : The board project whose posts to render, e.g. coins}';

    /** @var string */
    protected $description = 'Queue one ad render for each draft and approved post that has none yet';

    /**
     * Queue the renders and say how many.
     *
     * Side effects: see QueueMissingRenders (writes posts' `ad`, queues jobs, logs).
     */
    public function handle(QueueMissingRenders $queue): int
    {
        try {
            $count = $queue->handle((string) $this->argument('project'));
        } catch (MarketingKitException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued {$count} ".($count === 1 ? 'post' : 'posts').' to render.');

        return self::SUCCESS;
    }
}
