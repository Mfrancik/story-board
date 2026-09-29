<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;

/**
 * A project's on-ref stories grouped by initiative, for the Stories page (SB-15):
 * each initiative with its full counts, in Progress by initiative's order, and its
 * stories in natural ID order, each tagged with a display kind. One query whatever
 * the size of the project. Reads the stored snapshot only — never git.
 */
class ReadProjectStories
{
    /** The tag kinds, in filter-chip order. Every status maps to exactly one. */
    public const KINDS = ['built', 'approved', 'draft', 'cancelled', 'other'];

    public function __construct(private ReadProjectProgress $progress) {}

    /**
     * The tag kind of a raw status. The kit's four statuses are their own kind; any
     * other value, or none, is `other` — nothing is guessed, so rent-track's `done`
     * is not Built.
     */
    public static function kind(?string $status): string
    {
        return in_array($status, ['built', 'approved', 'draft', 'cancelled'], true) ? $status : 'other';
    }

    /**
     * Every on-ref story of the project, grouped.
     *
     * @return array{
     *     stories: int,
     *     tallies: array<string, int>,
     *     initiatives: list<array{
     *         key: string, name: string|null, counts: array<string, int>, total: int, built: int, open: int, parked: bool,
     *         kinds: array<string, int>,
     *         stories: list<array{id: string, story_id: string|null, title: string, status: string|null, kind: string, link: string|null}>
     *     }>
     * }
     */
    public function handle(Project $project): array
    {
        // The one query: only the columns a row shows. Grouping, counting and ordering
        // happen in PHP below, so 946 stories in 57 initiatives are still one query.
        $stories = Story::onRef()->where('project_id', $project->id)
            ->get(['id', 'story_id', 'title', 'status', 'initiative', 'is_parked', 'path']);

        // toBase(): the groups hold stories, and the rows built from them are not models.
        $byInitiative = $stories->toBase()->groupBy(fn (Story $s) => (string) $s->initiative);

        // The same (initiative, status, n, parked) rows ReadProjectProgress's grouped query
        // returns, counted here instead, so its rollup — counts, open work, order — is reused as is.
        $rows = $byInitiative->flatMap(fn ($group) => $group->groupBy(fn (Story $s) => $s->status ?? '(none)')
            ->map(fn ($same, $status) => (object) [
                'initiative' => $same->first()->initiative,
                'status' => (string) $status,
                'n' => $same->count(),
                'parked' => $same->contains('is_parked', true),
            ])->values());

        $tallies = array_fill_keys(self::KINDS, 0);
        $initiatives = [];
        foreach ($this->progress->rollup($rows) as $i => $initiative) {
            $group = $byInitiative->get((string) $initiative['name'])
                // Natural order: BR-2 before BR-10, MT-2 before MT-2a. A row with no ID sorts by its path, last.
                ->sort(fn (Story $a, Story $b) => (($a->story_id === null) <=> ($b->story_id === null))
                    ?: strnatcmp((string) $a->story_id, (string) $b->story_id)
                    ?: strcmp($a->path, $b->path));

            $kinds = array_fill_keys(self::KINDS, 0);
            $list = [];
            foreach ($group as $story) {
                $kind = self::kind($story->status);
                $kinds[$kind]++;
                $list[] = [
                    'id' => (string) $story->id,
                    'story_id' => $story->story_id,
                    'title' => $story->title ?? $story->path,
                    'status' => $story->status,
                    'kind' => $kind,
                    // Only a well-formed ID can be addressed by `?story=` (SB-8), as on every other list.
                    'link' => $story->hasPage() ? $project->name.'/'.$story->story_id : null,
                ];
            }
            foreach ($kinds as $kind => $n) {
                $tallies[$kind] += $n;
            }

            // An index, not the name: an initiative's name is free text and "No initiative" has none.
            $initiatives[] = [...$initiative, 'key' => 'g'.$i, 'kinds' => $kinds, 'stories' => $list];
        }

        return ['stories' => $stories->count(), 'tallies' => $tallies, 'initiatives' => $initiatives];
    }
}
