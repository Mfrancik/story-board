<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Story;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A story's markdown, read from git at the commit its row was indexed from and
 * rendered to safe HTML. Story text is not stored: the snapshot holds the
 * fields the lists need, and the body is read on demand (SB-3 expand, SB-4 page).
 */
class RenderStory
{
    /**
     * @param  GitReader  $git  the only way the board touches a project
     */
    public function __construct(private GitReader $git) {}

    /**
     * The story's body as HTML, or null when git cannot read it.
     *
     * Raw HTML in the markdown is escaped and unsafe links dropped: story files
     * are written by agents and humans in other repos, and render inside the board.
     */
    public function handle(Story $story): ?string
    {
        $markdown = $this->read($story);

        return $markdown === null ? null : $this->toHtml($markdown);
    }

    /**
     * The story's raw markdown at its row's SHA, or null (logged) when git cannot read it.
     */
    public function read(Story $story): ?string
    {
        // An untracked file is not in git, and the board reads projects only through git.
        if ($story->location_kind === Story::KIND_UNTRACKED) {
            return null;
        }

        try {
            return $this->git->show($story->project->path, $story->sha, $story->path);
        } catch (GitReaderException $e) {
            Log::warning('board.story_read_failed', ['project' => $story->project->name, 'story' => $story->story_id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Markdown to safe HTML: raw HTML escaped, unsafe links dropped.
     */
    public function toHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }
}
