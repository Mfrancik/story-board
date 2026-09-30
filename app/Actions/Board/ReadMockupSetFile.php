<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Exceptions\MockupNotFoundException;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Reads one file of a mockup set for the gallery's sandboxed frames (SB-21).
 * The requested name is only ever compared against the set directory's own
 * listing at the ref — it is never joined onto a path — so nothing outside
 * `docs/mockups/<ID>/` can be named, let alone read.
 */
class ReadMockupSetFile
{
    /**
     * @param  ReadMockupSets  $sets  which sets exist, and at which commit
     * @param  GitReader  $git  the listing and the bytes, both at the ref
     */
    public function __construct(private readonly ReadMockupSets $sets, private readonly GitReader $git) {}

    /**
     * The bytes of `$file` in `$storyId`'s set, read at the snapshot commit.
     *
     * Side effects: logs `board.mockup_file_refused` (warning) for a name not in
     * the listing, `board.mockup_not_found` (info) for a story with no set, and
     * `board.mockup_served` (debug).
     *
     * @throws MockupNotFoundException when there is no such set or no such file in it.
     */
    public function handle(Project $project, string $storyId, string $file): string
    {
        $set = $this->sets->find($project, $storyId);
        if ($set === null) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $storyId, 'reason' => 'no mockup set']);
            throw new MockupNotFoundException("No mockup set for {$storyId}");
        }

        $prefix = $set['dir'].'/';
        try {
            $listing = $this->git->listFiles($project->path, $set['sha'], $prefix);
        } catch (GitReaderException $e) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $storyId, 'reason' => 'not at the ref']);
            throw new MockupNotFoundException("Cannot list {$prefix}", previous: $e);
        }

        // Exact match against the listing: `../../.env`, `%2e%2e`, `./x` and the like are simply not in it.
        $path = $prefix.$file;
        if (! in_array($path, $listing, true)) {
            Log::warning('board.mockup_file_refused', ['project' => $project->name, 'path' => $path]);
            throw new MockupNotFoundException("Not in the set: {$file}");
        }

        try {
            $bytes = $this->git->show($project->path, $set['sha'], $path);
        } catch (GitReaderException $e) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $storyId, 'file' => $file, 'reason' => 'not at the ref']);
            throw new MockupNotFoundException("Not at the ref: {$path}", previous: $e);
        }

        Log::debug('board.mockup_served', ['project' => $project->name, 'story' => $storyId, 'file' => $file, 'bytes' => strlen($bytes)]);

        return $bytes;
    }
}
