<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A throwaway Claude Code transcripts root, laid out like `~/.claude/projects`:
 * one folder per working directory holding `<sessionId>.jsonl` files. The lines
 * are hand-written in the shape Claude Code 2.1.x writes, including a message
 * body, so tests can prove the board never shows one. Never the real `~/.claude`.
 */
class SessionFixture
{
    /** The transcripts root the board is pointed at (`board.sessions_path`). */
    public readonly string $root;

    /**
     * Create an empty root in the system temp folder.
     */
    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/story-board-sessions/'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root);
    }

    /**
     * One transcript line as Claude Code writes it: metadata the board keeps, plus
     * fields (the message, a request ID) it must never read out.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function line(string $cwd, ?string $branch, string $sessionId, int $minutesAgo = 2, array $overrides = []): string
    {
        $line = [
            'parentUuid' => null,
            'isSidechain' => false,
            'type' => 'user',
            'message' => ['role' => 'user', 'content' => 'Please build the story.'],
            'uuid' => bin2hex(random_bytes(8)),
            'timestamp' => now()->subMinutes($minutesAgo)->toIso8601ZuluString('millisecond'),
            'userType' => 'external',
            'cwd' => $cwd,
            'sessionId' => $sessionId,
            'version' => '2.1.284',
            ...($branch === null ? [] : ['gitBranch' => $branch]),
            ...$overrides,
        ];

        return (string) json_encode($line, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Write one session file whose mtime is `$minutesAgo` minutes in the past.
     * Returns its full path.
     *
     * @param  list<string>  $lines  raw lines, joined with newlines (a trailing newline is added unless `$raw`)
     */
    public function file(string $folder, string $sessionId, array $lines, int $minutesAgo = 2, bool $raw = false): string
    {
        $path = "{$this->root}/{$folder}/{$sessionId}.jsonl";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines).($raw ? '' : "\n"));
        touch($path, now()->subMinutes($minutesAgo)->getTimestamp());

        return $path;
    }

    /**
     * The usual case: a live session in `$cwd` on `$branch`, one line per file.
     */
    public function session(string $cwd, ?string $branch, string $sessionId, int $minutesAgo = 2): string
    {
        return $this->file(self::folderFor($cwd), $sessionId, [self::line($cwd, $branch, $sessionId, $minutesAgo)], $minutesAgo);
    }

    /**
     * The folder name Claude Code files a working directory under: its path with
     * every non-alphanumeric character turned into a dash.
     */
    public static function folderFor(string $cwd): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '-', $cwd);
    }

    /**
     * Write a `preflight-cost.csv` into `$folder` (SB-16), as `bin/preflight-meter.py report`
     * leaves it next to a checkout's transcripts. Returns its full path.
     *
     * @param  list<string>  $lines  the header line, then one line per run
     */
    public function preflightCsv(string $folder, array $lines): string
    {
        $path = "{$this->root}/{$folder}/preflight-cost.csv";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * Remove the root and everything in it.
     */
    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }
}
