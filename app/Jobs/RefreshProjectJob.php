<?php

namespace App\Jobs;

use App\Actions\Board\RefreshProject;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one project on the queue, outside the request that noticed it was
 * stale, so a page load never waits on `git fetch`. Unique per project: a burst
 * of page loads queues one refresh, not one each.
 */
class RefreshProjectJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Matches RefreshProject::LOCK_SECONDS: no second copy queues while one could still run. */
    public int $uniqueFor = 300;

    /** The request that asked for this refresh, so its log lines trace back to it. */
    public ?string $requestId;

    /**
     * @param  Project  $project  the project whose snapshot is out of date
     */
    public function __construct(public Project $project)
    {
        $this->requestId = Context::get('request_id');
    }

    /**
     * One queued refresh per project at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    /**
     * Run the refresh.
     *
     * RefreshProject logs every expected failure itself; an unexpected one (a DB
     * error) is logged here and rethrown. Logged in handle() rather than failed()
     * so the line carries this job's request_id context, which failed() would not.
     */
    public function handle(RefreshProject $refresh): void
    {
        // On a worker the originating request's Context is gone; restore its ID for this job's logs.
        if ($this->requestId !== null) {
            Context::add('request_id', $this->requestId);
        }

        try {
            $refresh->handle($this->project);
        } catch (Throwable $e) {
            Log::error('board.refresh_crashed', ['project' => $this->project->name, 'exception' => $e->getMessage()]);

            throw $e;
        }
    }
}
