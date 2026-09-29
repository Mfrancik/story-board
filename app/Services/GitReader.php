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
     * `worktree` and `status` are allowed in one read-only form each (see run()).
     */
    private const ALLOWED = ['fetch', 'ls-tree', 'show', 'rev-parse', 'cat-file', 'remote', 'for-each-ref', 'worktree', 'status', 'merge-base'];

    /**
     * What a ref may look like: branch/remote names and SHAs. A leading `-` is
     * refused because git would parse the ref as an option — `show --output=…`
     * writes a file. Stricter than git-check-ref-format on purpose.
     */
    private const REF_PATTERN = '#^(?!-)(?!.*\.\.)[A-Za-z0-9._/-]+$#D';

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
        $this->assertRef($ref);
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
        $this->assertRef($ref);

        return trim($this->run($path, ['rev-parse', '--verify', '--quiet', "{$ref}^{commit}"]));
    }

    /**
     * The raw bytes of `$file` at `$ref`.
     *
     * @throws GitReaderException when the file does not exist at the ref.
     */
    public function show(string $path, string $ref, string $file): string
    {
        $this->assertRef($ref);

        return $this->run($path, ['show', "{$ref}:{$file}"]);
    }

    /**
     * Every file path under `$prefix` at `$ref`, recursively.
     *
     * @return list<string>
     *
     * @throws GitReaderException when the ref does not resolve.
     */
    public function listFiles(string $path, string $ref, string $prefix): array
    {
        $this->assertRef($ref);
        $out = $this->run($path, ['ls-tree', '-r', '-z', '--name-only', $ref, '--', $prefix]);

        return array_values(array_filter(explode("\0", $out), fn ($f) => $f !== ''));
    }

    /**
     * Every blob under `stories/` and `docs/mockups/` at `$ref`, as path => blob
     * SHA. Comparing two of these finds what a branch changed without `git diff`.
     *
     * @return array<string, string>
     *
     * @throws GitReaderException when the ref does not resolve.
     */
    public function storyBlobs(string $path, string $ref): array
    {
        $this->assertRef($ref);
        $out = $this->run($path, ['ls-tree', '-r', '-z', $ref, '--', 'stories/', 'docs/mockups/']);

        $blobs = [];
        foreach (explode("\0", $out) as $entry) {
            // `<mode> blob <sha>\t<path>`
            if (preg_match('/^\d+ blob ([0-9a-f]+)\t(.+)$/s', $entry, $m)) {
                $blobs[$m[2]] = $m[1];
            }
        }

        return $blobs;
    }

    /**
     * The commit where `$a` and `$b` diverged.
     *
     * @throws GitReaderException when either ref is invalid or they share no history.
     */
    public function mergeBase(string $path, string $a, string $b): string
    {
        $this->assertRef($a);
        $this->assertRef($b);

        return trim($this->run($path, ['merge-base', $a, $b]));
    }

    /**
     * Local and remote-tracking branches with commits not in `$ref`, as
     * name => commit SHA. Symbolic refs (origin/HEAD) are skipped.
     *
     * @return array<string, string>
     *
     * @throws GitReaderException when the ref does not resolve.
     */
    public function unmergedBranches(string $path, string $ref): array
    {
        $this->assertRef($ref);
        $out = $this->run($path, ['for-each-ref', "--no-merged={$ref}", '--format=%(refname:short)%09%(objectname)%09%(symref)', 'refs/heads', 'refs/remotes']);

        $branches = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $sha, $symref] = array_pad(explode("\t", $line), 3, '');
            if ($symref === '' && $name !== $ref) {
                $branches[$name] = $sha;
            }
        }

        return $branches;
    }

    /**
     * Every worktree of the repository at `$path`, the primary checkout
     * included. `branch` is the short name checked out there, null when detached.
     *
     * @return list<array{path: string, branch: string|null}>
     *
     * @throws GitReaderException when `$path` is not a repository.
     */
    public function worktrees(string $path): array
    {
        $out = $this->run($path, ['worktree', 'list', '--porcelain', '-z']);

        $worktrees = [];
        $path = null;
        $branch = null;
        // Records are NUL-separated lines, each worktree ended by an empty line.
        foreach (explode("\0", $out) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $path = substr($line, 9);
                $branch = null;
            } elseif (str_starts_with($line, 'branch refs/heads/')) {
                $branch = substr($line, 18);
            } elseif ($line === '' && $path !== null) {
                $worktrees[] = ['path' => $path, 'branch' => $branch];
                $path = null;
            }
        }
        if ($path !== null) {
            $worktrees[] = ['path' => $path, 'branch' => $branch];
        }

        return $worktrees;
    }

    /**
     * Untracked files under `stories/` and `docs/mockups/` in the checkout at
     * `$path`, relative to it. GIT_OPTIONAL_LOCKS=0 keeps status from
     * refreshing the checkout's index, so this does not write either.
     *
     * @return list<string>
     *
     * @throws GitReaderException when `$path` is not a checkout.
     */
    public function untrackedStoryFiles(string $path): array
    {
        $out = $this->run($path, ['status', '--porcelain', '-z', '--untracked-files=all', '--', 'stories/', 'docs/mockups/'], timeout: 60);

        $files = [];
        foreach (explode("\0", $out) as $entry) {
            if (str_starts_with($entry, '?? ')) {
                $files[] = substr($entry, 3);
            }
        }

        return $files;
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
        $this->assertRef($ref);

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
     * Whether `$ref` is safe to hand to git as a revision (never an option).
     */
    public static function isValidRef(string $ref): bool
    {
        return preg_match(self::REF_PATTERN, $ref) === 1;
    }

    /**
     * @throws GitReaderException when `$ref` could be read by git as an option or a range.
     */
    private function assertRef(string $ref): void
    {
        if (! self::isValidRef($ref)) {
            throw new GitReaderException("invalid ref: {$ref}");
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
        // An allowed subcommand can still write through its arguments: `remote add|set-url`
        // rewrites .git/config and `--output` writes a file, so both are refused outright.
        if ($subcommand === 'remote' && count($args) > 1) {
            throw new GitReaderException('git remote is not allowed with arguments: the board only lists remotes');
        }
        // `worktree add|remove|prune` and `status` without --porcelain are not reads the board needs.
        if ($subcommand === 'worktree' && ($args[1] ?? '') !== 'list') {
            throw new GitReaderException('git worktree is not allowed except `worktree list`: the board is read-only');
        }
        if ($subcommand === 'status' && ! in_array('--porcelain', $args, true)) {
            throw new GitReaderException('git status is not allowed without --porcelain: the board is read-only');
        }
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output') || str_starts_with($arg, '-o')) {
                throw new GitReaderException("git option {$arg} is not allowed: the board is read-only");
            }
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
