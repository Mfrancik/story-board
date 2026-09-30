<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use App\Services\StoryPickWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every story's mockup set across the enabled projects (SB-21): which story it
 * belongs to, its options, and whether it is awaiting a pick or picked (with the
 * reason quoted from the story's `## Design mockup gate`). The gallery, the
 * viewer, the sidebar count and the app map (SB-24) all read sets from here.
 *
 * A set is an on-ref story row whose snapshot mockup directory is exactly
 * `docs/mockups/<ID>` with at least one option. Rows come from the snapshot on
 * every call (two queries); what each story's gate says is read from git at the
 * snapshot's commit in one batch and cached per commit, so the sidebar's count
 * costs no git on most page loads.
 *
 * @phpstan-type MockupSet array{
 *     project: string, story: string, row: int, title: string|null, status: string|null, path: string,
 *     sha: string, dir: string, options: list<string>, state: 'awaiting'|'picked'|'other',
 *     picked: string|null, reason: string|null, recorded: string|null, pushed: bool,
 *     where: string|null, dated_on: string|null
 * }
 */
class ReadMockupSets
{
    /** No option picked yet, and the story's gate still holds the placeholder — the board can pick it. */
    public const AWAITING = 'awaiting';

    /** A letter is recorded: by the kit parser at the ref, or by a board pick not pushed yet. */
    public const PICKED = 'picked';

    /** Anything else: a choice the parser could not read, a closed story, a set with no story file. */
    public const OTHER = 'other';

    /** How long a local-branch pick check is kept; also how soon a pick committed outside the board shows. */
    private const LOCAL_SECONDS = 300;

    /** How long gate readings at a commit are kept. A commit never changes, so this only bounds the cache's size. */
    private const GATE_SECONDS = 86400;

    /**
     * @param  GitReader  $git  story text at the ref, and the checkout's own branch
     * @param  ReadMockupGate  $gate  the gate's words, quoted
     * @param  StoryPickWriter  $picks  the one rule for "can the board pick this" (pure methods only)
     */
    public function __construct(
        private readonly GitReader $git,
        private readonly ReadMockupGate $gate,
        private readonly StoryPickWriter $picks,
    ) {}

    /**
     * Every set of every enabled project (or of `$only`), projects A–Z, and in
     * each project the sets awaiting a pick first, then the newest.
     *
     * Two queries whatever the number of projects or sets. Git is read only for
     * story files not yet read at their commit, and for the local-branch check
     * once per LOCAL_SECONDS (logs `board.mockup_sets_unreadable` when git fails).
     *
     * @return list<MockupSet>
     */
    public function handle(?Project $only = null): array
    {
        $projects = $only !== null
            ? ($only->is_enabled ? collect([$only]) : collect())
            : Project::enabled()->orderBy('name')->get();
        if ($projects->isEmpty()) {
            return [];
        }

        $rows = $this->candidates(array_values($projects->map(fn (Project $p) => $p->id)->all()))->groupBy('project_id');
        $sets = [];
        foreach ($projects as $project) {
            array_push($sets, ...$this->build($project, $rows->get($project->id, new Collection)));
        }

        return $sets;
    }

    /**
     * One project's set for `$storyId`, or null when it has none (or the project is off the board).
     *
     * @return MockupSet|null
     */
    public function find(Project $project, string $storyId): ?array
    {
        foreach ($this->handle($project) as $set) {
            if ($set['story'] === $storyId) {
                return $set;
            }
        }

        return null;
    }

    /**
     * How many sets across enabled projects await a pick — the sidebar's badge.
     */
    public function awaitingCount(): int
    {
        return count(array_filter($this->handle(), fn (array $set) => $set['state'] === self::AWAITING));
    }

    /**
     * How many sets a project has, from the snapshot alone (one query, no git):
     * enough to decide whether its page shows a Mockups button.
     */
    public function countFor(Project $project): int
    {
        return $this->candidates([$project->id])->count();
    }

    /**
     * What the viewer needs beyond the list: the one-line description (the set's
     * `index.html` meta description, else the story's `## Story` line) and every
     * file in the set's directory at the ref, relative to it.
     *
     * Side effects: two git reads.
     *
     * @param  MockupSet  $set
     * @return array{description: string|null, files: list<string>}
     */
    public function describe(Project $project, array $set): array
    {
        try {
            $prefix = $set['dir'].'/';
            $files = array_map(fn (string $f) => substr($f, strlen($prefix)), $this->git->listFiles($project->path, $set['sha'], $prefix));
            $read = $this->git->showMany($project->path, $set['sha'], array_values(array_filter([
                in_array('index.html', $files, true) ? $prefix.'index.html' : null, $set['path'],
            ])));
        } catch (GitReaderException $e) {
            Log::warning('board.mockup_sets_unreadable', ['project' => $project->name, 'story' => $set['story'], 'error' => $e->getMessage()]);

            return ['description' => null, 'files' => []];
        }

        $index = $read[$prefix.'index.html'] ?? null;
        $description = $index !== null ? $this->metaDescription($index) : null;

        return [
            'description' => $description ?? $this->storyLine($read[$set['path']] ?? ''),
            'files' => $files,
        ];
    }

    /**
     * Drop a project's cached local-branch check, so a pick the board just committed shows at once.
     */
    public function forget(Project $project): void
    {
        Cache::forget("board:mockup-local:{$project->id}");
    }

    /**
     * Classify one project's sets from its candidate rows.
     *
     * @param  Collection<int, Story>  $rows
     * @return list<MockupSet>
     */
    private function build(Project $project, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $readings = $this->readings($project, $rows->reject(fn (Story $row) => $row->isMockupOnly()));

        $sets = [];
        foreach ($rows as $row) {
            $reading = $readings[$row->sha][$row->path] ?? null;
            $letter = $row->mockups['chosen'] ?? null;
            $awaiting = $letter === null && $reading !== null && $reading['pickable']
                && in_array($row->status, StoryPickWriter::OPEN_STATUSES, true);

            $sets[] = [
                'project' => $project->name,
                'story' => (string) $row->story_id,
                'row' => $row->id,
                'title' => $row->title,
                'status' => $row->isMockupOnly() ? null : $row->status,
                'path' => $row->path,
                'sha' => $row->sha,
                'dir' => (string) $row->mockups['dir'],
                'options' => $row->mockups['options'],
                'state' => $letter !== null ? self::PICKED : ($awaiting ? self::AWAITING : self::OTHER),
                'picked' => $letter,
                'reason' => $letter !== null ? $reading['why'] ?? null : null,
                // ADR-009: a choice the kit parser could not read is quoted as written, never turned into a letter.
                'recorded' => $letter === null && ! $awaiting ? $reading['chosen'] ?? null : null,
                'pushed' => true,
                'where' => $reading['where'] ?? null,
                'dated_on' => $row->dated_on?->toDateString(),
            ];
        }

        $sets = $this->withLocalPicks($project, $sets);
        // Awaiting first, then newest by the story's date, then the higher ID (SB-21 before SB-3).
        usort($sets, fn (array $a, array $b) => [$a['state'] !== self::AWAITING, $b['dated_on'] ?? '', 0]
            <=> [$b['state'] !== self::AWAITING, $a['dated_on'] ?? '', strnatcmp($a['story'], $b['story'])]);

        return $sets;
    }

    /**
     * A pick the board committed lives on the checkout's branch until the owner
     * pushes it, so the ref still reads "awaiting". Re-read just the awaiting
     * sets' stories on that branch and mark those that carry a letter now, as
     * picked but not pushed. Cached per project for LOCAL_SECONDS and dropped by
     * forget() after a board pick.
     *
     * @param  list<MockupSet>  $sets
     * @return list<MockupSet>
     */
    private function withLocalPicks(Project $project, array $sets): array
    {
        $awaiting = array_filter($sets, fn (array $set) => $set['state'] === self::AWAITING);
        if ($awaiting === []) {
            return $sets;
        }

        $paths = array_values(array_map(fn (array $set) => $set['path'], $awaiting));
        $key = "board:mockup-local:{$project->id}";
        $cached = Cache::get($key);
        // The cached check answers only the question it was asked: the same awaiting stories at the same ref.
        $fingerprint = md5($project->sha.'|'.implode("\n", $paths));
        if (! is_array($cached) || ($cached['fingerprint'] ?? null) !== $fingerprint) {
            $cached = ['fingerprint' => $fingerprint, 'picks' => $this->readLocalPicks($project, $paths)];
            Cache::put($key, $cached, self::LOCAL_SECONDS);
        }

        foreach ($awaiting as $i => $set) {
            $pick = $cached['picks'][$set['path']] ?? null;
            if ($pick !== null && in_array($pick['letter'], $set['options'], true)) {
                $sets[$i] = [...$set, 'state' => self::PICKED, 'picked' => $pick['letter'], 'reason' => $pick['why'], 'pushed' => false];
            }
        }

        return $sets;
    }

    /**
     * The letter and reason each story carries on the checkout's branch, for the
     * stories that have one there.
     *
     * @param  list<string>  $paths
     * @return array<string, array{letter: string, why: string|null}>
     */
    private function readLocalPicks(Project $project, array $paths): array
    {
        try {
            $branch = $this->picks->boardBranch($project);
            if ($branch === $project->ref) {
                // The board reads a local branch already: the ref's answer is the checkout's.
                return [];
            }
            $local = $this->git->showMany($project->path, $branch, $paths);
        } catch (GitReaderException $e) {
            // No local branch of that name, or no checkout: the ref's state stands.
            Log::info('board.mockup_sets_unreadable', ['project' => $project->name, 'ref' => 'local branch', 'error' => $e->getMessage()]);

            return [];
        }

        $picks = [];
        foreach ($local as $path => $markdown) {
            $letter = $markdown !== null ? $this->picks->pickedLetter($markdown) : null;
            if ($letter !== null) {
                $picks[$path] = ['letter' => $letter, 'why' => $this->gate->handle((string) $markdown)['why']];
            }
        }

        return $picks;
    }

    /**
     * On-ref rows of `$projectIds` whose snapshot mockup directory is the story's
     * own folder with at least one option — one query.
     *
     * @param  list<int>  $projectIds
     * @return Collection<int, Story>
     */
    private function candidates(array $projectIds): Collection
    {
        return Story::onRef()->whereIn('project_id', $projectIds)
            // Raw SQL: the query builder has no JSON predicates. Constant expressions, no input. The
            // directory must be the story's own folder, the same rule both file routes enforce.
            ->whereRaw("json_unquote(json_extract(mockups, '$.dir')) = concat('docs/mockups/', story_id)")
            ->whereRaw("json_length(json_extract(mockups, '$.options')) > 0")
            ->get(['id', 'project_id', 'story_id', 'title', 'status', 'path', 'dated_on', 'mockups', 'sha'])
            ->filter(fn (Story $row) => $row->hasPage())
            ->values();
    }

    /**
     * What each story's gate says, as sha => path => reading. Readings at a
     * commit never change, so they are cached per project and commit, and git is
     * asked only for paths not read before — one batch per commit.
     *
     * @param  Collection<int, Story>  $rows
     * @return array<string, array<string, array{pickable: bool, chosen: string|null, why: string|null, where: string|null}>>
     */
    private function readings(Project $project, Collection $rows): array
    {
        $readings = [];
        foreach ($rows->groupBy('sha') as $sha => $group) {
            $key = "board:mockup-gates:{$project->id}:{$sha}";
            /** @var array<string, array{pickable: bool, chosen: string|null, why: string|null, where: string|null}> $known */
            $known = Cache::get($key, []);
            $missing = array_values(array_diff($group->pluck('path')->unique()->all(), array_keys($known)));
            if ($missing !== []) {
                try {
                    foreach ($this->git->showMany($project->path, (string) $sha, $missing) as $path => $markdown) {
                        if ($markdown !== null) {
                            $gate = $this->gate->handle($markdown);
                            $known[$path] = [
                                'pickable' => $this->picks->refusalFor($markdown) === null,
                                'chosen' => $gate['chosen'],
                                'why' => $gate['why'],
                                'where' => self::where($markdown),
                            ];
                        }
                    }
                    Cache::put($key, $known, self::GATE_SECONDS);
                } catch (GitReaderException $e) {
                    // Sets still list (from the snapshot); they lose the reason and Where until git reads again.
                    Log::warning('board.mockup_sets_unreadable', ['project' => $project->name, 'ref' => $sha, 'error' => $e->getMessage()]);
                }
            }
            $readings[$sha] = $known;
        }

        return $readings;
    }

    /**
     * The route or page the story names: the first path in its `- Routes:` line
     * (`GET /mockups` → `/mockups`), or null when it names none. Public so the
     * app map (SB-24) reads a step's route by the same rule the gallery's Where uses.
     */
    public static function where(string $markdown): ?string
    {
        if (! preg_match('/^[ \t]*-[ \t]+Routes[^:\n]*:(.*)$/mi', $markdown, $line)) {
            return null;
        }
        preg_match_all('/`([^`]+)`/', $line[1], $codes);
        foreach ($codes[1] as $code) {
            if (preg_match('#^(?:(?:GET|POST|PUT|PATCH|DELETE)\s+)?(/\S*)$#', trim($code), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * An `index.html`'s `<meta name="description">` content, or null.
     */
    private function metaDescription(string $html): ?string
    {
        if (! preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
            return null;
        }
        $text = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));

        return $text === '' ? null : $text;
    }

    /**
     * The first paragraph of the story's `## Story` section, on one line, or null.
     */
    private function storyLine(string $markdown): ?string
    {
        if (! preg_match('/^##\s+Story\s*\n+(.+?)(?:\n\s*\n|\n##|\z)/ms', $markdown, $m)) {
            return null;
        }

        return trim((string) preg_replace('/\s+/', ' ', $m[1])) ?: null;
    }
}
