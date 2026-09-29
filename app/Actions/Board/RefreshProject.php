<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings one project's story snapshot up to date with its ref: fetch, index at
 * the ref, replace the stored rows. A failure never loses the last snapshot.
 */
class RefreshProject
{
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
        } catch (GitReaderException $e) {
            Log::warning('board.index_failed', ['project' => $project->name, 'ref' => $project->ref, 'error' => $e->getMessage()]);
            $project->update(['state' => Project::STATE_STALE, 'last_error' => $e->getMessage()]);

            return;
        }

        $this->replaceSnapshot($project, $sha, $records);

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
     */
    private function replaceSnapshot(Project $project, string $sha, array $records): void
    {
        $now = now();
        $rows = array_map(fn (array $r) => [
            'project_id' => $project->id,
            'story_id' => $r['id'],
            'title' => $r['title'],
            'status' => $r['status'],
            'initiative' => $r['initiative'],
            'journey' => $r['journey'],
            'path' => $r['path'],
            'source' => $r['source'],
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
}
