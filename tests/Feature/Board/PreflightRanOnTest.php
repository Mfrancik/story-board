<?php

use App\Actions\Board\ListProjectTiles;
use App\Actions\Board\ReadPreflightRuns;
use App\Livewire\Board\Overview;
use App\Models\Project;
use App\Services\ProjectFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\SessionFixture;

/**
 * RD-8 acceptance criteria: the Preflight page and the home tile say where each run happened. Run records
 * (ADR-055) are JSON files in `<sessions_path>/<project folder>/preflight-runs/`; a cost-CSV row is matched to
 * the record on its branch that finished nearest its `ts`, within 10 minutes. Fixtures live in a temp root,
 * never the real ~/.claude. The no-request half of the filter is in tests/Browser/PreflightRanOnTest.php.
 */
const RAN_ON_HEADER = 'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';

beforeEach(function () {
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-09-29T12:00:00Z'));
    $this->claude = new SessionFixture;
    config(['board.sessions_path' => $this->claude->root]);

    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->board = Project::factory()->create(['name' => 'story-board', 'path' => '/Users/me/Code/story-board', ...$fresh]);
    $this->folder = SessionFixture::folderFor('/Users/me/Code/story-board');
});

afterEach(function () {
    $this->claude->destroy();
});

/**
 * A complete schema-1 run record, with `$overrides` merged in and the keys in `$without` removed.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $without
 * @return array<string, mixed>
 */
function ranOnRecord(array $overrides = [], array $without = []): array
{
    $record = [
        'schema' => 1, 'run_id' => 'run-1', 'project' => 'story-board', 'branch' => 'main', 'sha' => str_repeat('a', 40),
        'runner' => 'brain', 'host' => 'brain.local', 'mode' => 'scoped',
        'started_at' => '2026-09-28T09:56:00Z', 'finished_at' => '2026-09-28T10:04:00Z', 'wall_s' => 480, 'verdict' => 'GO',
        'gates' => [['name' => 'pint', 'result' => 'pass', 'seconds' => 3], ['name' => 'pest', 'result' => 'pass', 'seconds' => 210]],
        ...$overrides,
    ];

    return array_diff_key($record, array_flip($without));
}

/**
 * One CSV row on `$branch` at `$ts` taking `$wall` seconds.
 */
function ranOnRow(string $ts, string $branch = 'main', int $wall = 500): string
{
    return "{$ts},story-board,{$branch},scoped,{$wall},4,4,1000,10,0,0,sonnet";
}

/**
 * The Ran on cell of every row, in page order.
 *
 * @return list<string>
 */
function ranOnCells(string $html): array
{
    preg_match_all('/<td\b[^>]*data-col="ran_on"[^>]*>(.*?)<\/td>/s', $html, $m);

    return array_map(fn (string $c) => trim(html_entity_decode(strip_tags($c))), $m[1]);
}

/**
 * The count shown on one Where it ran filter button.
 */
function ranOnFilterCount(string $html, string $value): ?string
{
    return preg_match('/data-ran-on-filter="'.$value.'"[^>]*>.*?<span[^>]*>(\d+)<\/span>/s', $html, $m) ? $m[1] : null;
}

it('Given no preflight-runs/ folder, then the Preflight page renders as today with every Ran on cell "—", and the filter shows "Unknown" with every run', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z'), ranOnRow('2026-09-27T10:00:00Z')]);

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    expect(ranOnCells($html))->toBe(['—', '—'])
        ->and(ranOnFilterCount($html, 'all'))->toBe('2')
        ->and(ranOnFilterCount($html, 'unknown'))->toBe('2')
        ->and(ranOnFilterCount($html, 'brain'))->toBe('0')
        ->and(ranOnFilterCount($html, 'mac'))->toBe('0')
        ->and($html)->not->toContain('data-skipped')
        ->and($html)->not->toContain('data-runner-wall');
});

it('Given a record with runner: brain on the same branch, finishing 4 minutes after a cost row, then that row reads "Brain"', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z'), ranOnRow('2026-09-27T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['finished_at' => '2026-09-28T10:04:00Z']));

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    expect(ranOnCells($html))->toBe(['Brain', '—'])
        ->and(ranOnFilterCount($html, 'brain'))->toBe('1')
        ->and(ranOnFilterCount($html, 'unknown'))->toBe('1');
});

