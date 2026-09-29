<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Story;
use App\Services\GitReader;
use App\Services\StoryParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finds stories and mockups that exist somewhere other than the project's ref
 * (SB-5): on unmerged branches, on a branch checked out in a worktree, or as
 * untracked files in any checkout. The ref stays the truth for status; these
 * rows are shown beside it, labelled with where they live.
 */
class IndexOffMain
{
    /** A story file, as story-index defines one: stories/**.md except README.md. */
    private const STORY_FILE = '#^stories/(?:.+/)?(?!README\.md$)[^/]+\.md$#D';

    /**
     * A file in a mockup directory, or below it: docs/mockups/<dir>/... Any directory
     * name, not only a well-formed ID — an oddly named folder is still work not on main.
     */
    private const MOCKUP_FILE = '#^docs/mockups/([^/]+)/(.+)$#D';

    /**
     * @param  GitReader  $git  the only way the board touches a project
     * @param  StoryParser  $parser  the kit's parser, for texts that are not at a ref
     */
    public function __construct(private GitReader $git, private StoryParser $parser) {}

    /** @var array<string, list<string>> remote names per repository, read once per run */
    private array $remotes = [];

    /**
     * Replace the project's off-main rows with what its branches and checkouts hold now.
     *
     * Side effects: replaces `stories` rows with a location and the project's
     * worktree `project_locations`; logs board.offmain_indexed.
     *
     * @param  string  $sha  the ref commit the snapshot was taken at
     * @param  list<array<string, mixed>>  $refRecords  the ref's story-index records
     * @return array{branch: int, worktree: int, untracked: int}
     *
     * @throws GitReaderException when git or the parser fails; the caller keeps the ref snapshot.
     */
    public function handle(Project $project, string $sha, array $refRecords): array
    {
        $refByPath = collect($refRecords)->keyBy('path')->all();
        $refById = collect($refRecords)->filter(fn ($r) => $r['id'] !== null)->keyBy('id')->all();
        $readme = $this->readme($project, $sha);

        $worktrees = $this->git->worktrees($project->path);
        $aliases = array_values($project->locations()->where('kind', ProjectLocation::KIND_ALIAS)->pluck('path')
            ->map(fn ($path): string => (string) $path)->all());

        $rows = [
            ...$this->branchRows($project, $sha, $worktrees, $aliases, $readme, $refByPath, $refById),
            ...$this->untrackedRows($project, $sha, $worktrees, $aliases, $readme, $refById),
        ];

        DB::transaction(function () use ($project, $rows, $worktrees) {
            $project->stories()->offMain()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('stories')->insert($chunk);
            }
            $project->locations()->where('kind', ProjectLocation::KIND_WORKTREE)->delete();
            $project->locations()->createMany(array_map(fn ($w) => [
                'kind' => ProjectLocation::KIND_WORKTREE, 'path' => $w['path'], 'branch' => $w['branch'],
            ], $worktrees));
        });

        $kinds = array_count_values(array_column($rows, 'location_kind'));
        $counts = [
            'branch' => $kinds[Story::KIND_BRANCH] ?? 0,
            'worktree' => $kinds[Story::KIND_WORKTREE] ?? 0,
            'untracked' => $kinds[Story::KIND_UNTRACKED] ?? 0,
        ];
        Log::info('board.offmain_indexed', ['project' => $project->name, ...$counts]);

