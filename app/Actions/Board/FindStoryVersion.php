<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Collection;

/**
 * Which version of a story to show, and how to describe the others (SB-5). One
 * place for the rule, read by the story page (SB-4) and the story modal (SB-8),
 * so the two never disagree about which copy of a story is "the" story.
 */
class FindStoryVersion
{
    /** Where off-main versions are looked for first when the ref has no such story. */
    private const KIND_ORDER = [Story::KIND_BRANCH, Story::KIND_WORKTREE, Story::KIND_UNTRACKED];

    /**
     * The row to show: the requested off-main version, else the ref's row, else
     * the story's first off-main version (a story that exists only off main).
     * Null when there is no such story or version; the caller logs and refuses.
     */
    public function handle(Project $project, string $storyId, ?int $version = null): ?Story
    {
        $query = Story::where('project_id', $project->id)->where('story_id', $storyId);

        if ($version !== null) {
            return (clone $query)->offMain()->find($version);
        }

        return (clone $query)->onRef()->first()
            ?? (clone $query)->offMain()->get()->sortBy(fn (Story $s) => array_search($s->location_kind, self::KIND_ORDER, true))->first();
    }

    /**
     * Every version of the story in the project: the ref's row and each off-main one.
     *
     * @return Collection<int, Story>
     */
    public function all(Project $project, string $storyId): Collection
    {
        return Story::where('project_id', $project->id)->where('story_id', $storyId)->get()->toBase();
    }

    /**
     * One line for every version other than the one shown: what each says
     * differently from main, and where it lives ("Picked D on branch x — not on main").
     *
     * @param  Collection<int, Story>  $all  from all()
     * @return list<array{id: int, text: string, onRef: bool}>
     */
    public function others(Collection $all, Story $shown): array
    {
        $onRef = $all->first(fn (Story $s) => $s->location_kind === null);
        $lines = [];
        foreach ($all as $version) {
            if ($version->id === $shown->id) {
                continue;
            }
            if ($version->location_kind === null) {
                $lines[] = ['id' => $version->id, 'text' => 'The version on main: '.($version->status ?? 'no status'), 'onRef' => true];

                continue;
            }

            $chosen = $version->mockups['chosen'] ?? null;
            $what = match (true) {
                $onRef === null => 'Exists only',
                $chosen !== null && $chosen !== ($onRef->mockups['chosen'] ?? null) => 'Picked '.strtoupper($chosen),
                $version->status !== $onRef->status => ucfirst((string) $version->status),
                default => 'Changed',
            };
            $lines[] = ['id' => $version->id, 'text' => "{$what} {$version->placePhrase()} — not on main", 'onRef' => false];
        }

        return $lines;
    }
}
