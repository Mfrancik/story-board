<?php

namespace App\Actions\Board;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A project's preflight runs, read from the `preflight-cost.csv` files that
 * `bin/preflight-meter.py report` leaves under the Claude projects root (SB-16):
 * the project's own folder, each of its `.claude/worktrees/<name>` folders and its
 * `.claude/plans` folder. Read on every call, by header name, so both CSV shapes
 * (with and without a `project` column) work. Reads only: never writes, locks or
 * moves a CSV, and nothing is stored in the board's database.
 */
class ReadPreflightHistory
{
    /** The file the meter appends one row per run to. */
    public const FILE = 'preflight-cost.csv';

    /** How a checkout's own folder is named: its path with every non-alphanumeric character turned into a dash. */
    private const ENCODE = '/[^A-Za-z0-9]/';

    /** `<project>/.claude/worktrees/<name>`, encoded. */
    private const WORKTREES = '--claude-worktrees-';

    /** `<project>/.claude/plans`, encoded. */
    private const PLANS = '--claude-plans';

    /** The `where` of a run made in the project's own checkout. */
    public const MAIN = 'main checkout';

    /** The `where` of a run made from the project's `.claude/plans` folder. */
    public const PLANS_WHERE = 'plans';

    /** The audit tier CLAUDE.md pins the audit to; any other named tier is flagged. */
    public const PINNED_TIER = 'sonnet';

    /** An ISO-8601 UTC or offset timestamp, as the meter writes `ts`. Anything looser (`yesterday`) is not a date. */
    private const TS = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

    /** The trend strip compares this many days with the same span before it. */
    public const WINDOW_DAYS = 30;

    /**
     * Every readable run of the project, newest first, with the trend figures
     * and where the reader looked.
     *
     * Side effects: reads files under `board.sessions_path`; logs
     * `board.preflight_history_unreadable` (warning) for the missing root and for
     * each CSV that cannot be opened or has no `ts` column.
     *
     * @return array{
     *     runs: list<array{ts: CarbonImmutable, branch: string|null, where: string, mode: string|null, wall: int|null, turns: int|null, tools: int|null, tokens: int|null, tokens_in: int|null, tokens_out: int|null, share: int|null, pack: int|null, tier: string|null, flagged: bool}>,
     *     skipped: int,
     *     looked: list<string>,
     *     trend: array{current: array{runs: int, wall: float|null, tokens: float|null}, previous: array{runs: int, wall: float|null, tokens: float|null}},
     *     now: CarbonImmutable,
     * }
     */
    public function handle(Project $project): array
    {
        $root = rtrim((string) config('board.sessions_path'), '/');
        $folder = (string) preg_replace(self::ENCODE, '-', rtrim($project->path, '/'));
        $now = CarbonImmutable::now();

        $runs = [];
        $skipped = 0;
        foreach ($this->files($project, $root, $folder) as $file => $where) {
            [$fileRuns, $fileSkipped] = $this->read($project, $file, $where);
            array_push($runs, ...$fileRuns);
            $skipped += $fileSkipped;
        }

        // Newest first; a stable sort keeps same-second runs in file order.
        usort($runs, fn (array $a, array $b) => $b['ts'] <=> $a['ts']);

        return [
            'runs' => $runs,
            'skipped' => $skipped,
            'looked' => [
                "{$root}/{$folder}/".self::FILE,
                "{$root}/{$folder}".self::WORKTREES.'*/'.self::FILE,
                "{$root}/{$folder}".self::PLANS.'/'.self::FILE,
            ],
            'trend' => [
                'current' => $this->figures($runs, $now->subDays(self::WINDOW_DAYS), $now),
                'previous' => $this->figures($runs, $now->subDays(2 * self::WINDOW_DAYS), $now->subDays(self::WINDOW_DAYS)),
            ],
            'now' => $now,
        ];
    }

