<?php

namespace App\Actions\Board;

use App\Models\Story;
use App\Services\StoryStatusOrder;

/**
 * One project's depth for its dashboard (SB-10): how each initiative is
 * progressing on the ref, and how many versions live off main by where they
 * live. Two grouped queries whatever the number of initiatives. Reads the
 * stored snapshot only — never git.
 */
class ReadProjectProgress
{
    /**
     * The initiative rollup and the off-main counts for one project.
     *
     * @return array{
     *     initiatives: list<array{name: string|null, counts: array<string, int>, total: int, built: int, open: int, parked: bool}>,
     *     offmain: array<string, int>
     * }
     */
    public function handle(int $projectId): array
    {
        return [
            'initiatives' => $this->initiatives($projectId),
            'offmain' => $this->offMain($projectId),
        ];
    }

    /**
     * One row per initiative on the ref, most open work first. A story with no
     * initiative (it sits directly in `stories/`) gets a row of its own, named null,
     * so its work is not dropped from the page.
     *
     * @return list<array{name: string|null, counts: array<string, int>, total: int, built: int, open: int, parked: bool}>
     */
    private function initiatives(int $projectId): array
    {
        // One grouped query for every initiative, so the page's query count does not
        // grow with them. Raw select: coalesce and max() have no builder form. The
        // `(none)` label matches ListWhatNeedsMe's tiles, so a missing status is seen
        // (danger tone), not dropped. `is_parked` is the same for every story in an
        // initiative (RefreshProject sets it from the README), so max() is that value.
        // toBase(): the rows are aggregates, not stories, so they come back as plain objects.
        $rows = Story::onRef()->where('project_id', $projectId)->toBase()
            ->selectRaw('initiative, coalesce(status, ?) as status, count(*) as n, max(is_parked) as parked', ['(none)'])
            ->groupBy('initiative', 'status')
            ->get();

        // StoryStatusOrder owns the counting and the order, so the Stories page, which counts its
        // rows in PHP from the list it already holds, agrees with this one without a second query.
        return StoryStatusOrder::rollup($rows);
    }

    /**
     * Versions not on the ref, counted by location kind (branch, worktree, untracked).
     *
     * @return array<string, int>
     */
    private function offMain(int $projectId): array
    {
        return Story::offMain()->where('project_id', $projectId)
            ->selectRaw('location_kind, count(*) as n')
            ->groupBy('location_kind')
            ->pluck('n', 'location_kind')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
