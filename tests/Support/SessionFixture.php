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
     * One assistant line as Claude Code writes it (RD-5): a message with its model,
     * its content blocks (a tool call, say) and, unless `$usage` is null, its usage
     * record. The text block carries a sentinel the board must never show.
     *
     * @param  list<array<string, mixed>>  $blocks  content blocks, e.g. `SessionFixture::tool('Read', [...])`
     * @param  array<string, mixed>|null  $usage  the usage record, or null for a line without one
     * @param  array<string, mixed>  $overrides
     */
    public static function assistant(string $cwd, ?string $branch, string $sessionId, array $blocks, ?array $usage = null, string $model = 'claude-sonnet-4-5', int $minutesAgo = 2, array $overrides = []): string
    {
        $message = [
            'id' => 'msg_'.bin2hex(random_bytes(6)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => 'ASSISTANT-SENTINEL-TEXT'], ...$blocks],
            ...($usage === null ? [] : ['usage' => $usage]),
        ];

        return self::line($cwd, $branch, $sessionId, $minutesAgo, ['type' => 'assistant', 'message' => $message, ...$overrides]);
    }

    /**
     * A `tool_use` content block, as an assistant line carries it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function tool(string $name, array $input): array
    {
        return ['type' => 'tool_use', 'id' => 'toolu_'.bin2hex(random_bytes(6)), 'name' => $name, 'input' => $input];
    }

    /**
     * A usage record whose four token counts total `$total` (mostly cache reads, as a long session's are).
     *
     * @return array<string, int>
     */
    public static function usage(int $total): array
    {
        return ['input_tokens' => 2, 'cache_creation_input_tokens' => 998, 'cache_read_input_tokens' => $total - 1500, 'output_tokens' => 500];
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
     * Write one preflight run record (RD-8, ADR-055) into `$folder/preflight-runs/<run_id>.json`,
     * as RD-10's runner will. `$record` is JSON-encoded unless it is already a string, so a
     * test can write a file that is not JSON at all. Returns its full path.
     *
     * @param  array<string, mixed>|string  $record
     */
    public function preflightRun(string $folder, string $runId, array|string $record): string
    {
        $path = "{$this->root}/{$folder}/preflight-runs/{$runId}.json";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, is_string($record) ? $record : (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

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