    /**
     * Wall time as `m:ss` (1223 s is `20:23`), or "—".
     */
    public static function wall(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $seconds = (int) round($seconds);

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A token count, compact: `2.6M`, `517k`, `940`, or "—".
     */
    public static function tokens(int|float|null $n): string
    {
        return match (true) {
            $n === null => '—',
            $n >= 1_000_000 => preg_replace('/\.0$/', '', number_format($n / 1_000_000, 1, '.', '')).'M',
            $n >= 1_000 => round($n / 1_000).'k',
            default => (string) round($n),
        };
    }

    /**
     * An audit pack's size in KB: one decimal under 10 KB (`0.1 KB`), whole above, or "—".
     */
    public static function pack(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $kb = $bytes / 1024;

        return ($kb > 0 && $kb < 10 ? number_format($kb, 1, '.', '') : (string) round($kb)).' KB';
    }

    /**
     * Each CSV that belongs to the project, with the `where` label its runs get.
     * A folder is the project's when it is the encoded path itself, or that path
     * followed by a worktree or the plans suffix — so `story-board-x` is not
     * `story-board`. Compared case-insensitively: macOS paths are, and a project
     * registered as `~/code/x` must still find the `-Code-x` folder Claude made.
     *
     * @return array<string, string> file => where
     */
    private function files(Project $project, string $root, string $folder): array
    {
        $entries = is_dir($root) ? @scandir($root) : false;
        if ($entries === false) {
            Log::warning('board.preflight_history_unreadable', ['project' => $project->name, 'file' => $root]);

            return [];
        }

        $own = strtolower($folder);
        $files = [];
        foreach ($entries as $entry) {
            $name = strtolower($entry);
            $where = match (true) {
                $name === $own => self::MAIN,
                $name === $own.self::PLANS => self::PLANS_WHERE,
                str_starts_with($name, $own.self::WORKTREES) && strlen($name) > strlen($own.self::WORKTREES) => substr($entry, strlen($own.self::WORKTREES)),
                default => null,
            };
            $file = "{$root}/{$entry}/".self::FILE;
            if ($where !== null && is_file($file)) {
                $files[$file] = $where;
            }
        }

        return $files;
    }

    /**
     * The runs in one CSV and how many of its rows could not be read. A row with
     * the wrong number of cells or a `ts` that is not an ISO date is skipped and
     * counted, not guessed at; a file with no `ts` column is not a cost CSV at all.
     *
     * @return array{0: list<array{ts: CarbonImmutable, branch: string|null, where: string, mode: string|null, wall: int|null, turns: int|null, tools: int|null, tokens: int|null, tokens_in: int|null, tokens_out: int|null, share: int|null, pack: int|null, tier: string|null, flagged: bool}>, 1: int}
     */
    private function read(Project $project, string $file, string $where): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = $lines === false || $lines === [] ? [] : array_map(fn (?string $name) => trim((string) $name), str_getcsv((string) array_shift($lines), ',', '"', ''));
        if ($lines === false || ! in_array('ts', $header, true)) {
            Log::warning('board.preflight_history_unreadable', ['project' => $project->name, 'file' => $file]);

            return [[], 0];
        }

        $runs = [];
        $skipped = 0;
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            $row = count($cells) === count($header) ? array_combine($header, $cells) : null;
            $ts = $row === null ? null : $this->timestamp($row['ts']);
            if ($row === null || $ts === null) {
                // Counted and shown as "N rows could not be read"; the count is logged with the page view.
                $skipped++;

                continue;
            }

            $runs[] = $this->run($row, $ts, $where);
        }

        return [$runs, $skipped];
    }

    /**
     * One run from a row keyed by header name. A column the file does not have and
     * an empty cell both read as null ("—").
     *
     * @param  array<string, string|null>  $row
     * @return array{ts: CarbonImmutable, branch: string|null, where: string, mode: string|null, wall: int|null, turns: int|null, tools: int|null, tokens: int|null, tokens_in: int|null, tokens_out: int|null, share: int|null, pack: int|null, tier: string|null, flagged: bool}
     */
    private function run(array $row, CarbonImmutable $ts, string $where): array
    {
        $text = fn (string $col): ?string => ($v = trim((string) ($row[$col] ?? ''))) === '' ? null : $v;
        $int = fn (string $col): ?int => is_numeric($v = $text($col)) ? (int) round((float) $v) : null;

        $in = $int('tokens_in');
        $out = $int('tokens_out');
        $tokens = $in === null && $out === null ? null : ($in ?? 0) + ($out ?? 0);
        $sub = $int('subagent_tokens');
        $tier = $text('audit_model');
        // The meter writes `?` when it cannot tell scoped from full: as unknown as an empty cell.
        $mode = $text('mode') === '?' ? null : $text('mode');

        return [
            'ts' => $ts,
            'branch' => $text('branch'),
            'where' => $where,
            'mode' => $mode,
            'wall' => $int('wall_s'),
            'turns' => $int('turns'),
            'tools' => $int('tool_calls'),
            'tokens' => $tokens,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'share' => $sub === null || ! $tokens ? null : (int) round($sub / $tokens * 100),
            'pack' => $int('pack_bytes'),
            'tier' => $tier,
            // "Check", not "wrong": the tier label itself may be off (F-3).
            'flagged' => $tier !== null && $tier !== self::PINNED_TIER,
        ];
    }

    /**
     * The row's `ts` as a date, or null when it is not an ISO timestamp.
     */
    private function timestamp(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if (preg_match(self::TS, $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // Shaped like a date but not one (month 13): skipped like any unreadable row.
            return null;
        }
    }

    /**
     * Runs, median wall time and median tokens of the runs in (from, to].
     *
     * @param  list<array{ts: CarbonImmutable, wall: int|null, tokens: int|null}>  $runs
     * @return array{runs: int, wall: float|null, tokens: float|null}
     */
    private function figures(array $runs, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $in = array_filter($runs, fn (array $r) => $r['ts']->greaterThan($from) && $r['ts']->lessThanOrEqualTo($to));

        return [
            'runs' => count($in),
            'wall' => $this->median(array_column($in, 'wall')),
            'tokens' => $this->median(array_column($in, 'tokens')),
        ];
    }

    /**
     * The median of the non-null values, or null when there are none.
     *
     * @param  array<int|null>  $values
     */
    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
