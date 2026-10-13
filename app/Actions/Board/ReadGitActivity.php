<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A project's git activity for its overview (RD-12, option B): commits per day over the
 * last 14 days, the latest commits with their +/− line counts and story IDs, the code
 * files most edited today, and the working copy's uncommitted-path count. Four reads,
 * each through a fixed read-only GitReader form and each on its own, so one failing read
 * empties only its own tile. Writes nothing.
 */
class ReadGitActivity
{
    /** The commits-per-day window, in days, today included. */
    public const DAYS = 14;

    /** How many commits Last edits lists (option B shows seven). */
    public const EDITS = 7;

    /** How many files the most-edited list shows. */
    public const TOP_FILES = 6;

    /**
     * How long a read is kept: the same cadence as Shipped this week's git read. The ref only
     * moves on a refresh, which changes the stored sha the keys carry, so a re-render between
     * refreshes never needs git; the working copy can change any time and is at most this stale.
     */
    public const CACHE_SECONDS = 300;

    /** Paths that are not code: docs and stories are the kit's own churn, not the project's edits. */
    public const NOT_CODE = ['docs/', 'stories/'];

    /**
     * A commit subject as CLAUDE.md step 6 writes it: `<type>(<ID>): <subject>`, the ID optional
     * and `!` allowed for a breaking change. Anything else is shown whole.
     */
    private const SUBJECT = '/^([a-z]+)(?:\(([^)]*)\))?!?:\s*(.+)$/D';

    /** A story ID anywhere in a subject or its scope (CLAUDE.md's ID rule, Story::ID_ROUTE). */
    private const STORY_ID = '/(?<![A-Za-z0-9-])('.Story::ID_ROUTE.')(?![A-Za-z0-9-])/';

    /**
     * @param  GitReader  $git  the only way the board touches a project
     */
    public function __construct(private GitReader $git) {}

    /**
     * The four activity reads for `$project`. Each is null when its git read failed — that
     * tile's error state — and the others still come back.
     *
     * Side effects: runs at most four read-only git commands per project per snapshot
     * (cached CACHE_SECONDS; the date-windowed reads also per day); logs
     * `board.git_activity_failed` (warning: project, read, error) for each failed read.
     * A refused form logs GitReader's own `board.git_refused`.
     *
     * @return array{
     *     commits: array{days: list<array{day: CarbonImmutable, count: int}>, total: int, today: int}|null,
     *     edits: list<array{sha: string, short: string, at: CarbonImmutable, type: string|null, subject: string, story_id: string|null, linked: bool, added: int|null, removed: int|null}>|null,
     *     files: list<array{path: string, commits: int}>|null,
     *     uncommitted: int|null,
     * }
     */
    public function handle(Project $project): array
    {
        $timezone = (string) config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        // Every cached value is scalars only: config/cache.php's serializable_classes is false, so a
        // Carbon put in the database store would come back an incomplete class. Dates are rebuilt here.
        $perDay = $this->read($project, 'commits', $today, fn () => $this->git->commitsPerDay($project->path, $project->ref, self::DAYS, $timezone));
        $stats = $this->read($project, 'edits', null, fn () => array_map(
            fn (array $c) => [...$c, 'at' => $c['at']->getTimestamp()],
            $this->git->commitStats($project->path, $project->ref, self::EDITS),
        ));
        $files = $this->read($project, 'files', $today, fn () => $this->git->filesChangedSince($project->path, $project->ref, $today));

        return [
            'commits' => $perDay === null ? null : $this->commits($perDay, $timezone),
            'edits' => $stats === null ? null : $this->edits($project, $stats),
            'files' => $files === null ? null : $this->topCode($files),
            'uncommitted' => $this->read($project, 'uncommitted', null, fn () => $this->git->uncommittedCount($project->path)),
        ];
    }

    /**
     * The chart's days, oldest first, with the window's total and today's count.
     *
     * @param  array<string, int>  $perDay  `Y-m-d` => commits
     * @return array{days: list<array{day: CarbonImmutable, count: int}>, total: int, today: int}
     */
    private function commits(array $perDay, string $timezone): array
    {
        $days = [];
        foreach ($perDay as $day => $count) {
            $days[] = ['day' => CarbonImmutable::parse((string) $day, $timezone), 'count' => $count];
        }

        return ['days' => $days, 'total' => array_sum($perDay), 'today' => $days === [] ? 0 : $days[count($days) - 1]['count']];
    }

    /**
     * The most-edited code files: the first TOP_FILES of GitReader's ranking outside NOT_CODE.
     *
     * @param  array<string, int>  $ranked  path => commits, most first
     * @return list<array{path: string, commits: int}>
     */
    private function topCode(array $ranked): array
    {
        $files = [];
        foreach ($ranked as $path => $commits) {
            if ($this->isCode((string) $path)) {
                $files[] = ['path' => (string) $path, 'commits' => $commits];
            }
            if (count($files) === self::TOP_FILES) {
                break;
            }
        }

        return $files;
    }

    /**
     * The latest commits, each subject split into its type, story ID and text, linked when
     * the ID is a story in this project's snapshot (only those have a page to open).
     *
     * @param  list<array{sha: string, at: int, subject: string, added: int|null, removed: int|null}>  $stats
     * @return list<array{sha: string, short: string, at: CarbonImmutable, type: string|null, subject: string, story_id: string|null, linked: bool, added: int|null, removed: int|null}>
     */
    private function edits(Project $project, array $stats): array
    {
        $edits = [];
        foreach ($stats as $commit) {
            $type = null;
            $text = $commit['subject'];
            if (preg_match(self::SUBJECT, $commit['subject'], $m) === 1) {
                [$type, $text] = [$m[1], $m[3]];
            }
            preg_match(self::STORY_ID, $commit['subject'], $id);
            $edits[] = [
                'sha' => $commit['sha'],
                'short' => substr($commit['sha'], 0, 7),
                'at' => CarbonImmutable::createFromTimestamp($commit['at']),
                'type' => $type,
                'subject' => $text,
                'story_id' => $id[1] ?? null,
                'linked' => false,
                'added' => $commit['added'],
                'removed' => $commit['removed'],
            ];
        }

        $known = Story::onRef()->where('project_id', $project->id)
            ->whereIn('story_id', array_filter(array_column($edits, 'story_id')))
            ->pluck('story_id')->all();

        return array_map(fn (array $e) => [...$e, 'linked' => in_array($e['story_id'], $known, true)], $edits);
    }

    /**
     * Whether `$path` is code: anything outside docs/ and stories/.
     */
    private function isCode(string $path): bool
    {
        foreach (self::NOT_CODE as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One read, cached per project, ref, stored sha (and day, for a date-windowed read). A
     * failed read is not cached, so the next render tries git again, and is logged once per try.
     *
     * @template T
     *
     * @param  Closure(): T  $source
     * @return T|null
     */
    private function read(Project $project, string $read, ?CarbonImmutable $day, Closure $source): mixed
    {
        $key = 'board.git_activity:'.$project->id.':'.$read.':'.sha1($project->path.'|'.$project->ref.'|'.$project->sha.'|'.$day?->toDateString());

        try {
            return Cache::remember($key, self::CACHE_SECONDS, $source);
        } catch (GitReaderException $e) {
            Log::warning('board.git_activity_failed', ['project' => $project->name, 'read' => $read, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