it('Given a record with runner: local, then the matched row reads "Mac"', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['runner' => 'local', 'host' => 'mikes-mac']));

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    expect(ranOnCells($html))->toBe(['Mac'])
        ->and(ranOnFilterCount($html, 'mac'))->toBe('1');
});

it('Given a record 25 minutes away from any cost row, then no row matches it and every row stays "—"', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z'), ranOnRow('2026-09-28T11:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['finished_at' => '2026-09-28T10:25:00Z']));

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    expect(ranOnCells($html))->toBe(['—', '—']);
});

it('Given a record on another branch 2 minutes from a cost row, then the row is not matched', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z', 'main')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['branch' => 'feat/RD-8-x', 'finished_at' => '2026-09-28T10:02:00Z']));

    expect(ranOnCells($this->get('/p/story-board/preflight')->assertOk()->getContent()))->toBe(['—']);
});

it('Given two records near one row, then the nearest wins and the other is free for the row it is nearest to', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:06:00Z'), ranOnRow('2026-09-28T10:00:00Z')]);
    // 10:03 (mac) is 3 min from 10:00 and 10:06; 10:05 (brain) is 1 min from 10:06: brain takes 10:06, mac 10:00.
    $this->claude->preflightRun($this->folder, 'a', ranOnRecord(['run_id' => 'a', 'runner' => 'local', 'finished_at' => '2026-09-28T10:03:00Z']));
    $this->claude->preflightRun($this->folder, 'b', ranOnRecord(['run_id' => 'b', 'runner' => 'brain', 'finished_at' => '2026-09-28T10:05:00Z']));

    expect(ranOnCells($this->get('/p/story-board/preflight')->assertOk()->getContent()))->toBe(['Brain', 'Mac']);
});

it('Given a record with schema: 2 or no runner field, then it is skipped, counted in the "could not be read" notice, and logged', function (array $record, string $reason) {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z')]);
    $file = $this->claude->preflightRun($this->folder, 'run-1', $record);
    Log::spy();

    $response = $this->get('/p/story-board/preflight')->assertOk();

    expect(ranOnCells($response->getContent()))->toBe(['—']);
    $response->assertSeeHtml('data-skipped')->assertSee('1 run record could not be read');
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.preflight_run_record_skipped'
        && $c === ['project' => 'story-board', 'file' => $file, 'reason' => $reason])->once();
})->with([
    'schema 2' => [ranOnRecord(['schema' => 2]), 'schema'],
    'no runner' => [ranOnRecord(without: ['runner']), 'missing runner'],
    // A field the matching never reads is still required: the format is the contract RD-10 writes to (ADR-055).
    'no gates' => [ranOnRecord(without: ['gates']), 'missing gates'],
]);

it('skips and counts a record that is not JSON, has an unknown runner or a finished_at that is not an ISO date', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'a', '{not json');
    $this->claude->preflightRun($this->folder, 'b', ranOnRecord(['runner' => 'cloud']));
    $this->claude->preflightRun($this->folder, 'c', ranOnRecord(['finished_at' => 'yesterday']));
    $this->claude->preflightRun($this->folder, 'd', ranOnRecord(['branch' => null]));
    Log::spy();

    $read = app(ReadPreflightRuns::class)->handle($this->board);

    expect($read['records'])->toBe([])->and($read['skipped'])->toBe(4);
    foreach (['unreadable', 'runner', 'finished_at', 'branch'] as $reason) {
        Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.preflight_run_record_skipped' && $c['reason'] === $reason)->once();
    }
});

it('reads records from the project\'s worktree folders too, and none from a look-alike project\'s folder', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z'), ranOnRow('2026-09-27T10:00:00Z')]);
    $this->claude->preflightRun($this->folder.'--claude-worktrees-rd-8', 'a', ranOnRecord(['run_id' => 'a', 'finished_at' => '2026-09-28T10:01:00Z']));
    $this->claude->preflightRun($this->folder.'-x', 'b', ranOnRecord(['run_id' => 'b', 'runner' => 'local', 'finished_at' => '2026-09-27T10:01:00Z']));

    expect(ranOnCells($this->get('/p/story-board/preflight')->assertOk()->getContent()))->toBe(['Brain', '—']);
});

it('never writes to a run record it reads', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z')]);
    $file = $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord());
    $before = [md5_file($file), filemtime($file)];

    $this->get('/p/story-board/preflight')->assertOk();

    expect([md5_file($file), filemtime($file)])->toBe($before);
});

