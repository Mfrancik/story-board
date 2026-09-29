<?php

namespace App\Jobs;

use App\Actions\Board\RefreshProject;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
     */
    public function handle(RefreshProject $refresh): void
    {
        $refresh->handle($this->project);
    }
}
