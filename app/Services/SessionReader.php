<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads the metadata of live Claude Code sessions from their transcript files
 * (SB-11). A transcript is an undocumented JSONL format, so it is read
 * defensively: only files touched in the last LIVE_MINUTES, only their tail, and
 * only five fields of one line. RD-5 adds three facts, each cut down at once: the
 * newest tool call as a one-line summary (tool name plus a path or the first line
 * of a command, never its output), the newest usage record's token total and model,
 * and when the session started (the first timestamp in the file). Message text is
 * never kept, logged or returned. Reads files only: never writes, never leaves the
 * root, never runs git.
 */
class SessionReader
{
    /** A session whose file changed within this many minutes counts as live. */
    public const LIVE_MINUTES = 10;

    /** How much of a file's end is read; the newest line is always there, the history is not needed. */
    public const TAIL_BYTES = 400 * 1024;

    /** One scan serves every panel and sidebar for this long, so two open tabs cost one scan. */
    public const CACHE_SECONDS = 20;

    /** The root exists and was scanned (possibly finding nothing live). */
    public const OK = 'ok';

    /** The configured root folder does not exist. */
    public const MISSING = 'missing';

    /** There were live files and not one of them could be read. */
    public const UNAVAILABLE = 'unavailable';

    private const CACHE_KEY = 'board.sessions';

    /** How much of a file's start is read for its first timestamp (RD-5); the opening lines are small. */
    public const HEAD_BYTES = 64 * 1024;

    /** A summary longer than this is cut: it is one line on a card, not a transcript. */
    public const DOING_MAX = 120;

