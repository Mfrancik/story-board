<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use Illuminate\View\View;

/**
 * The bare project list that proves the snapshot data (SB-2); SB-3 replaces it.
 */
class BoardController extends Controller
{
    /**
     * List enabled projects with their story counts, and queue a refresh for
     * any whose snapshot is stale. The refresh runs after the response is sent,
     * so the page shows the last snapshot immediately instead of waiting on git.
     */
    public function __invoke(): View
    {
        $projects = Project::enabled()->withCount('stories')->orderBy('name')->get();

        $projects->filter(fn (Project $p) => $p->needsRefresh())
            ->each(fn (Project $p) => RefreshProjectJob::dispatchAfterResponse($p));

        return view('board.index', ['projects' => $projects]);
    }
}
