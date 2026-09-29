<?php

namespace App\Actions\Board;

use App\Models\Project;

/**
 * Whether a project name may have a page on the board (SB-7): it must be
 * registered and enabled. The one place that decides it, read by the
 * project-page guard and by the old `/?project=` redirect.
 */
class CheckProjectShown
{
    /** No project is registered under the name. */
    public const UNKNOWN = 'unknown';

    /** The project is registered but taken off the board. */
    public const DISABLED = 'disabled';

    /**
     * Why the project cannot be shown, or null when it can.
     *
     * @return self::UNKNOWN|self::DISABLED|null
     */
    public function refusal(string $name): ?string
    {
        $project = Project::where('name', $name)->first(['id', 'is_enabled']);

        return match (true) {
            $project === null => self::UNKNOWN,
            ! $project->is_enabled => self::DISABLED,
            default => null,
        };
    }
}