    /**
     * The live sessions, from a scan at most CACHE_SECONDS old.
     *
     * Side effects: logs `board.sessions_read` (debug) on each real scan,
     * `board.session_unreadable` (warning) once per unreadable file version,
     * `board.live_session_unparsed` (warning) once per file version whose usage or
     * tool record is malformed, and `board.sessions_root_missing` (warning) when the
     * root is gone.
     *
     * @return array{status: string, sessions: list<array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int, started: int|null, doing: string|null, tokens: int|null, model: string|null}>}
     */
    public function live(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->scan());
    }

    /**
     * Scan the root now: every `<root>/<folder>/<id>.jsonl` changed within LIVE_MINUTES.
     *
     * @return array{status: string, sessions: list<array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int, started: int|null, doing: string|null, tokens: int|null, model: string|null}>}
     */
    private function scan(): array
    {
        $started = hrtime(true);
        $path = (string) config('board.sessions_path');
        $root = $path === '' ? false : realpath($path);

        if ($root === false || ! is_dir($root)) {
            Log::warning('board.sessions_root_missing', ['path' => $path]);

            return ['status' => self::MISSING, 'sessions' => []];
        }

        // Transcripts sit one folder deep; subagent transcripts one level further down are not sessions.
        $files = glob($root.'/*/*.jsonl', GLOB_NOSORT) ?: [];
        $cutoff = now()->subMinutes(self::LIVE_MINUTES)->getTimestamp();
        $sessions = [];
        $live = 0;

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime < $cutoff) {
                continue;
            }
            $live++;

            $session = $this->readFile($root, $file, $mtime);
            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        // Newest activity first: the session being worked in right now leads the panel.
        usort($sessions, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        Log::debug('board.sessions_read', ['files' => count($files), 'live' => $live, 'ms' => (int) round((hrtime(true) - $started) / 1e6)]);

        return [
            // Live files existed and every one failed: say so, rather than "no live sessions".
            'status' => $live > 0 && $sessions === [] ? self::UNAVAILABLE : self::OK,
            'sessions' => $sessions,
        ];
    }

    /**
     * One live file's metadata, or null (logged) when it cannot be used.
     *
     * @return array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int, started: int|null, doing: string|null, tokens: int|null, model: string|null}|null
     */
    private function readFile(string $root, string $file, int $mtime): ?array
    {
        // A symlink could point anywhere on disk; only files that really live under the root are read.
        $real = realpath($file);
        if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return $this->unreadable($file, $mtime, 'outside_root');
        }

        $size = @filesize($real);
        $handle = @fopen($real, 'rb');
        if ($size === false || $handle === false) {
            return $this->unreadable($file, $mtime, 'unopenable');
        }

        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $tail = (string) stream_get_contents($handle);
        // The tail of a long session no longer holds its first line, so its start is read from the head.
        $head = $offset > 0 && fseek($handle, 0) === 0 ? (string) fread($handle, self::HEAD_BYTES) : $tail;
        fclose($handle);

        $lines = explode("\n", $tail);
        if ($offset > 0) {
            // The read started mid-file, so its first line is a fragment.
            array_shift($lines);
        }

        $line = $this->lastValidLine($lines);
        if ($line === null) {
            return $this->unreadable($file, $mtime, 'no_valid_line');
        }

        $branch = $line['gitBranch'] ?? null;
        $cwd = rtrim($line['cwd'], '/');
        $activity = $this->activity($lines, $cwd, $file, $mtime);

        return [
            'file' => basename($file),
            'cwd' => $cwd,
            'branch' => is_string($branch) && $branch !== '' ? $branch : null,
            'timestamp' => is_string($line['timestamp'] ?? null) ? $line['timestamp'] : null,
            'session_id' => is_string($line['sessionId'] ?? null) ? $line['sessionId'] : null,
            'version' => is_string($line['version'] ?? null) ? $line['version'] : null,
            'mtime' => $mtime,
            'started' => $this->started(explode("\n", $head)),
            ...$activity,
        ];
    }

    /**
     * What the session is doing and how full its context is (RD-5), from the newest
     * assistant lines of the tail: the newest tool call, summarised in one line, and
     * the newest usage record's token total with the model it ran on. Each is cut
     * down here, so nothing else of the message outlives this call. A record that is
     * present but malformed is skipped (null, as if absent) and logged once; a
     * session with no tool call or usage yet is simply null.
     *
     * @param  list<string>  $lines
     * @return array{doing: string|null, tokens: int|null, model: string|null}
     */
    private function activity(array $lines, string $cwd, string $file, int $mtime): array
    {
        $out = ['doing' => null, 'tokens' => null, 'model' => null];
        $toolSeen = $usageSeen = false;

        for ($i = count($lines) - 1; $i >= 0 && ! ($toolSeen && $usageSeen); $i--) {
            $text = trim($lines[$i]);
            // Cheap pre-check: most lines are tool results and user turns, and decoding each is the cost.
            if ($text === '' || $text[0] !== '{' || ! str_contains($text, '"assistant"')) {
                continue;
            }

            $decoded = json_decode($text, true);
            // A subagent's turn is not what this session's own context holds.
            if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'assistant' || ($decoded['isSidechain'] ?? false) === true
                || ! is_array($message = $decoded['message'] ?? null)) {
                continue;
            }

            if (! $usageSeen && array_key_exists('usage', $message)) {
                $usageSeen = true;
                $out['tokens'] = $this->tokens($message['usage']);
                $out['model'] = is_string($message['model'] ?? null) && $message['model'] !== '' ? $message['model'] : null;
                if ($out['tokens'] === null) {
                    $this->unparsed($file, $mtime, 'usage');
                }
            }

            if (! $toolSeen && ($block = $this->lastToolUse($message['content'] ?? null)) !== null) {
                $toolSeen = true;
                $out['doing'] = $this->summarise($block, $cwd);
                if ($out['doing'] === null) {
                    $this->unparsed($file, $mtime, 'tool');
                }
            }
        }

        return $out;
    }

    /**
     * The last `tool_use` block of a message's content, or null when it has none.
     *
     * @return array<mixed>|null
     */
    private function lastToolUse(mixed $content): ?array
    {
        if (! is_array($content)) {
            return null;
        }

        foreach (array_reverse($content) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                return $block;
            }
        }

        return null;
    }

    /**
     * The tokens a usage record says the context holds: input, cache writes, cache
     * reads and output together. Null when the record is not in the expected shape
     * (input_tokens must be a whole number; the others whole numbers or absent).
     */
    private function tokens(mixed $usage): ?int
    {
        if (! is_array($usage) || ! is_int($usage['input_tokens'] ?? null)) {
            return null;
        }

        $total = 0;
        foreach (['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens'] as $key) {
            $n = $usage[$key] ?? 0;
            if (! is_int($n) || $n < 0) {
                return null;
            }
            $total += $n;
        }

        return $total;
    }

    /**
     * One line on what a tool call does: "Reading tests/Feature/X.php", "Running
     * php artisan test". Only a path (made relative to the session's cwd), a command's
     * first line (minus a leading `cd … &&`), or a short label is used — never a
     * file's content, an edit's text or a prompt. Null when the block has no tool name.
     *
     * @param  array<mixed>  $block
     */
    private function summarise(array $block, string $cwd): ?string
    {
        $name = $block['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }

        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
        $field = fn (string $key): ?string => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
        $path = function (?string $p) use ($cwd): ?string {
            return $p !== null && str_starts_with($p, $cwd.'/') ? substr($p, strlen($cwd) + 1) : $p;
        };
        $or = fn (string $verb, ?string $what, string $fallback): string => $what === null ? $fallback : "{$verb} {$what}";

        $line = match ($name) {
            'Read' => $or('Reading', $path($field('file_path')), 'Reading a file'),
            'Edit', 'MultiEdit' => $or('Editing', $path($field('file_path')), 'Editing a file'),
            'Write' => $or('Writing', $path($field('file_path')), 'Writing a file'),
            'NotebookEdit' => $or('Editing', $path($field('notebook_path')), 'Editing a notebook'),
            'Bash' => $or('Running', $this->command($field('command')), 'Running a command'),
            'Grep', 'Glob' => $or('Searching for', $field('pattern'), 'Searching'),
            'Agent', 'Task' => $or('Running a subagent:', $field('description'), 'Running a subagent'),
            'WebFetch' => $or('Fetching', is_string($host = parse_url((string) $field('url'), PHP_URL_HOST)) ? $host : null, 'Fetching a page'),
            'WebSearch' => 'Searching the web',
            'TodoWrite' => 'Updating its to-do list',
            'Skill' => $or('Running skill', $field('skill'), 'Running a skill'),
            default => 'Using '.$name,
        };

        // Control characters would break the one line; a long path or command is cut, not wrapped.
        return mb_strimwidth((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line), 0, self::DOING_MAX, '…');
    }

    /**
     * A command's first line, without the `cd <dir> &&` an agent puts in front of
     * every call to pin its working directory: the card shows the work, not the cd.
     */
    private function command(?string $command): ?string
    {
        if ($command === null) {
            return null;
        }

        $first = trim(strtok($command, "\n") ?: '');
        $first = trim((string) preg_replace('/^cd\s+("[^"]*"|\'[^\']*\'|\S+)\s*&&\s*/', '', $first));

        return $first === '' ? null : $first;
    }

    /**
     * When the session started: the first timestamp in the file's opening lines, as
     * a Unix time. Null when none of them carries a readable one.
     *
     * @param  list<string>  $lines
     */
    private function started(array $lines): ?int
    {
        foreach ($lines as $text) {
            $text = trim($text);
            if ($text === '' || $text[0] !== '{') {
                continue;
            }

            $decoded = json_decode($text, true);
            $ts = is_array($decoded) && is_string($decoded['timestamp'] ?? null) ? strtotime($decoded['timestamp']) : false;
            if ($ts !== false) {
                return $ts;
            }
        }

        return null;
    }

    /**
     * Log, once per version of the file, that its usage or tool record could not be
     * read (RD-5): its name and which record, never its content. The card still shows,
     * with that field as "—".
     */
    private function unparsed(string $file, int $mtime, string $reason): void
    {
        if (Cache::add('board.live_session_unparsed:'.sha1($file.'|'.$mtime.'|'.$reason), true, self::LIVE_MINUTES * 60)) {
            Log::warning('board.live_session_unparsed', ['file' => basename($file), 'reason' => $reason]);
        }
    }

    /**
     * The newest line that decodes to an object with a string `cwd`, cut down at
     * once to the five kept fields (cwd, gitBranch, timestamp, sessionId, version),
     * so nothing else of the line, the message least of all, outlives this call.
     * A truncated last line (a write in progress) falls through to the one before.
     *
     * @param  list<string>  $lines
     * @return array{cwd: string, gitBranch: mixed, timestamp: mixed, sessionId: mixed, version: mixed}|null
     */
    private function lastValidLine(array $lines): ?array
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $text = trim($lines[$i]);
            if ($text === '' || $text[0] !== '{') {
                continue;
            }

            $decoded = json_decode($text, true);
            if (is_array($decoded) && is_string($decoded['cwd'] ?? null) && $decoded['cwd'] !== '') {
                return [
                    'cwd' => $decoded['cwd'],
                    'gitBranch' => $decoded['gitBranch'] ?? null,
                    'timestamp' => $decoded['timestamp'] ?? null,
                    'sessionId' => $decoded['sessionId'] ?? null,
                    'version' => $decoded['version'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Skip a file, logging its name and why (never its content) once per version
     * of the file: the panel polls every 30 s and would otherwise repeat the line.
     */
    private function unreadable(string $file, int $mtime, string $reason): null
    {
        if (Cache::add('board.session_unreadable:'.sha1($file.'|'.$mtime), true, self::LIVE_MINUTES * 60)) {
            Log::warning('board.session_unreadable', ['file' => basename($file), 'reason' => $reason]);
        }

        return null;
    }
}
