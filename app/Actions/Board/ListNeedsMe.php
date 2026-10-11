<?php

namespace App\Actions\Board;

use App\Models\Story;
use App\Services\StoryStatusOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The overview's Needs me (RD-5): every story of an enabled project, on its ref,
 * that waits on the owner — a mockup set awaiting a pick, a draft awaiting approval,
 * an approved story ready to build — as one list, oldest first. The three
 * predicates are the ones SB-3's ListWhatNeedsMe used; it now calls them from here,
 * so both pages agree on what counts. Reads the stored snapshot only, never git.
 */
class ListNeedsMe
{
    /** A mockup set awaiting the owner's pick. */
    public const PICK = 'pick';

    /** A draft story awaiting approval. */
    public const DRAFT = 'draft';

    /** An approved story ready to build. */
    public const READY = 'ready';

    /**
     * The rows, oldest first by the date the story was asked for (undated last),
     * each listed once with the step it waits on, and how many of each kind.
     * A story awaiting a pick is also a draft or approved; the pick is what blocks
     * it, so it is listed once, as a pick.
     *
     * @return array{rows: list<array{kind: string, story: Story}>, counts: array{pick: int, draft: int, ready: int}}
     */
    public function handle(): array
    {
        $rows = [];
        // Pick first, so a story that is also a draft or ready keeps the pick.
        foreach ([self::PICK => 'awaitingPick', self::DRAFT => 'toApprove', self::READY => 'readyToBuild'] as $kind => $predicate) {
            foreach (self::$predicate($this->base())->get() as $story) {
                $rows[$story->id] ??= ['kind' => $kind, 'story' => $story];
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$a['story']->dated_on === null, $a['story']->dated_on, $a['story']->project->name, $a['story']->path]
            <=> [$b['story']->dated_on === null, $b['story']->dated_on, $b['story']->project->name, $b['story']->path]);

        $counts = array_count_values(array_column($rows, 'kind'));

        return [
            'rows' => $rows,
            'counts' => ['pick' => $counts[self::PICK] ?? 0, 'draft' => $counts[self::DRAFT] ?? 0, 'ready' => $counts[self::READY] ?? 0],
        ];
    }

    /**
     * Narrow a story query to mockup sets awaiting a pick: open stories (draft or
     * approved) with mockup options and no chosen option. A built or cancelled
     * story with no parseable pick is history, not a decision.
     *
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public static function awaitingPick(Builder $query): Builder
    {
        // Raw SQL: the query builder has no JSON length/type predicates. Constant expressions, no input.
        return $query->whereIn('stories.status', StoryStatusOrder::OPEN)
            ->whereRaw("json_length(json_extract(stories.mockups, '$.options')) > 0")
            ->whereRaw("json_type(json_extract(stories.mockups, '$.chosen')) = 'NULL'");
    }

    /**
     * Narrow a story query to drafts awaiting approval. A parked draft (its
     * initiative is a draft group) is not asking for a decision yet.
     *
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public static function toApprove(Builder $query): Builder
    {
        return $query->where('stories.status', 'draft')->where('stories.is_parked', false);
    }

    /**
     * Narrow a story query to approved stories, ready to build.
     *
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public static function readyToBuild(Builder $query): Builder
    {
        return $query->where('stories.status', 'approved');
    }

    /**
     * Stories on the ref of enabled projects, with their project loaded for the row.
     *
     * @return Builder<Story>
     */
    private function base(): Builder
    {
        return Story::query()
            ->select('stories.*')
            ->join('projects', 'projects.id', '=', 'stories.project_id')
            ->where('projects.is_enabled', true)
            // Off-main versions (SB-5) are not the story yet; the ref's row is.
            ->whereNull('stories.location_kind')
            ->with('project');
    }
}
