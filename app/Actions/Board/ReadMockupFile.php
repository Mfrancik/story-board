<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Exceptions\MockupNotFoundException;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Reads one file from a story's mockup directory at the snapshot's commit, for
 * the board to serve into a sandboxed frame (SB-4). The only door from a URL to
 * a project's bytes, so it refuses any path that is not plainly inside
 * `docs/mockups/<ID>/`.
 */
class ReadMockupFile
{
    /**
     * @param  GitReader  $git  the only way the board touches a project
     */
    public function __construct(private GitReader $git) {}

    /**
     * The bytes of `$file` inside `$storyId`'s mockup directory, read at the
     * commit the story was indexed from — the ref's, or with `$version` (an
     * off-main row id, SB-5) that branch's commit. Untracked versions are refused.
     *
     * Side effects: logs board.mockup_path_rejected (warning) or board.mockup_served (debug).
     *
     * @throws MockupNotFoundException when the story, its mockup directory, the path, or the file is not servable.
     */
    public function handle(Project $project, string $storyId, string $file, ?int $version = null): string
    {
        // Checked here, not only by the database lookup below, so the guarantee is local to this file.
        if (! preg_match('/^[A-Z]{2,}-[0-9]+[a-z]?$/D', $storyId) || ! $this->isPlainRelativePath($file)) {
            Log::warning('board.mockup_path_rejected', ['project' => $project->name, 'story' => $storyId, 'file' => $file]);
            throw new MockupNotFoundException("Rejected mockup path: {$file}");
        }

        $story = $version === null
            ? Story::onRef()->where('project_id', $project->id)->where('story_id', $storyId)->first()
            // The version must be this project's, this story's, and in git (a branch commit).
            : Story::offMain()->where('project_id', $project->id)->where('story_id', $storyId)
                ->whereIn('location_kind', [Story::KIND_BRANCH, Story::KIND_WORKTREE])->find($version);
        $dir = $story?->mockups['dir'] ?? null;
        // The directory comes from the snapshot, never the URL, so a story can only serve its own folder.
        if ($story === null || $dir !== "docs/mockups/{$storyId}") {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $storyId, 'reason' => $story ? 'no mockup directory' : 'unknown story']);
            throw new MockupNotFoundException("No mockups for {$storyId}");
        }

        try {
            $bytes = $this->git->show($project->path, $story->sha, "{$dir}/{$file}");
        } catch (GitReaderException $e) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $storyId, 'file' => $file, 'reason' => 'not at the ref']);
            throw new MockupNotFoundException("Not at the ref: {$dir}/{$file}", previous: $e);
        }

        Log::debug('board.mockup_served', ['project' => $project->name, 'story' => $storyId, 'file' => $file, 'bytes' => strlen($bytes)]);

        return $bytes;
    }

    /**
     * Whether `$file` is a plain path below the mockup directory: non-empty
     * segments of ordinary characters, none of them dot-led (`..`, `.`, hidden
     * files), no backslashes or NULs. Everything else is refused rather than
     * normalised — a path that needs cleaning up was not sent by the board.
     */
    private function isPlainRelativePath(string $file): bool
    {
        if ($file === '' || strlen($file) > 255) {
            return false;
        }

        foreach (explode('/', $file) as $segment) {
            if (! preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/D', $segment)) {
                return false;
            }
        }

        return true;
    }
}
