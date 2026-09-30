<?php

namespace App\View\Components\Board;

use App\Actions\Board\ListLiveSessions;
use App\Actions\Board\ReadMockupSets;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

/**
 * The board's project switcher (SB-7, design A): every enabled project with its
 * snapshot state and on-ref story count, "All projects", and the Manage projects
 * slot once SB-12 registers its page, and (SB-11) a live badge beside each project
 * with a Claude Code session active now; (SB-18) a Production link counting the
 * projects connected to production; (SB-21) a Mockups link counting the sets
 * awaiting a pick. A drawer below 768px; all toggling is Alpine.
 */
class Sidebar extends Component
{
    /**
     * Build the sidebar's data: one query for the projects and their counts.
     *
     * The current entry is read from the route rather than passed by each page:
     * the layout renders once per full page load (wire:navigate included), and
     * every page under `/p/{project}` names its project in the same parameter.
     */
    public function render(): View
    {
        $projects = Project::enabled()
            ->withCount(['stories' => fn ($q) => $q->onRef()])
            ->orderBy('name')
            ->get(['id', 'name', 'state']);

        $param = request()->route()?->parameter('project');

        return view('components.board.sidebar', [
            'projects' => $projects,
            'total' => (int) $projects->sum('stories_count'),
            'current' => $param instanceof Project ? $param->name : (is_string($param) ? $param : null),
            'onHome' => request()->routeIs('home'),
            // SB-12 ships the page; until its route exists the slot stays hidden.
            'manageUrl' => Route::has('projects.manage') ? route('projects.manage') : null,
            'onManage' => request()->routeIs('projects.manage'),
            // SB-18: Production, with how many of the shown projects have a connection ("3/4").
            'prodUrl' => Route::has('prod') ? route('prod') : null,
            'onProd' => request()->routeIs('prod'),
            'prodConnected' => Project::enabled()->has('prodConnection')->count(),
            // SB-21: Mockups, with how many sets await a pick (cached per project snapshot, so no git on most loads).
            'onMockups' => request()->routeIs('mockups', 'mockups.show'),
            'awaitingMockups' => app(ReadMockupSets::class)->awaitingCount(),
            // Live sessions per project (SB-11), from the same 20 s cached scan as the Live now panel.
            'live' => app(ListLiveSessions::class)->counts(),
        ]);
    }
}
