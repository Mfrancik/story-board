<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use App\Services\ProjectFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shipped this week on the home overview (RD-7): every build commit — `feat`, `fix` or
 * `test` with a story ID, the commits CLAUDE.md step 6 says flip a story to built — on
 * each covered project's ref in the last 7 days, newest first, with the story's title
 * from the stored snapshot. `docs`, `chore` and `refactor` commits never mark a story
 * built, so they are not shipping. Reads git through GitReader::log only; writes nothing.
 */
class ListShippedThisWeek
{
    /** The window, in days. */
    public const DAYS = 7;

    /**
     * How long one project's read is kept. The overview polls every 30 s and re-renders
     * every panel; the ref only moves on a refresh, which changes the stored sha the
     * cache is keyed on, so a poll in between never needs git.
     */
    public const CACHE_SECONDS = 300;

    /** A build commit's subject: its type, then the story ID (CLAUDE.md's ID rule, Story::ID_ROUTE). */
    private const BUILD_COMMIT = '/^(?:feat|fix|test)\(('.Story::ID_ROUTE.')\):\s*(.*)$/D';

    /**
     * @param  GitReader  $git  the only way the board touches a project
     */
    public function __construct(private GitReader $git) {}

    /**
     * The build commits of the last 7 days across the covered projects, newest first,
     * and the projects whose history could not be read (the rest are still listed).
     *
     * Side effects: runs `git log` once per project per snapshot (cached
     * CACHE_SECONDS); logs `board.shipped_read` (info: project, count) for each read
     * and `board.shipped_unreadable` (warning: project, error) for a failed one.
     *
     * @param  ProjectFilter|null  $filter  the projects to cover (RD-6); null is every enabled project
     * @return array{
     *     rows: list<array{at: CarbonImmutable, story_id: string, title: string, project: string, linked: bool}>,
     *     failed: list<string>,
     * }
     */
    public function handle(?ProjectFilter $filter = null): array
    {
        $filter ??= ProjectFilter::all();

        $commits = [];
        $failed = [];
        foreach ($filter->projects()->orderBy('name')->get() as $project) {
            try {
                $read = $this->read($project);
            } catch (GitReaderException $e) {
                // One project's missing checkout or bad ref must not hide what the others shipped.
                Log::warning('board.shipped_unreadable', ['project' => $project->name, 'error' => $e->getMessage()]);
                $failed[] = $project->name;

                continue;
            }
            // A cached read can be up to CACHE_SECONDS old, so its window may reach that much past 7 days.
            foreach ($read as $commit) {
                $commits[] = [...$commit, 'project_id' => $project->id, 'project' => $project->name];
            }
        }

        // Newest first; a tie (one push of several commits) keeps a stable, readable order.
        usort($commits, fn (array $a, array $b) => [$b['at'], $a['project'], $a['story_id']] <=> [$a['at'], $b['project'], $b['story_id']]);

        $titles = $this->titles($commits);
        $rows = array_map(function (array $c) use ($titles) {
            $title = $titles[$c['project_id'].'/'.$c['story_id']] ?? null;

            return [
                'at' => CarbonImmutable::createFromTimestamp($c['at']),
                'story_id' => $c['story_id'],
                // A story not in the snapshot (renamed, or never written) shows its commit's subject instead.
                'title' => $title ?? $c['subject'],
                'project' => $c['project'],
                // Only a story the snapshot holds has a page to open.
                'linked' => $title !== null,
            ];
        }, $commits);

        return ['rows' => $rows, 'failed' => $failed];
    }

    /**
     * One project's build commits since 7 days before the read, cached per stored
     * snapshot so the 30 s poll does not re-run git.
     *
     * @return list<array{at: int, story_id: string, subject: string}>
     *
     * @throws GitReaderException when the checkout or its ref cannot be read.
     */
    private function read(Project $project): array
    {
        $key = 'board.shipped:'.$project->id.':'.sha1($project->ref.'|'.$project->sha);

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($project) {
            $commits = [];
            foreach ($this->git->log($project->path, $project->ref, CarbonImmutable::now()->subDays(self::DAYS)) as $commit) {
                if (preg_match(self::BUILD_COMMIT, $commit['subject'], $m) === 1) {
                    $commits[] = ['at' => $commit['at']->getTimestamp(), 'story_id' => $m[1], 'subject' => $m[2] !== '' ? $m[2] : $commit['subject']];
                }
            }
            Log::info('board.shipped_read', ['project' => $project->name, 'count' => count($commits)]);

            return $commits;
        });
    }

    /**
     * The snapshot title of each shipped story, keyed `<project id>/<ID>`, in one query.
     *
     * @param  list<array{project_id: int, story_id: string}>  $commits
     * @return array<string, string>
     */
    private function titles(array $commits): array
    {
        if ($commits === []) {
            return [];
        }

        $titles = [];
        $stories = Story::onRef()
            ->whereIn('project_id', array_unique(array_column($commits, 'project_id')))
            ->whereIn('story_id', array_unique(array_column($commits, 'story_id')))
            ->get(['project_id', 'story_id', 'title', 'path']);
        foreach ($stories as $story) {
            $titles[$story->project_id.'/'.$story->story_id] = $story->title ?? $story->path;
        }

        return $titles;
    }
}
