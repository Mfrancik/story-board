<?php

namespace App\Actions\Marketing;

use App\Exceptions\GitReaderException;
use App\Exceptions\MarketingLogoException;
use App\Models\MarketingBrand;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * Reads a brand logo from a file in the project's own repo at a ref (MK-2) — StackerMint's swirl lives in coins'
 * `resources/views/components/app-mark.blade.php` on `origin/main` — and stores it through SetBrandLogo. Uses only
 * GitReader's existing read-only `resolve` and `show`; nothing is fetched, so the ref is read as the clone has it.
 */
class ReadLogoFromRepo
{
    /**
     * Create the action.
     */
    public function __construct(private readonly GitReader $git, private readonly SetBrandLogo $setLogo) {}

    /**
     * Read `$path` at `$ref` from `$project`'s checkout and store it, sanitised, as the brand logo. The source line
     * reads "<path> @ <ref> · <short sha>", so the owner can tell which commit the logo came from.
     *
     * Side effects: two read-only git commands; writes the brand (see SetBrandLogo).
     *
     * @throws MarketingLogoException for a malformed path or ref, a ref or file that is not there, or no SVG in it;
     *                                each logged as `marketing.brand.logo_refused`.
     */
    public function handle(Project $project, string $path, string $ref): MarketingBrand
    {
        $path = trim($path);
        $ref = trim($ref);
        // A path is looked up inside the ref's tree, but one that starts outside it, or smuggles a newline, is no logo path.
        if ($path === '' || str_starts_with($path, '/') || preg_match('/[\x00-\x1f]/', $path) || str_contains($path, '..')) {
            $this->refuse($project, 'bad_path', 'logoPath', 'Give the logo\'s path inside the repo, e.g. resources/views/components/app-mark.blade.php.');
        }
        if (! GitReader::isValidRef($ref)) {
            $this->refuse($project, 'bad_ref', 'logoRef', 'That is not a ref git can read, e.g. origin/main.');
        }

        try {
            $sha = $this->git->resolve($project->path, $ref);
        } catch (GitReaderException) {
            $this->refuse($project, 'unknown_ref', 'logoRef', "{$project->name} has no {$ref} to read from.");
        }

        try {
            $raw = $this->git->show($project->path, $ref, $path);
        } catch (GitReaderException) {
            $this->refuse($project, 'not_found', 'logoPath', "There is no {$path} at {$ref}.");
        }

        return $this->setLogo->handle($project, $raw, "{$path} @ {$ref} · ".substr($sha, 0, 7));
    }

    /**
     * Log why the logo was refused and throw the owner's message for `$field`.
     *
     * @throws MarketingLogoException always.
     */
    private function refuse(Project $project, string $reason, string $field, string $message): never
    {
        Log::warning('marketing.brand.logo_refused', ['project' => $project->name, 'reason' => $reason]);

        throw new MarketingLogoException($message, $field, $reason);
    }
}
