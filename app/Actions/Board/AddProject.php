<?php

namespace App\Actions\Board;

use App\Exceptions\ProjectAddRefusedException;
use App\Exceptions\ProjectRegistrationException;
use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Adds a project from the Manage projects form (SB-12): expands `~`, registers
 * the folder through RegisterProject — the one place registration is validated —
 * and queues the first refresh. A refusal is translated into the form field it
 * belongs under and a stable reason for the log.
 */
class AddProject
{
    /**
     * @param  RegisterProject  $register  validates and inserts, exactly as `board:project add` does
     * @param  GitReader  $git  re-asks whether the folder is a repo, only to name a refusal's reason
     */
    public function __construct(private RegisterProject $register, private GitReader $git) {}

    /**
     * Register `$path` (a leading `~` is the home folder) as a project and queue its first refresh.
     *
     * Side effects: inserts a `projects` row (logged by RegisterProject), dispatches
     * RefreshProjectJob; on refusal logs board.project_add_refused.
     *
     * @param  string|null  $name  blank means the folder's name
     * @param  string|null  $ref  blank means origin/main
     *
     * @throws ProjectAddRefusedException when the path is blank or missing, not a repo, already registered, or the ref is invalid.
     */
    public function handle(string $path, ?string $name = null, ?string $ref = null): Project
    {
        $path = $this->expandHome(trim($path));
        $name = trim((string) $name) ?: null;
        $ref = trim((string) $ref) ?: 'origin/main';

        // RegisterProject would read a blank path as the current directory — the board's own checkout.
        if ($path === '') {
            throw $this->refuse($path, 'path', ProjectAddRefusedException::MISSING_PATH, 'Enter the folder of a git checkout.');
        }

        try {
            $project = $this->register->handle($path, $name, $ref);
        } catch (ProjectRegistrationException) {
            throw $this->classify($path, $name, $ref);
        }

        RefreshProjectJob::dispatch($project);

        return $project;
    }

    /**
     * The owner's home folder, without a trailing slash: what `~` stands for.
     */
    public static function home(): string
    {
        return rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME')), '/');
    }

    /**
     * `~` and `~/…` are the owner's home folder, as in a shell; PHP's realpath does not expand them.
     */
    private function expandHome(string $path): string
    {
        $home = self::home();

        return match (true) {
            $path === '~' => $home,
            str_starts_with($path, '~/') => $home.substr($path, 1),
            default => $path,
        };
    }

    /**
     * Name the reason RegisterProject refused, by re-running its checks in its own
     * order (ref, then folder, then repo, then duplicate). RegisterProject stays
     * unchanged and its message stays an unparsed sentence for the CLI.
     */
    private function classify(string $path, ?string $name, string $ref): ProjectAddRefusedException
    {
        if (! GitReader::isValidRef($ref)) {
            return $this->refuse($path, 'ref', ProjectAddRefusedException::BAD_REF, 'That is not a valid git ref.');
        }

        $real = realpath($path);
        if ($real === false) {
            return $this->refuse($path, 'path', ProjectAddRefusedException::MISSING_PATH, 'That folder does not exist.');
        }

        if (! $this->git->isRepository($real)) {
            return $this->refuse($path, 'path', ProjectAddRefusedException::NOT_A_REPO, 'That folder is not a git repository.');
        }

        // A path match is reported under Path even when the name also clashes: the folder is the project.
        $byPath = Project::where('path', $real)->first(['name']);
        $taken = $byPath ?? Project::where('name', $name ?: basename($real))->first(['name']);

        return $this->refuse($path, $byPath ? 'path' : 'name', ProjectAddRefusedException::DUPLICATE,
            'Already on the board as '.($taken->name ?? $name).'.');
    }

    /**
     * Log the refusal (L-5: every guard logs) and hand back the exception to throw.
     *
     * @param  'path'|'name'|'ref'  $field
     * @param  ProjectAddRefusedException::*  $reason
     */
    private function refuse(string $path, string $field, string $reason, string $message): ProjectAddRefusedException
    {
        Log::info('board.project_add_refused', ['path' => $path, 'reason' => $reason]);

        return new ProjectAddRefusedException($field, $reason, $message);
    }
}
