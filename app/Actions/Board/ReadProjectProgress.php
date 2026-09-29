<?php

namespace App\Actions\Board;

use App\Models\Story;

/**
 * One project's depth for its dashboard (SB-10): how each initiative is
 * progressing on the ref, and how many versions live off main by where they
 * live. Two grouped queries whatever the number of initiatives. Reads the
 * stored snapshot only — never git.
 */
class ReadProjectProgress
{
    /** The statuses that count as open work, the order Progress by initiative sorts on. */
    public const OPEN = ['draft', 'approved'];

    public function __construct(private ListWhatNeedsMe $list) {}

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

        $initiatives = $rows->groupBy(fn ($row) => (string) $row->initiative)->map(function ($group) {
            // Summed, not keyed: a null status and a literal "(none)" are two SQL rows
            // with one key, and mapWithKeys would drop one of them.
            $counts = $group->groupBy('status')->map(fn ($rows) => $rows->sum(fn ($row) => (int) $row->n))->all();

            return [
                'name' => $group->first()->initiative,
                'counts' => $this->list->ordered($counts),
                'total' => array_sum($counts),
                'built' => $counts['built'] ?? 0,
                'open' => array_sum(array_intersect_key($counts, array_flip(self::OPEN))),
                'parked' => $group->contains(fn ($row) => (bool) $row->parked),
            ];
        })->values()->all();

        // Most open work first; a tie reads A–Z, with the unnamed row after the named ones.
        usort($initiatives, fn ($a, $b) => [$b['open'], $a['name'] === null, $a['name']] <=> [$a['open'], $b['name'] === null, $b['name']]);

        return $initiatives;
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
