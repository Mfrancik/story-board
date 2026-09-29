<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings one project's story snapshot up to date with its ref: fetch, index at
 * the ref, replace the stored rows. A failure never loses the last snapshot.
 */
class RefreshProject
{
    /**
     * Longer than the worst case of every git timeout in one refresh (30 + 60 +
     * 30 + 60 s = 180 s), so the lock cannot expire while its refresh still runs.
     */
    private const LOCK_SECONDS = 300;

    /**
     * @param  GitReader  $git  the only way the board touches a project
     */
    public function __construct(private GitReader $git) {}

    /**
     * Refresh `$project`, recording the outcome in its `state`.
     *
     * Side effects: runs `git fetch` in the project (remote-tracking refs only),
     * replaces its `stories` rows, updates the project row, logs board.* events.
     */
    public function handle(Project $project): void
    {
        // Two page loads a second apart would otherwise run two fetches and two
        // snapshot swaps against the same rows; the second one simply stands down.
        $lock = Cache::lock("board:refresh:{$project->id}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            Log::info('board.refresh_skipped', ['project' => $project->name, 'reason' => 'already running']);

            return;
        }

        try {
            $this->refresh($project);
        } finally {
            $lock->release();
        }
    }

    /**
     * The refresh itself, run while holding the project's lock.
     */
    private function refresh(Project $project): void
    {
        $project->update(['refresh_attempted_at' => now()]);
        $started = hrtime(true);
        Log::info('board.refresh_started', ['project' => $project->name, 'ref' => $project->ref]);

        if (! $this->git->isRepository($project->path)) {
            Log::warning('board.project_unreachable', ['project' => $project->name, 'path' => $project->path]);
            $project->update(['state' => Project::STATE_UNREACHABLE, 'last_error' => "not a git repository: {$project->path}"]);

            return;
        }

        try {
            $this->git->fetch($project->path, $project->ref);
        } catch (GitReaderException $e) {
            // Offline or the remote is gone: indexing now would read an old ref and
            // stamp it fresh, so keep the last snapshot and say it is stale instead.
            Log::warning('board.fetch_failed', ['project' => $project->name, 'error' => $e->getMessage()]);
            $project->update(['state' => Project::STATE_STALE, 'last_error' => $e->getMessage()]);

            return;
        }

        try {
            $sha = $this->git->resolve($project->path, $project->ref);
            $records = $this->git->storyIndex($project->path, $project->ref);
            // Read at the resolved SHA, not the ref, so both reads describe the same commit.
            $parked = $this->parkedInitiatives($project, $sha);
        } catch (GitReaderException $e) {
            Log::warning('board.index_failed', ['project' => $project->name, 'ref' => $project->ref, 'error' => $e->getMessage()]);
            $project->update(['state' => Project::STATE_STALE, 'last_error' => $e->getMessage()]);

            return;
        }

        $this->replaceSnapshot($project, $sha, $records, $parked);

        Log::info('board.refresh_finished', [
            'project' => $project->name,
            'count' => count($records),
            'sha' => $sha,
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);
    }

    /**
     * Swap the project's stored rows for `$records` in one transaction, so a
     * reader never sees a half-written snapshot.
     *
     * @param  list<array<string, mixed>>  $records  bin/story-index output
     * @param  list<string>  $parked  initiatives that are parked draft groups
     */
    private function replaceSnapshot(Project $project, string $sha, array $records, array $parked): void
    {
        $now = now();
        $rows = array_map(fn (array $r) => [
            'project_id' => $project->id,
            'story_id' => $r['id'],
            'title' => $r['title'],
            'status' => $r['status'],
            'initiative' => $r['initiative'],
            'is_parked' => in_array($r['initiative'], $parked, true),
            'journey' => $r['journey'],
            'path' => $r['path'],
            'source' => $r['source'],
            'dated_on' => $this->dateOf($r['source']),
            'depends_on' => json_encode($r['depends_on']),
            'mockups' => json_encode($r['mockups']),
            'parse_errors' => json_encode($r['parse_errors']),
            'sha' => $sha,
            'created_at' => $now,
            'updated_at' => $now,
        ], $records);

        DB::transaction(function () use ($project, $sha, $rows, $now) {
            $project->stories()->delete();
            // Bulk insert: coins alone is ~900 rows, one INSERT per row would dominate the refresh.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('stories')->insert($chunk);
            }
            $project->update(['state' => Project::STATE_OK, 'sha' => $sha, 'indexed_at' => $now, 'last_error' => null]);
        });
    }

    /**
     * Initiatives parked as a draft group at `$sha`: an initiative folder whose own
     * README has a `Status:` line starting `draft group` (kit stories/README.md
     * §Draft groups). A README alone is not enough — coins has a "release group"
     * README and one with no status, and neither is parked.
     *
     * @return list<string>
     *
     * @throws GitReaderException when git cannot list or read the files.
     */
    private function parkedInitiatives(Project $project, string $sha): array
    {
        $parked = [];
        foreach ($this->git->listFiles($project->path, $sha, 'stories/') as $file) {
            if (! preg_match('#^stories/([^/]+)/README\.md$#', $file, $m)) {
                continue;
            }
            $readme = $this->git->show($project->path, $sha, $file);
            if (preg_match('/^\**Status:\**\s*draft group\b/mi', $readme)) {
                $parked[] = $m[1];
            }
        }

        return $parked;
    }

    /**
     * The first valid YYYY-MM-DD in a Source line, or null.
     */
    private function dateOf(mixed $source): ?string
    {
        if (! is_string($source) || ! preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $source, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
