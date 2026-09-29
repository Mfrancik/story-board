<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads the metadata of live Claude Code sessions from their transcript files
 * (SB-11). A transcript is an undocumented JSONL format, so it is read
 * defensively: only files touched in the last LIVE_MINUTES, only their tail, and
 * only five fields of one line. Message content is never kept, logged or returned.
 * Reads files only: never writes, never leaves the root, never runs git.
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

    /**
     * The live sessions, from a scan at most CACHE_SECONDS old.
     *
     * Side effects: logs `board.sessions_read` (debug) on each real scan,
     * `board.session_unreadable` (warning) once per unreadable file version, and
     * `board.sessions_root_missing` (warning) when the root is gone.
     *
     * @return array{status: string, sessions: list<array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int}>}
     */
    public function live(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->scan());
    }

    /**
     * Scan the root now: every `<root>/<folder>/<id>.jsonl` changed within LIVE_MINUTES.
     *
     * @return array{status: string, sessions: list<array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int}>}
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
     * @return array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int}|null
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

        return [
            'file' => basename($file),
            'cwd' => rtrim($line['cwd'], '/'),
            'branch' => is_string($branch) && $branch !== '' ? $branch : null,
            'timestamp' => is_string($line['timestamp'] ?? null) ? $line['timestamp'] : null,
            'session_id' => is_string($line['sessionId'] ?? null) ? $line['sessionId'] : null,
            'version' => is_string($line['version'] ?? null) ? $line['version'] : null,
            'mtime' => $mtime,
        ];
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
