<?php

namespace App\Http\Middleware;

use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refresh-on-load for the whole board (RD-2): on every board page load, each
 * enabled project whose snapshot is due (Project::needsRefresh()) gets one
 * RefreshProjectJob queued. It replaces the per-page triggers that lived in the
 * home and project pages, so every page — and every page the redesign adds —
 * keeps the board fresh without remembering to.
 *
 * Only page loads reach it: it sits on the page route group, not the file routes
 * a page's frames and images hit many times over, and Livewire's update requests
 * never run route middleware.
 */
class RefreshStaleProjects
{
    /**
     * Queue a refresh of every stale enabled project, then serve the page.
     *
     * Side effects: dispatches RefreshProjectJob (unique per project, so a burst of
     * loads still queues one) and logs `board.stale_refresh_dispatched` per project.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Queued, not run here: a page load must never wait on `git fetch`.
        Project::enabled()->get()
            ->filter(fn (Project $project) => $project->needsRefresh())
            ->each(function (Project $project) {
                RefreshProjectJob::dispatch($project);
                Log::info('board.stale_refresh_dispatched', ['project' => $project->name]);
            });

        return $next($request);
    }
}
