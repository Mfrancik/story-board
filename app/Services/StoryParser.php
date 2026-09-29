<?php

namespace App\Services;

use App\Exceptions\GitReaderException;
use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * Parses story texts that are not at a ref (a file diffed out of a branch, an
 * untracked file) with the kit's own parser, via scripts/parse-story-files.py.
 * One parser for the whole board: no story rule is re-implemented in PHP.
 */
class StoryParser
{
    /**
     * story-index records for `$files`, in order.
     *
     * @param  string|null  $readme  stories/README.md at the ref, for the status vocabulary
     * @param  list<array{path: string, text: string}>  $files
     * @param  array<string, list<string>>  $mockupFiles  mockup directory => file names in it
     * @return list<array<string, mixed>>
     *
     * @throws GitReaderException when the parser fails or returns something other than JSON.
     */
    public function parse(?string $readme, array $files, array $mockupFiles): array
    {
        if ($files === []) {
            return [];
        }

        $input = json_encode(['readme' => $readme, 'files' => $files, 'mockup_files' => (object) $mockupFiles], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $result = Process::timeout(60)->input($input)->run([base_path('scripts/parse-story-files.py')]);

        if (! $result->successful()) {
            throw new GitReaderException('parse-story-files failed: '.strtok(trim($result->errorOutput()), "\n"));
        }

        try {
            /** @var list<array<string, mixed>> */
            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GitReaderException('parse-story-files returned invalid JSON: '.$e->getMessage(), previous: $e);
        }
    }
}
