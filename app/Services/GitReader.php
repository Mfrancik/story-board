<?php

namespace App\Services;

use App\Exceptions\GitReaderException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
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
     * `worktree` is allowed in one read-only form, `status` only as --porcelain listings and
     * `log` in three fixed forms (see run()).
     */
    private const ALLOWED = ['fetch', 'ls-tree', 'show', 'rev-parse', 'cat-file', 'remote', 'for-each-ref', 'worktree', 'status', 'merge-base', 'log'];

    /**
     * The one format `log` may print (RD-7): SHA, committer time and subject, unit-separated.
     * Fixed so no caller can ask for a patch, a body or anything git could expand further.
     */
    public const LOG_FORMAT = '--format=%H%x1f%ct%x1f%s';

    /** The one `--since` form `log` takes: an absolute unix time, never git's free-text dates. */
    private const LOG_SINCE = '/^--since=@\d+$/D';

    /**
     * The format of the `--shortstat` log (RD-12's Last edits): a record separator, then SHA,
     * committer time and subject; git appends each commit's one-line stat after it.
     */
    public const STAT_FORMAT = '--format=%x1e%H%x1f%ct%x1f%s';

    /** The format of the `--name-only` log (RD-12's most-edited files): a record separator and the SHA. */
    public const FILES_FORMAT = '--format=%x1e%H';

    /** How many commits the `--shortstat` log may ask for: 1–99, so no caller can stat a whole history. */
    private const STAT_MAX_COUNT = '/^--max-count=[1-9]\d?$/D';

    /**
     * The options `status` may take before `--`. `-v`/`--verbose` would print the staged diff —
     * file contents — and nothing else a caller needs changes what status reads.
     */
    private const STATUS_OPTIONS = ['--porcelain', '-z', '--untracked-files=all', '--untracked-files=normal'];

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
        $remote = explode('/', $ref, 2)[0];
        $args = in_array($remote, $this->remotes($path), true) ? [$remote] : [];

        // Auto-gc/maintenance would rewrite pack files in the project's .git; not ours to do.
        $this->run($path, ['fetch', '--quiet', '--no-tags', ...$args], timeout: 60, config: [
            'gc.auto=0', 'maintenance.auto=false',
        ]);
    }

    /**
     * The checkout's remote names (`origin`, `upstream`, …), empty when it has none.
     *
     * @return list<string>
     *
     * @throws GitReaderException when `$path` is not a repository.
     */
    public function remotes(string $path): array
    {
        return preg_split('/\s+/', trim($this->run($path, ['remote'])), flags: PREG_SPLIT_NO_EMPTY) ?: [];
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
     * The raw bytes of several files at `$ref` from one `git cat-file --batch`
     * process, as path => bytes; a path that is not a file at the ref maps to
     * null. One process instead of one `show` per file: the mockup gallery
     * (SB-21) reads every set's story, and coins alone has 263 of them.
     *
     * @param  list<string>  $files
     * @return array<string, string|null>
     *
     * @throws GitReaderException when git fails or a path could break the batch protocol.
     */
    public function showMany(string $path, string $ref, array $files): array
    {
        $this->assertRef($ref);
        if ($files === []) {
            return [];
        }
        foreach ($files as $file) {
            // One request per line: a newline in a path would smuggle in a second object name.
            if (str_contains($file, "\n") || str_contains($file, "\r")) {
                throw $this->refuse('a path in a batch read contains a newline', ['cat-file']);
            }
        }

        $out = $this->run($path, ['cat-file', '--batch'], input: implode('', array_map(fn ($f) => "{$ref}:{$f}\n", $files)));

        $read = [];
        $offset = 0;
        foreach ($files as $file) {
            $end = strpos($out, "\n", $offset);
            if ($end === false) {
                $read[$file] = null;

                continue;
            }
            $header = substr($out, $offset, $end - $offset);
            $offset = $end + 1;
            // `<sha> <type> <size>` then the bytes and a newline; anything else (`<name> missing`) has no body.
            if (! preg_match('/^[0-9a-f]+ (\w+) (\d+)$/', $header, $m)) {
                $read[$file] = null;

                continue;
            }
            $read[$file] = $m[1] === 'blob' ? substr($out, $offset, (int) $m[2]) : null;
            $offset += (int) $m[2] + 1;
        }

        return $read;
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
        // A checkout's own config could name an fsmonitor program that status would run;
        // the board runs git, never a project's configured helpers.
        $out = $this->run($path, ['status', '--porcelain', '-z', '--untracked-files=all', '--', 'stories/', 'docs/mockups/'], timeout: 60, config: [
            'core.fsmonitor=false', 'core.untrackedCache=false',
        ]);

        $files = [];
        foreach (explode("\0", $out) as $entry) {
            if (str_starts_with($entry, '?? ')) {
                $files[] = substr($entry, 3);
            }
        }

        return $files;
    }

    /**
     * The commits reachable from `$ref` whose committer date is after `$since`, newest
     * first, as SHA, committer time and subject line (RD-7's Shipped this week). Only
     * the fixed LOG_FORMAT, `--since` and the ref reach git; run() refuses any other form.
     *
     * @return list<array{sha: string, at: CarbonImmutable, subject: string}>
     *
     * @throws GitReaderException when the ref does not resolve or `$path` is not a repository.
     */
    public function log(string $path, string $ref, DateTimeInterface $since): array
    {
        $this->assertRef($ref);
        // A project's own config could ask log to verify signatures, which runs gpg; the board runs git only.
        $out = $this->run($path, ['log', self::LOG_FORMAT, '--since=@'.$since->getTimestamp(), $ref, '--'], config: ['log.showSignature=false']);

        $commits = [];
        foreach (explode("\n", $out) as $line) {
            $parts = explode("\x1f", $line, 3);
            // A line that is not `<sha>␟<time>␟<subject>` (the trailing empty one) is not a commit.
            if (count($parts) === 3 && preg_match('/^[0-9a-f]{40,64}$/D', $parts[0]) && ctype_digit($parts[1])) {
                $commits[] = ['sha' => $parts[0], 'at' => CarbonImmutable::createFromTimestamp((int) $parts[1]), 'subject' => $parts[2]];
            }
        }

        return $commits;
    }

    /**
     * Commits reachable from `$ref` per day over the last `$days` days, today included, as
     * `Y-m-d` => count, oldest first, every day present (a day with no commits is a zero).
     * Days are calendar days in `$timezone`; a commit is counted on its committer date, the
     * date `--since` walks by. Reads through log()'s fixed form, so it adds no new git form.
     *
     * @return array<string, int>
     *
     * @throws GitReaderException when the ref does not resolve or `$path` is not a repository.
     */
    public function commitsPerDay(string $path, string $ref, int $days, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $first = $today->subDays($days - 1);

        $counts = [];
        for ($day = $first; $day->lte($today); $day = $day->addDay()) {
            $counts[$day->toDateString()] = 0;
        }
        foreach ($this->log($path, $ref, $first) as $commit) {
            $key = $commit['at']->setTimezone($timezone)->toDateString();
            // A commit dated after today (a skewed clock) is outside the window, not a new day.
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * The latest `$count` commits on `$ref`, newest first, each with its lines added and
     * removed from `git log --shortstat` — counts only, never a patch. A merge has no stat
     * line, so its counts are null. `--no-textconv` and `--no-ext-diff` stop a project's
     * own diff helpers from running; run() refuses any other form of this log.
     *
     * @return list<array{sha: string, at: CarbonImmutable, subject: string, added: int|null, removed: int|null}>
     *
     * @throws GitReaderException when the ref does not resolve, `$path` is not a repository, or `$count` is outside 1–99.
     */
    public function commitStats(string $path, string $ref, int $count): array
    {
        $this->assertRef($ref);
        $out = $this->run($path, ['log', self::STAT_FORMAT, '--shortstat', '--no-ext-diff', '--no-textconv', '--max-count='.$count, $ref, '--'], config: ['log.showSignature=false']);

        $commits = [];
        foreach (explode("\x1e", $out) as $record) {
            $lines = explode("\n", trim($record, "\n"), 2);
            $parts = explode("\x1f", $lines[0], 3);
            // Text before the first separator, or a malformed record, is not a commit.
            if (count($parts) !== 3 || preg_match('/^[0-9a-f]{40,64}$/D', $parts[0]) !== 1 || ! ctype_digit($parts[1])) {
                continue;
            }
            $stat = trim($lines[1] ?? '');
            $commits[] = [
                'sha' => $parts[0],
                'at' => CarbonImmutable::createFromTimestamp((int) $parts[1]),
                'subject' => $parts[2],
                // `N files changed, X insertions(+), Y deletions(-)`; git leaves out a zero side.
                'added' => $stat === '' ? null : (preg_match('/(\d+) insertions?\(\+\)/', $stat, $a) === 1 ? (int) $a[1] : 0),
                'removed' => $stat === '' ? null : (preg_match('/(\d+) deletions?\(-\)/', $stat, $d) === 1 ? (int) $d[1] : 0),
            ];
        }

        return $commits;
    }

    /**
     * Every file changed by a commit on `$ref` since `$since`, as path => the number of
     * commits that touched it, most first (ties A–Z). Names only (`--name-only`), never
     * contents; merges list no files, so a change is not counted twice. `--no-renames`
     * keeps a rename as its new path. run() refuses any other form of this log.
     *
     * @return array<string, int>
     *
     * @throws GitReaderException when the ref does not resolve or `$path` is not a repository.
     */
    public function filesChangedSince(string $path, string $ref, DateTimeInterface $since): array
    {
        $this->assertRef($ref);
        $out = $this->run($path, ['log', '-z', self::FILES_FORMAT, '--name-only', '--no-renames', '--since=@'.$since->getTimestamp(), $ref, '--'], config: ['log.showSignature=false']);

        $counts = [];
        // Each record: `<sha>\0\n<path>\0<path>\0…`; -z keeps any path byte-exact.
        foreach (explode("\x1e", $out) as $record) {
            $entries = explode("\0", $record);
            array_shift($entries);
            foreach (array_unique(array_filter(array_map(fn ($e) => ltrim($e, "\n"), $entries), fn ($e) => $e !== '')) as $file) {
                $counts[$file] = ($counts[$file] ?? 0) + 1;
            }
        }
        uksort($counts, fn ($a, $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }

    /**
     * How many paths in the checkout at `$path` are uncommitted — modified, staged, deleted
     * or untracked (an untracked directory counts once, as git shows it). Counted from
     * `status --porcelain -z`; no path or content leaves this method.
     *
     * @throws GitReaderException when `$path` is not a checkout.
     */
    public function uncommittedCount(string $path): int
    {
        // As untrackedStoryFiles(): never run a project's configured fsmonitor helper.
        $out = $this->run($path, ['status', '--porcelain', '-z', '--untracked-files=normal'], timeout: 60, config: [
            'core.fsmonitor=false', 'core.untrackedCache=false',
        ]);

        $entries = explode("\0", $out);
        $count = 0;
        for ($i = 0; $i < count($entries); $i++) {
            if (strlen($entries[$i]) < 4) {
                continue;
            }
            $count++;
            // A rename or copy is followed by its old path as a separate entry: one path, not two.
            if (str_contains('RC', $entries[$i][0]) || str_contains('RC', $entries[$i][1])) {
                $i++;
            }
        }

        return $count;
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
            throw $this->refuse("invalid ref: {$ref}", []);
        }
    }

    /**
     * A refused command is the read-only rule doing its job — worth a warning,
     * since nothing in the board should ever ask for one.
     *
     * @param  list<string>  $args
     */
    private function refuse(string $reason, array $args): GitReaderException
    {
        // Facade may be unbound in a bare unit test; the refusal must still throw.
        if (function_exists('app') && app()->bound('log')) {
            Log::warning('board.git_refused', ['reason' => $reason, 'subcommand' => $args[0] ?? null]);
        }

        return new GitReaderException($reason);
    }

    /**
     * Whether `$args` is one of the three fixed `log` forms the board runs, and nothing else:
     * - log(): `log`, LOG_FORMAT, `--since=@<unix>`, a valid ref, `--` (RD-7);
     * - commitStats(): `log`, STAT_FORMAT, `--shortstat`, `--no-ext-diff`, `--no-textconv`,
     *   `--max-count=<1–99>`, a valid ref, `--` (RD-12) — counts per commit, never a patch;
     * - filesChangedSince(): `log`, `-z`, FILES_FORMAT, `--name-only`, `--no-renames`,
     *   `--since=@<unix>`, a valid ref, `--` (RD-12) — file names, never contents.
     *
     * @param  list<string>  $args
     */
    private function isReadOnlyLog(array $args): bool
    {
        $subjects = count($args) === 5
            && $args[1] === self::LOG_FORMAT
            && preg_match(self::LOG_SINCE, $args[2]) === 1
            && self::isValidRef($args[3])
            && $args[4] === '--';

        // --no-textconv matters: git log runs a project's textconv filters by default, and --shortstat diffs.
        $stats = count($args) === 8
            && array_slice($args, 1, 4) === [self::STAT_FORMAT, '--shortstat', '--no-ext-diff', '--no-textconv']
            && preg_match(self::STAT_MAX_COUNT, $args[5]) === 1
            && self::isValidRef($args[6])
            && $args[7] === '--';

        $files = count($args) === 8
            && array_slice($args, 1, 4) === ['-z', self::FILES_FORMAT, '--name-only', '--no-renames']
            && preg_match(self::LOG_SINCE, $args[5]) === 1
            && self::isValidRef($args[6])
            && $args[7] === '--';

        return $subjects || $stats || $files;
    }

    /**
     * Whether `$args` is a read-only `status`: `--porcelain`, then only STATUS_OPTIONS before
     * an optional `--` and the paths after it — so never `-v`, which prints the diff.
     *
     * @param  list<string>  $args
     */
    private function isReadOnlyStatus(array $args): bool
    {
        $separator = array_search('--', $args, true);
        $options = array_slice($args, 1, $separator === false ? null : $separator - 1);

        return in_array('--porcelain', $options, true)
            && array_diff($options, self::STATUS_OPTIONS) === [];
    }

    /**
     * Run one allow-listed git subcommand in `$path` and return its stdout.
     *
     * @param  list<string>  $args  the subcommand first, then its arguments
     * @param  list<string>  $config  `-c key=value` settings the reader itself imposes
     * @param  string|null  $input  stdin for the command (`cat-file --batch` object names)
     *
     * @throws GitReaderException when the subcommand is not allowed, fails, or times out.
     */
    public function run(string $path, array $args, int $timeout = 30, array $config = [], ?string $input = null): string
    {
        $subcommand = $args[0] ?? '';
        if (! in_array($subcommand, self::ALLOWED, true)) {
            throw $this->refuse("git {$subcommand} is not allowed: the board is read-only", $args);
        }
        // An allowed subcommand can still write through its arguments: `remote add|set-url`
        // rewrites .git/config and `--output` writes a file, so both are refused outright.
        if ($subcommand === 'remote' && count($args) > 1) {
            throw $this->refuse('git remote is not allowed with arguments: the board only lists remotes', $args);
        }
        // `worktree add|remove|prune` and `status` without --porcelain are not reads the board needs.
        if ($subcommand === 'worktree' && ($args[1] ?? '') !== 'list') {
            throw $this->refuse('git worktree is not allowed except `worktree list`: the board is read-only', $args);
        }
        // `status` lists paths, never contents: --porcelain and a few listing options only (RD-12).
        if ($subcommand === 'status' && ! $this->isReadOnlyStatus($args)) {
            throw $this->refuse('git status is not allowed except --porcelain with listing options: the board is read-only', $args);
        }
        // `log` takes one of three fixed forms (isReadOnlyLog): no patch, no other refs, no paths,
        // no free-text dates, and nothing git could read as a writing option.
        if ($subcommand === 'log' && ! $this->isReadOnlyLog($args)) {
            throw $this->refuse('git log is not allowed except its fixed read-only forms: the board is read-only', $args);
        }
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output') || str_starts_with($arg, '-o')) {
                throw $this->refuse("git option {$arg} is not allowed: the board is read-only", $args);
            }
        }

        $command = ['git', '-C', $path];
        foreach ($config as $setting) {
            array_push($command, '-c', $setting);
        }

        try {
            $process = Process::env(self::ENV)->timeout($timeout);
            $result = ($input === null ? $process : $process->input($input))->run([...$command, ...$args]);
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
