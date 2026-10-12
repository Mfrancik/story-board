<?php

namespace App\View\Components\Shell;

use App\Actions\Board\ReadMockupSets;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\Component;

/**
 * The command-center shell's sidebar (RD-2, Home A): the logo, Home, Mockups with
 * the count of sets awaiting a pick, then every enabled project with its snapshot
 * state dot and story ID prefix, each opening the project's Overview (RD-11). Below
 * 768 px it is a drawer behind a menu button; all toggling is Alpine.
 */
class Sidebar extends Component
{
    /**
     * Build the sidebar's data: the enabled projects, their ID prefixes (one grouped
     * query) and the awaiting-pick count.
     *
     * The current entry is read from the route rather than passed by each page: the
     * layout renders once per page load, and every page under `/p/{project}` names
     * its project in the same parameter.
     */
    public function render(): View
    {
        $projects = Project::enabled()->orderBy('name')->get(['id', 'name', 'state']);
        $param = request()->route()?->parameter('project');

        return view('components.shell.sidebar', [
            'projects' => $projects,
            'prefixes' => $this->prefixes($projects),
            'current' => $param instanceof Project ? $param->name : (is_string($param) ? $param : null),
            'onHome' => request()->routeIs('home'),
            'onMockups' => request()->routeIs('mockups', 'mockups.show'),
            // Cached per project snapshot by ReadMockupSets, so most loads run no git.
            'awaiting' => app(ReadMockupSets::class)->awaitingCount(),
        ]);
    }

    /**
     * Each project's story ID prefix — the one most of its on-ref stories use
     * ("SB" for story-board, though it also holds RD stories), ties broken
     * alphabetically. A project with no story IDs has none.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, string> prefix by project id
     */
    private function prefixes(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        // Raw expression: Eloquent has no "text before the first hyphen"; grouping in MySQL keeps it
        // one small result per project instead of every story ID of every project in PHP.
        $rows = Story::onRef()
            ->whereIn('project_id', $projects->modelKeys())
            ->whereNotNull('story_id')
            ->selectRaw("project_id, SUBSTRING_INDEX(story_id, '-', 1) as prefix, COUNT(*) as uses")
            ->groupBy('project_id', 'prefix')
            ->orderByDesc('uses')
            ->orderBy('prefix')
            ->toBase()
            ->get();

        $prefixes = [];
        foreach ($rows as $row) {
            // Rows come most-used first, so the first one seen per project is its prefix.
            $prefixes[(int) $row->project_id] ??= (string) $row->prefix;
        }

        return $prefixes;
    }
}
