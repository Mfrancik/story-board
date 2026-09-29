<?php

namespace App\Services;

use App\Exceptions\GitReaderException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * The only code in the board allowed to run git. Every command against a
 * registered project passes through run(), which refuses any subcommand not on
 * a read-only allow-list — the board must never change a project's files,
 * branches, or index (owner ruling, SB-2).
 */
class GitReader
{
    /**
     * Subcommands that cannot change a working tree, index, or branch. `fetch`
     * is the one that writes at all, and only to remote-tracking refs under .git.
     */
    private const ALLOWED = ['fetch', 'ls-tree', 'show', 'rev-parse', 'cat-file', 'remote'];

    /**
     * Keep git from ever waiting on a human: a passphrase or host-key prompt
     * would otherwise hang a page-load refresh until the timeout.
     */
    private const ENV = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o ConnectTimeout=10',
        'GIT_OPTIONAL_LOCKS' => '0',
    ];

    /**
     * Whether `$path` exists and is inside a git repository.
     */
    public function isRepository(string $path): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        try {
            $this->run($path, ['rev-parse', '--git-dir']);

            return true;
        } catch (GitReaderException) {
            return false;
        }
    }

    /**
     * Fetch the remote that `$ref` tracks (`origin` for `origin/main`), or the
     * default remote if the ref names none. Writes only remote-tracking refs.
     *
     * @throws GitReaderException when the fetch fails (offline, remote gone, auth).
     */
    public function fetch(string $path, string $ref): void
    {
        $remotes = preg_split('/\s+/', trim($this->run($path, ['remote']))) ?: [];
        $remote = explode('/', $ref, 2)[0];
        $args = in_array($remote, $remotes, true) ? [$remote] : [];

        // Auto-gc/maintenance would rewrite pack files in the project's .git; not ours to do.
        $this->run($path, ['fetch', '--quiet', '--no-tags', ...$args], timeout: 60, config: [
            'gc.auto=0', 'maintenance.auto=false',
        ]);
    }

    /**
     * The commit SHA that `$ref` points at.
     *
     * @throws GitReaderException when the ref does not resolve to a commit.
     */
    public function resolve(string $path, string $ref): string
    {
        return trim($this->run($path, ['rev-parse', '--verify', '--quiet', "{$ref}^{commit}"]));
    }

    /**
     * The raw bytes of `$file` at `$ref`.
     *
     * @throws GitReaderException when the file does not exist at the ref.
     */
    public function show(string $path, string $ref, string $file): string
    {
        return $this->run($path, ['show', "{$ref}:{$file}"]);
    }

    /**
     * Every story record at `$ref`, straight from the kit's bin/story-index
     * contract (docs/KIT-REFERENCE.md §story-index). The parser reads through
     * `git ls-tree` and `git cat-file` only, so it is inside the read-only rule.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GitReaderException when the repo or ref is bad (parser exit 2) or its output is not JSON.
     */
    public function storyIndex(string $path, string $ref): array
    {
        $result = Process::env(self::ENV)->timeout(60)
            ->run([base_path('bin/story-index'), $path, $ref]);

        if (! $result->successful()) {
            throw new GitReaderException(trim($result->errorOutput()) ?: 'story-index failed');
        }

        try {
            /** @var list<array<string, mixed>> */
            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GitReaderException('story-index returned invalid JSON: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Run one allow-listed git subcommand in `$path` and return its stdout.
     *
     * @param  list<string>  $args  the subcommand first, then its arguments
     * @param  list<string>  $config  `-c key=value` settings the reader itself imposes
     *
     * @throws GitReaderException when the subcommand is not allowed, fails, or times out.
     */
    public function run(string $path, array $args, int $timeout = 30, array $config = []): string
    {
        $subcommand = $args[0] ?? '';
        if (! in_array($subcommand, self::ALLOWED, true)) {
            throw new GitReaderException("git {$subcommand} is not allowed: the board is read-only");
        }

        $command = ['git', '-C', $path];
        foreach ($config as $setting) {
            array_push($command, '-c', $setting);
        }

        try {
            $result = Process::env(self::ENV)->timeout($timeout)->run([...$command, ...$args]);
        } catch (ProcessTimedOutException $e) {
            throw new GitReaderException("git {$subcommand} timed out after {$timeout}s", previous: $e);
        }

        if (! $result->successful()) {
            $error = strtok(trim($result->errorOutput()), "\n") ?: "git {$subcommand} failed";
            throw new GitReaderException($error);
        }

        return $result->output();
    }
}
