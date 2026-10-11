<?php

namespace App\Actions\Board;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * A project's preflight run records (RD-8): one JSON file per run at
 * `<board.sessions_path>/<project folder>/preflight-runs/<run_id>.json`, in the
 * format ADR-055 fixes and RD-10's runner writes. Looks in the same folders as
 * ReadPreflightHistory (the project's own, its worktrees' and its plans folder)
 * and pairs each cost-CSV row with the record of the run it measured, so the board
 * can say where that run happened. Reads only: never writes, locks or moves a file,
 * never contacts the brain, and nothing is stored in the board's database.
 */
class ReadPreflightRuns
{
    /** The folder, inside each of the project's Claude folders, that holds the records. */
    public const DIR = 'preflight-runs';

    /** The one record format this reader knows. A newer one is skipped, not guessed at. */
    public const SCHEMA = 1;

    /** Every field a schema-1 record carries (ADR-055); a record without one is skipped. */
    public const FIELDS = ['schema', 'run_id', 'project', 'branch', 'sha', 'runner', 'host', 'mode', 'started_at', 'finished_at', 'wall_s', 'verdict', 'gates'];

    /** How the board names each runner: `local` is the owner's Mac. */
    public const RAN_ON = ['brain' => 'brain', 'local' => 'mac'];

    /** A record matches a cost row only when it finished within this many seconds of the row's `ts`. */
    public const WINDOW_S = 600;

    /**
     * @param  ReadPreflightHistory  $history  owner of the folder rule and the strict ISO date rule
     */
    public function __construct(private ReadPreflightHistory $history) {}

    /**
     * Every readable record of the project and how many files were skipped.
     *
     * Side effects: reads files under `board.sessions_path`; logs
     * `board.preflight_run_record_skipped` (warning: project, file, reason) for each
     * file that is unreadable, has an unknown `schema`, misses a field or holds a
     * value the matching cannot use. A missing root or folder is not an error: no
     * records is the normal state until RD-10 writes them.
     *
     * @return array{records: list<array{run_id: string, branch: string, ran_on: string, finished_at: CarbonImmutable, wall: int|null}>, skipped: int}
     */
    public function handle(Project $project): array
    {
        $root = rtrim((string) config('board.sessions_path'), '/');
        $records = [];
        $skipped = 0;

        foreach (array_keys($this->history->folders($root, ReadPreflightHistory::folderFor($project)) ?? []) as $folder) {
            $dir = $folder.'/'.self::DIR;
            $entries = is_dir($dir) ? (@scandir($dir) ?: []) : [];
            foreach ($entries as $entry) {
                if (! str_ends_with($entry, '.json') || ! is_file("{$dir}/{$entry}")) {
                    continue;
                }
                [$record, $reason] = $this->read("{$dir}/{$entry}");
                if ($record === null) {
                    // Counted into the page's "could not be read" notice; the file is left as it is.
                    Log::warning('board.preflight_run_record_skipped', ['project' => $project->name, 'file' => "{$dir}/{$entry}", 'reason' => $reason]);
                    $skipped++;

                    continue;
                }
                $records[] = $record;
            }
        }

        return ['records' => $records, 'skipped' => $skipped];
    }

    /**
     * Where each run ran — `brain`, `mac`, or null when no record matches — keyed
     * like `$runs`. A row and a record match when they are on the same branch and
     * the record finished within WINDOW_S of the row's `ts`. Pairs are taken
     * nearest first and each side is used once, so two runs on one branch minutes
     * apart cannot both claim the same record.
     *
     * @param  array<int, array{ts: CarbonImmutable, branch: string|null}>  $runs
     * @param  list<array{branch: string, ran_on: string, finished_at: CarbonImmutable}>  $records
     * @return array<int, string|null>
     */
    public function match(array $runs, array $records): array
    {
        $pairs = [];
        foreach ($runs as $i => $run) {
            foreach ($records as $j => $record) {
                $gap = abs($record['finished_at']->getTimestamp() - $run['ts']->getTimestamp());
                if ($run['branch'] !== null && $run['branch'] === $record['branch'] && $gap <= self::WINDOW_S) {
                    $pairs[] = [$gap, $i, $j];
                }
            }
        }
        // Nearest first; ties fall to the newer row (lower index), then the earlier-read record.
        sort($pairs);

        $ranOn = array_fill_keys(array_keys($runs), null);
        $taken = [];
        foreach ($pairs as [, $i, $j]) {
            if ($ranOn[$i] === null && ! isset($taken[$j])) {
                $ranOn[$i] = $records[$j]['ran_on'];
                $taken[$j] = true;
            }
        }

        return $ranOn;
    }

    /**
     * One record, or null and the reason it cannot be used.
     *
     * @return array{0: array{run_id: string, branch: string, ran_on: string, finished_at: CarbonImmutable, wall: int|null}|null, 1: string|null}
     */
    private function read(string $file): array
    {
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data)) {
            return [null, 'unreadable'];
        }
        // A newer schema may mean anything by its fields; reading it as 1 would be a guess.
        if (array_key_exists('schema', $data) && $data['schema'] !== self::SCHEMA) {
            return [null, 'schema'];
        }
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                return [null, "missing {$field}"];
            }
        }
        if (! is_string($data['runner']) || ! isset(self::RAN_ON[$data['runner']])) {
            return [null, 'runner'];
        }
        if (! is_string($data['branch']) || $data['branch'] === '') {
            return [null, 'branch'];
        }
        $finished = is_string($data['finished_at']) ? $this->history->timestamp($data['finished_at']) : null;
        if ($finished === null) {
            return [null, 'finished_at'];
        }

        return [[
            'run_id' => (string) (is_scalar($data['run_id']) ? $data['run_id'] : ''),
            'branch' => $data['branch'],
            'ran_on' => self::RAN_ON[$data['runner']],
            'finished_at' => $finished,
            'wall' => is_numeric($data['wall_s']) ? (int) round((float) $data['wall_s']) : null,
        ], null];
    }
}
