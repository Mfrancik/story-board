<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Where the dev-standards kit is and which commit to compare with (SB-14). The
 * kit is a second repository read through the same GitReader gateway as the
 * projects, but it is never registered as one: its path and ref come from
 * `config/board.php`. An unreachable kit hides the handbook's badges and
 * nothing else, so this reports the failure instead of throwing it.
 */
class ResolveKit
{
    /**
     * @param  GitReader  $git  the only way the board touches a repository
     */
    public function __construct(private GitReader $git) {}

    /**
     * The kit's path and the commit its configured ref points at, or the reason
     * it cannot be read. A failure logs `board.kit_unreachable` (warning).
     *
     * @return array{path: string, sha: string|null, error: string|null}
     */
    public function handle(): array
    {
        $path = (string) config('board.kit_path');
        $ref = (string) config('board.kit_ref');

        try {
            // isRepository() swallows git's error, so a missing folder and a plain folder are told apart here.
            if (! is_dir($path)) {
                throw new GitReaderException('no such directory');
            }
            if (! $this->git->isRepository($path)) {
                throw new GitReaderException('not a git repository');
            }
            $sha = $this->git->resolve($path, $ref);
            if ($sha === '') {
                throw new GitReaderException("ref {$ref} does not resolve");
            }

            return ['path' => $path, 'sha' => $sha, 'error' => null];
        } catch (GitReaderException $e) {
            Log::warning('board.kit_unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return ['path' => $path, 'sha' => null, 'error' => $e->getMessage()];
        }
    }
}