it('Given the Brain filter, then only Brain rows show, without a server request', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z'), ranOnRow('2026-09-27T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord());

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    // The filter is Alpine over the page's own run data: each row carries where it ran, and no button talks to the server.
    preg_match_all('/data-ran-on-filter="([a-z]+)"/', $html, $buttons);
    expect($buttons[1])->toBe(['all', 'brain', 'mac', 'unknown'])
        ->and($html)->toMatch('/data-ran-on-filter="brain"[^>]*x-on:click="fRan = \'brain\'"/')
        ->and($html)->not->toMatch('/data-ran-on-filter="[a-z]+"[^>]*wire:/')
        ->and($html)->toMatch('/<tr\b[^>]*data-run="0"[^>]*x-show="keep\(0\)"/')
        ->and($html)->toContain('ranOn\\u0022:\\u0022brain\\u0022');
});

it('Given 3 brain runs and 3 mac runs in the window, then the trend strip shows each one\'s median wall time', function () {
    $lines = [RAN_ON_HEADER];
    foreach ([[1, 'local', 600], [2, 'brain', 120], [3, 'local', 700], [4, 'brain', 180], [5, 'local', 800], [6, 'brain', 240]] as [$day, $runner, $wall]) {
        $ts = "2026-09-2{$day}T10:00:00Z";
        $lines[] = ranOnRow($ts, 'main', $wall);
        $this->claude->preflightRun($this->folder, "r{$day}", ranOnRecord(['run_id' => "r{$day}", 'runner' => $runner, 'finished_at' => "2026-09-2{$day}T10:02:00Z"]));
    }
    $this->claude->preflightCsv($this->folder, $lines);

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    $wall = fn (string $runner) => preg_match('/data-runner-wall="'.$runner.'"[^>]*>.*?data-median[^>]*>(.*?)</s', $html, $m) ? trim($m[1]) : null;
    expect($wall('brain'))->toBe('3:00')
        ->and($wall('mac'))->toBe('11:40');
});

it('Given only brain runs in the window, then the trend strip shows no per-runner wall time', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord());

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    // One runner alone has nothing to compare, and a filter can only narrow the runs, so the figures are not rendered.
    expect($html)->toContain('data-trend="wall"')->not->toContain('data-runner-walls');
});

it('Given the page loads, then board.preflight_history_viewed carries matched and unmatched counts', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-28T10:00:00Z'), ranOnRow('2026-09-27T10:00:00Z'), ranOnRow('2026-09-26T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord());
    Log::spy();

    $this->get('/p/story-board/preflight')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.preflight_history_viewed'
        && $c === ['project' => 'story-board', 'runs' => 3, 'skipped' => 0, 'matched' => 1, 'unmatched' => 2])->once();
});

it('Given the latest run matched to a brain record, then that project\'s home tile reads "… on brain"', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-29T10:00:00Z'), ranOnRow('2026-09-27T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['finished_at' => '2026-09-29T10:04:00Z']));

    $tile = collect(app(ListProjectTiles::class)->handle(ProjectFilter::all()))->firstWhere('name', 'story-board');
    preg_match('#<a[^>]*data-tile="story-board".*?</a>#s', Livewire::test(Overview::class)->html(), $html);

    expect($tile['preflight']['ran_on'])->toBe('brain')
        ->and(trim(strip_tags(preg_replace('/.*(<span[^>]*data-tile-ran-on[^>]*>.*?<\/span>).*/s', '$1', $html[0] ?? ''))))->toBe('on brain');
});

it('Given the latest run matched to a local record, then the tile reads "on mac"; unmatched, it adds nothing', function () {
    $this->claude->preflightCsv($this->folder, [RAN_ON_HEADER, ranOnRow('2026-09-29T10:00:00Z')]);
    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['runner' => 'local', 'finished_at' => '2026-09-29T10:01:00Z']));
    $tiles = fn () => collect(app(ListProjectTiles::class)->handle(ProjectFilter::all()))->firstWhere('name', 'story-board');

    expect($tiles()['preflight']['ran_on'])->toBe('mac');

    $this->claude->preflightRun($this->folder, 'run-1', ranOnRecord(['runner' => 'local', 'finished_at' => '2026-09-29T11:00:00Z']));
    expect($tiles()['preflight']['ran_on'])->toBeNull();
});
