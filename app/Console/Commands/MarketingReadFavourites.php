<?php

namespace App\Console\Commands;

use App\Actions\Marketing\QueueFavouriteReads;
use App\Exceptions\MarketingKitException;
use Illuminate\Console\Command;

/**
 * `marketing:read-favourites {project}` — the one-time backfill (MK-18): queue one AI read for each favourite photo
 * that has no description yet, so Fill picks photos by what they show, not by file name. Run once after deploying
 * MK-18, with a queue worker running; safe to run again (see QueueFavouriteReads).
 */
class MarketingReadFavourites extends Command
{
    /** @var string */
    protected $signature = 'marketing:read-favourites {project : The board project whose favourite photos to read, e.g. coins}';

    /** @var string */
    protected $description = 'Queue one AI read for each favourite photo that has no description yet';

    /**
     * Queue the reads and say how many.
     *
     * Side effects: see QueueFavouriteReads (writes `ai_notes.status`, queues jobs, logs).
     */
    public function handle(QueueFavouriteReads $queue): int
    {
        try {
            $count = $queue->handle((string) $this->argument('project'));
        } catch (MarketingKitException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued {$count} favourite ".($count === 1 ? 'photo' : 'photos').' to read.');

        return self::SUCCESS;
    }
}
