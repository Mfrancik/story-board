<?php

namespace App\Jobs;

use App\Actions\Board\RefreshProject;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one project outside the request that noticed it was stale, so a
 * page load never waits on `git fetch`.
 */
class RefreshProjectJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Project  $project  the project whose snapshot is out of date
     */
    public function __construct(public Project $project) {}

    /**
     * Run the refresh.
     *
     * RefreshProject logs every expected failure itself; an unexpected one (a DB
     * error) is logged here and rethrown. Logged in handle() rather than failed()
     * because the page-load path runs the job after the response, not on a queue,
     * where failed() is never called.
     */
    public function handle(RefreshProject $refresh): void
    {
        try {
            $refresh->handle($this->project);
        } catch (Throwable $e) {
            Log::error('board.refresh_crashed', ['project' => $this->project->name, 'exception' => $e->getMessage()]);

            throw $e;
        }
    }
}