        return $counts;
    }

    /**
     * Rows for every unmerged branch that changes a story's content (new file,
     * Status, Chosen option, or mockup options) or adds or changes mockups.
     *
     * @param  list<array{path: string, branch: string|null}>  $worktrees
     * @param  list<string>  $aliases
     * @param  array<string, array<string, mixed>>  $refByPath
     * @param  array<string, array<string, mixed>>  $refById
     * @return list<array<string, mixed>>
     */
    private function branchRows(Project $project, string $sha, array $worktrees, array $aliases, ?string $readme, array $refByPath, array $refById): array
    {
        $refBlobs = $this->git->storyBlobs($project->path, $sha);
        $checkedOut = [];
        foreach ($worktrees as $worktree) {
            if ($worktree['branch'] !== null) {
                $checkedOut[$worktree['branch']] = $worktree['path'];
            }
        }

        $rows = [];
        $seen = [];
        // Stacked branches (MINT-11 on MINT-10 on …) each carry the same story files; one
        // row per identical file version, attributed to the first branch that holds it.
        $seenVersions = [];
        foreach (array_merge([$project->path], $aliases) as $repo) {
            if (! $this->git->isRepository($repo)) {
                continue;
            }
            $branches = $this->git->unmergedBranches($repo, $project->ref);
            // Local branches first, so `docs/X` wins over the `origin/docs/X` it was pushed as.
            uksort($branches, fn ($a, $b) => [$this->isRemote($repo, $a), $a] <=> [$this->isRemote($repo, $b), $b]);

            foreach ($branches as $name => $commit) {
                if (isset($seen[$commit])) {
                    continue;
                }
                $seen[$commit] = true;

                try {
                    $records = $this->branchRecords($repo, $commit, $sha, $refBlobs, $readme, $refByPath, $refById);
                } catch (GitReaderException $e) {
                    // One odd branch (an orphan with no shared history, say) must not hide the rest.
                    Log::warning('board.offmain_branch_skipped', ['project' => $project->name, 'branch' => $name, 'error' => $e->getMessage()]);

                    continue;
                }

                $worktree = $checkedOut[$name] ?? null;
                $location = $worktree !== null
                    ? [Story::KIND_WORKTREE, 'worktree '.$this->real($worktree)]
                    : [Story::KIND_BRANCH, "branch {$name}"];

                foreach ($records as $record) {
                    $version = $record['path'].'@'.$record['_version'];
                    unset($record['_version']);
                    if (isset($seenVersions[$version])) {
                        continue;
                    }
                    $seenVersions[$version] = true;
                    $rows[] = $this->row($project, $record, $commit, $location[0], $location[1], $name);
                }
            }
        }

        return $rows;
    }

    /**
     * The story-index records a branch changes: files it changed since it forked
     * from the ref (its merge-base), that still differ from the ref today.
     * Diffing against the ref alone would report every story main has moved
     * on since an old branch forked — coins showed 903 such rows.
     *
     * @param  array<string, string>  $refBlobs
     * @param  array<string, array<string, mixed>>  $refByPath
     * @param  array<string, array<string, mixed>>  $refById
     * @return list<array<string, mixed>>
     */
    private function branchRecords(string $repo, string $commit, string $sha, array $refBlobs, ?string $readme, array $refByPath, array $refById): array
    {
        $blobs = $this->git->storyBlobs($repo, $commit);
        $baseBlobs = $this->git->storyBlobs($repo, $this->git->mergeBase($repo, $commit, $sha));
        $changed = array_keys(array_filter($blobs, fn ($blob, $path) => ($baseBlobs[$path] ?? null) !== $blob
            && ($refBlobs[$path] ?? null) !== $blob, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return [];
        }

        $mockupFiles = $this->mockupFiles(array_keys($blobs));
        $storyPaths = array_values(array_filter($changed, fn (string $p): bool => preg_match(self::STORY_FILE, $p) === 1));
        $records = $this->parser->parse($readme, array_map(fn ($p) => ['path' => $p, 'text' => $this->git->show($repo, $commit, $p)], $storyPaths), $mockupFiles);

        // A story counts only if the branch changes what the board shows about it. Matched
        // by ID when the path differs: stories are moved between folders on main, and the
        // same story often lands through another branch (squash, cherry-pick).
        $records = array_values(array_filter($records, function ($r) use ($refByPath, $refById) {
            $ref = $refByPath[$r['path']] ?? ($r['id'] !== null ? $refById[$r['id']] ?? null : null);

            return $ref === null
                || $ref['status'] !== $r['status']
                || $ref['mockups']['chosen'] !== $r['mockups']['chosen']
                || $ref['mockups']['options'] !== $r['mockups']['options'];
        }));

        foreach ($records as &$r) {
            $r['_version'] = $blobs[$r['path']] ?? '';
        }
        unset($r);

        // Mockup directories the branch adds to or changes, whose story file it did not touch.
        $covered = array_column($records, 'id');
        foreach ($this->changedMockupDirs($changed) as $id) {
            $record = $this->mockupOnlyRecord($id, $mockupFiles["docs/mockups/{$id}"] ?? [], $refById[$id] ?? null);
            // Same options as the ref already shows: an edit to an option file, not new work to pick from.
            $sameAsRef = isset($refById[$id]) && $refById[$id]['mockups']['options'] === $record['mockups']['options'];
            if (! in_array($id, $covered, true) && ! $sameAsRef) {
                // The directory's version is the set of its files' blobs.
                $dirBlobs = array_filter($blobs, fn ($p) => str_starts_with($p, "docs/mockups/{$id}/"), ARRAY_FILTER_USE_KEY);
                $record['_version'] = sha1(implode(',', $dirBlobs));
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Rows for untracked stories and mockups in every checkout: each worktree
     * (the primary checkout included) and each registered alias.
     *
     * @param  list<array{path: string, branch: string|null}>  $worktrees
     * @param  list<string>  $aliases
     * @param  array<string, array<string, mixed>>  $refById
     * @return list<array<string, mixed>>
     */
    private function untrackedRows(Project $project, string $sha, array $worktrees, array $aliases, ?string $readme, array $refById): array
    {
        $checkouts = [...$worktrees, ...array_map(fn ($a) => ['path' => $a, 'branch' => null], $aliases)];

        $rows = [];
        foreach ($checkouts as ['path' => $checkout, 'branch' => $branch]) {
            // A worktree whose directory was deleted without `git worktree prune`.
            if (! is_dir($checkout)) {
                continue;
            }
            $root = $this->real($checkout);
            $files = $this->git->untrackedStoryFiles($checkout);
            if ($files === []) {
                continue;
            }

            $mockupFiles = $this->mockupFiles($files);
            $storyFiles = [];
            foreach (array_filter($files, fn (string $f): bool => preg_match(self::STORY_FILE, $f) === 1) as $file) {
                $text = $this->readInside($root, $file);
                if ($text !== null) {
                    $storyFiles[] = ['path' => $file, 'text' => $text];
                }
            }
            $records = $this->parser->parse($readme, $storyFiles, $mockupFiles);

            $covered = array_column($records, 'id');
            foreach (array_keys($mockupFiles) as $dir) {
                $id = substr($dir, strlen('docs/mockups/'));
                if (! in_array($id, $covered, true)) {
                    $records[] = $this->mockupOnlyRecord($id, $mockupFiles[$dir], $refById[$id] ?? null);
                }
            }

            foreach ($records as $record) {
                $rows[] = $this->row($project, $record, $sha, Story::KIND_UNTRACKED, "untracked in {$root}", $branch);
            }
        }

        return $rows;
    }

    /**
     * Group mockup file paths by their story directory: docs/mockups/<ID> => file names below it.
     *
     * @param  list<string>  $paths
     * @return array<string, list<string>>
     */
    private function mockupFiles(array $paths): array
    {
        $dirs = [];
        foreach ($paths as $path) {
            if (preg_match(self::MOCKUP_FILE, $path, $m)) {
                $dirs["docs/mockups/{$m[1]}"][] = $m[2];
            }
        }

        return $dirs;
    }

    /**
     * Story IDs whose mockup directory has a changed or new file among `$paths`.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function changedMockupDirs(array $paths): array
    {
        $ids = [];
        foreach ($paths as $path) {
            if (preg_match(self::MOCKUP_FILE, $path, $m)) {
                $ids[$m[1]] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * A record for mockups with no story file beside them in that location.
     * Title and status come from the ref's story of that ID when there is one.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>|null  $refStory
     * @return array<string, mixed>
     */
    private function mockupOnlyRecord(string $id, array $files, ?array $refStory): array
    {
        $options = [];
        foreach ($files as $file) {
            if (preg_match('/^option-(.+)\.html$/D', $file, $m)) {
                $options[] = $m[1];
            }
        }
        sort($options);

        return [
            'id' => $id,
            'title' => $refStory['title'] ?? null,
            'status' => $refStory['status'] ?? null,
            'journey' => $refStory['journey'] ?? null,
            'initiative' => $refStory['initiative'] ?? null,
            'path' => "docs/mockups/{$id}",
            'source' => null,
            'depends_on' => [],
            'mockups' => ['dir' => "docs/mockups/{$id}", 'options' => $options, 'chosen' => $refStory['mockups']['chosen'] ?? null],
            'parse_errors' => [],
        ];
    }

    /**
     * One `stories` insert row for an off-main record.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function row(Project $project, array $r, string $sha, string $kind, string $location, ?string $branch): array
    {
        return [
            'project_id' => $project->id,
            'story_id' => $r['id'],
            'title' => $r['title'],
            'status' => $r['status'],
            'initiative' => $r['initiative'],
            'is_parked' => false,
            'journey' => $r['journey'],
            'path' => $r['path'],
            'source' => $r['source'],
            'dated_on' => null,
            'depends_on' => json_encode($r['depends_on']),
            'mockups' => json_encode($r['mockups']),
            'parse_errors' => json_encode($r['parse_errors']),
            'sha' => $sha,
            'location_kind' => $kind,
            'location' => $location,
            'branch' => $branch,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * stories/README.md at the ref, or null when the project has none.
     */
    private function readme(Project $project, string $sha): ?string
    {
        try {
            return $this->git->show($project->path, $sha, 'stories/README.md');
        } catch (GitReaderException) {
            return null;
        }
    }

    /**
     * Read an untracked file only if it resolves inside its checkout — a symlink
     * pointing elsewhere is skipped rather than followed.
     */
    private function readInside(string $root, string $file): ?string
    {
        $real = realpath("{$root}/{$file}");
        if ($real === false || ! str_starts_with($real, $root.'/') || ! is_file($real)) {
            Log::warning('board.offmain_file_skipped', ['checkout' => $root, 'file' => $file, 'reason' => 'resolves outside the checkout or is not a file']);

            return null;
        }

        return (string) file_get_contents($real);
    }

    /**
     * Whether `$name` is a remote-tracking branch (it starts with one of the repo's remotes).
     */
    private function isRemote(string $repo, string $name): bool
    {
        $this->remotes[$repo] ??= preg_split('/\s+/', trim($this->git->run($repo, ['remote']))) ?: [];

        return in_array(explode('/', $name, 2)[0], $this->remotes[$repo], true);
    }

    /**
     * A path with symlinks resolved (macOS's /var → /private/var), for stable labels.
     */
    private function real(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
