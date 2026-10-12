<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;

/**
 * A project's story progress for its overview (RD-11): on-ref counts by the Stories
 * page's tag kind (ReadProjectStories::kind), so the overview and that page always
 * agree, and the initiatives with the most stories with how many of each are built.
 * One grouped query whatever the size of the project. Reads the stored snapshot
 * only — never git.
 */
class ReadStoryProgress
{
    /** How many initiatives the Biggest initiatives tile lists (option B shows six). */
    public const TOP_INITIATIVES = 6;

    /**
     * The project's on-ref counts by kind, in the Stories page's chip order, and its
     * biggest initiatives, most stories first (a tie reads A–Z, the unnamed one last).
     *
     * @return array{
     *     total: int,
     *     tallies: array<string, int>,
     *     initiatives: list<array{name: string|null, total: int, built: int}>,
     *     initiative_count: int,
     * }
     */
    public function handle(Project $project): array
    {
        // One row per (initiative, status): small whatever the project's size (coins: ~150 rows for 1,558 stories).
        $rows = Story::onRef()->where('project_id', $project->id)->toBase()
            ->selectRaw('initiative, status, count(*) as n')
            ->groupBy('initiative', 'status')
            ->get();

        $tallies = array_fill_keys(ReadProjectStories::KINDS, 0);
        $initiatives = [];
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $kind = ReadProjectStories::kind($row->status);
            $tallies[$kind] += $n;

            $key = (string) $row->initiative;
            $initiatives[$key] ??= ['name' => $row->initiative, 'total' => 0, 'built' => 0];
            $initiatives[$key]['total'] += $n;
            if ($kind === 'built') {
                $initiatives[$key]['built'] += $n;
            }
        }

        $initiatives = array_values($initiatives);
        usort($initiatives, fn (array $a, array $b) => [$b['total'], $a['name'] === null, $a['name']] <=> [$a['total'], $b['name'] === null, $b['name']]);

        return [
            'total' => array_sum($tallies),
            'tallies' => $tallies,
            'initiatives' => array_slice($initiatives, 0, self::TOP_INITIATIVES),
            'initiative_count' => count($initiatives),
        ];
    }
}
