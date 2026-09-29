<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads a story modal link, `?story=<project>/<ID>` (SB-8), and finds the row it
 * names. The one place the value is parsed: nothing reaches the database, let
 * alone git, until the project part is a plain name and the ID matches
 * Story::ID_PATTERN. Every refusal is logged with its reason.
 */
class ResolveStoryLink
{
    /** The value is not `<project>/<ID>`: a path trick, a lower-case ID, a stray slash. */
    public const MALFORMED = 'malformed';

    /** Well formed, but no such project or no such story in it. */
    public const UNKNOWN = 'unknown';

    /** The project is registered but taken off the board. */
    public const DISABLED = 'disabled';

    /**
     * A project name as the board registers them (a directory's basename): it
     * starts with a letter or digit, so `.` and `..` can never be one.
     */
    private const LINK = '#^([A-Za-z0-9][A-Za-z0-9._-]*)/([^/]+)$#D';

    /**
     * @param  CheckProjectShown  $shown  decides unknown vs disabled for every project URL (SB-7)
     * @param  FindStoryVersion  $find  picks the ref's row, or the first off-main one (SB-5)
     */
    public function __construct(private CheckProjectShown $shown, private FindStoryVersion $find) {}

    /**
     * The story the link names, or why it cannot be shown.
     *
     * Side effect: logs `board.story_modal_refused` (info) with the reason on every refusal.
     *
     * @return array{story: Story|null, project: string|null, id: string|null, reason: self::MALFORMED|self::UNKNOWN|self::DISABLED|null}
     */
    public function handle(string $link): array
    {
        if (! preg_match(self::LINK, $link, $m) || preg_match(Story::ID_PATTERN, $m[2]) !== 1) {
            // Only a bounded echo of the value: it came from the address bar, not from the board.
            return $this->refuse(null, Str::limit($link, 80), self::MALFORMED);
        }
        [, $name, $id] = $m;

        $reason = $this->shown->refusal($name);
        if ($reason !== null) {
            return $this->refuse($name, $id, $reason === CheckProjectShown::DISABLED ? self::DISABLED : self::UNKNOWN);
        }

        $story = $this->find->handle(Project::where('name', $name)->sole(), $id);
        if ($story === null) {
            return $this->refuse($name, $id, self::UNKNOWN);
        }

        return ['story' => $story->load('project'), 'project' => $name, 'id' => $id, 'reason' => null];
    }

    /**
     * Log the refusal and return it.
     *
     * @param  self::MALFORMED|self::UNKNOWN|self::DISABLED  $reason
     * @return array{story: null, project: string|null, id: string|null, reason: self::MALFORMED|self::UNKNOWN|self::DISABLED}
     */
    private function refuse(?string $project, ?string $id, string $reason): array
    {
        Log::info('board.story_modal_refused', ['project' => $project, 'story' => $id, 'reason' => $reason]);

        return ['story' => null, 'project' => $project, 'id' => $id, 'reason' => $reason];
    }
}
