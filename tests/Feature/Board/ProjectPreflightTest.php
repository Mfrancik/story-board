<?php

use App\Actions\Board\ReadPreflightHistory;
use App\Livewire\Board\ProjectPreflight;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\SessionFixture;

/**
 * SB-16 acceptance criteria: `/p/{project}/preflight` lists the runs recorded in every
 * `preflight-cost.csv` under the Claude projects root (`board.sessions_path`) whose folder is
 * the project's encoded path, a worktree of it, or its plans folder. Fixture CSVs are written to
 * a temp root in the two shapes checked on 2026-09-29: coins' old header (no `project` column)
 * and story-board's new one (with `project`, and `?` for an unknown mode). Never the real ~/.claude.
 * The client-side half (filters, charts) is in tests/Browser/ProjectPreflightTest.php.
 */
const PREFLIGHT_OLD_HEADER = 'ts,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';
const PREFLIGHT_NEW_HEADER = 'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';

beforeEach(function () {
    Queue::fake();
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
 * The table's runs in page order, each as its cells' text keyed by `data-col`.
 *
 * @return list<array<string, string>>
 */
function preflightRows(string $html): array
{
    preg_match_all('/<tr\b[^>]*data-run="\d+"[^>]*>(.*?)<\/tr>/s', $html, $rows);

    return array_map(function (string $row) {
        preg_match_all('/<td\b[^>]*data-col="([a-z]+)"[^>]*>(.*?)<\/td>/s', $row, $cells, PREG_SET_ORDER);
        $out = [];
        foreach ($cells as [, $col, $inner]) {
            $out[$col] = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($inner))));
        }

        return $out;
    }, $rows[1]);
}

it('Given a project whose CSV holds 3 rows, when /p/{project}/preflight loads, then the table lists 3 runs, newest first, with date, branch, mode, wall time as m:ss, turns, tool calls, tokens, audit tier', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_NEW_HEADER,
        "2026-09-27T15:08:52Z,{$this->folder},feat/ADMIN-24-tenant-console,scoped,826,4,4,515663,1273,0,0,sonnet",
        "2026-09-28T18:18:01Z,{$this->folder},main,full,1223,2,2,2559306,10418,0,61,sonnet",
        "2026-08-26T02:33:47Z,{$this->folder},feat/MOB-21-status-leaves-the-gallery,scoped,449,48,48,14798031,8069,14806100,37580,sonnet",
    ]);

    $rows = preflightRows($this->get('/p/story-board/preflight')->assertOk()->getContent());

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'branch'))->toBe(['main', 'feat/ADMIN-24-tenant-console', 'feat/MOB-21-status-leaves-the-gallery'])
        ->and($rows[0])->toMatchArray([
            'when' => 'Sep 28 18:18', 'where' => 'main checkout', 'mode' => 'full', 'wall' => '20:23', 'turns' => '2',
            'tools' => '2', 'tokens' => '2.6M', 'share' => '0%', 'pack' => '0.1 KB', 'tier' => 'sonnet',
        ])
        ->and($rows[1])->toMatchArray(['when' => 'Sep 27 15:08', 'mode' => 'scoped', 'wall' => '13:46', 'tokens' => '517k'])
        // A subagent share over the run's own tokens is shown as the meter wrote it, not capped.
        ->and($rows[2])->toMatchArray(['wall' => '7:29', 'turns' => '48', 'tokens' => '14.8M', 'share' => '100%', 'pack' => '37 KB']);
});

it('Given an old-shape CSV with no project column (coins) and a new-shape CSV, then both are read by header and show the same columns', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-10T14:05:14Z,integration/mob-32-33,scoped,477,1,1,422940,944,0,35225,sonnet',
    ]);
    $this->claude->preflightCsv($this->folder.'--claude-worktrees-sb-10', [
        PREFLIGHT_NEW_HEADER,
        "2026-09-11T14:05:14Z,{$this->folder}--claude-worktrees-sb-10,integration/mob-32-33,scoped,477,1,1,422940,944,0,35225,sonnet",
    ]);

    [$new, $old] = preflightRows($this->get('/p/story-board/preflight')->assertOk()->getContent());

    unset($new['when'], $new['where'], $old['when'], $old['where']);
    expect($old)->toBe($new)
        ->and($old)->toMatchArray(['branch' => 'integration/mob-32-33', 'mode' => 'scoped', 'wall' => '7:57', 'tokens' => '424k', 'pack' => '34 KB', 'tier' => 'sonnet']);
});

it('Given runs in the main-checkout folder and in a --claude-worktrees-sb-15 folder, then both are listed, and the worktree row shows "sb-15" as where it ran', function () {
    $this->claude->preflightCsv($this->folder, [PREFLIGHT_NEW_HEADER, "2026-09-29T04:49:51Z,{$this->folder},sb-2-project-registry,scoped,511,44,43,4309904,39533,132381,7018,sonnet"]);
    $this->claude->preflightCsv($this->folder.'--claude-worktrees-sb-15', [PREFLIGHT_NEW_HEADER, '2026-09-29T11:04:11Z,x,feat/SB-15-stories-by-initiative,?,195,5,4,343061,1724,12072,16078,sonnet']);
    $this->claude->preflightCsv($this->folder.'--claude-plans', [PREFLIGHT_NEW_HEADER, '2026-09-29T10:31:30Z,x,main,?,310,22,20,1524066,7583,319276,26073,sonnet']);

    $rows = preflightRows($this->get('/p/story-board/preflight')->assertOk()->getContent());

    expect(array_column($rows, 'where'))->toBe(['sb-15', 'plans', 'main checkout'])
        ->and($rows[0]['branch'])->toBe('feat/SB-15-stories-by-initiative');
});

it('Given a folder for a different project whose name starts with the same text (story-board-x), then its rows are not listed', function () {
    $this->claude->preflightCsv($this->folder, [PREFLIGHT_OLD_HEADER, '2026-09-20T10:00:00Z,main,scoped,60,1,1,100,1,0,0,sonnet']);
    $this->claude->preflightCsv($this->folder.'-x', [PREFLIGHT_OLD_HEADER, '2026-09-21T10:00:00Z,other-project,scoped,60,1,1,100,1,0,0,sonnet']);
    $this->claude->preflightCsv($this->folder.'-x--claude-worktrees-a', [PREFLIGHT_OLD_HEADER, '2026-09-22T10:00:00Z,other-worktree,scoped,60,1,1,100,1,0,0,sonnet']);

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    expect(array_column(preflightRows($html), 'branch'))->toBe(['main'])
        ->and($html)->not->toContain('other-project')->not->toContain('other-worktree');
});

it('Given an empty mode or audit_model cell, then that cell shows "—"', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-12T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,',
        // The meter writes `?` when it cannot tell the mode: unknown, so the same dash.
        '2026-09-11T18:53:34Z,main,?,187,14,13,4243249,8902,,1117,sonnet',
    ]);

    $rows = preflightRows($this->get('/p/story-board/preflight')->assertOk()->getContent());

    expect($rows[0])->toMatchArray(['mode' => '—', 'tier' => '—'])
        ->and($rows[1])->toMatchArray(['mode' => '—', 'share' => '—', 'tier' => 'sonnet']);
});

it('Given an audit tier of opus+sonnet, then the cell is flagged "check the tier"; given sonnet, it is not', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-18T19:31:04Z,opus-run,,1553,34,45,5853613,11787,2259296,47206,opus+sonnet',
        '2026-09-17T19:31:04Z,sonnet-run,,1553,34,45,5853613,11787,2259296,47206,sonnet',
        '2026-09-16T19:31:04Z,blank-run,,1553,34,45,5853613,11787,2259296,47206,',
    ]);

    $rows = preflightRows($this->get('/p/story-board/preflight')->assertOk()->getContent());

    expect($rows[0]['tier'])->toBe('opus+sonnet check the tier')
        ->and($rows[1]['tier'])->toBe('sonnet')
        ->and($rows[2]['tier'])->toBe('—');
});

it('Given a row with an unparseable ts, then it is skipped and the page says "1 row could not be read"', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-12T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,',
        'yesterday,bad-date,,187,14,13,4243249,8902,0,1117,',
    ]);

    $response = $this->get('/p/story-board/preflight')->assertOk();

    expect(preflightRows($response->getContent()))->toHaveCount(1);
    $response->assertSeeHtml('data-skipped')->assertSee('1 row could not be read')->assertDontSee('bad-date');
});

it('skips a row with the wrong number of columns and counts it with the unparseable one: "2 rows could not be read"', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-12T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,',
        '2026-09-13T18:53:34Z,short-row,,187,14',
        'not-a-date,bad-date,,187,14,13,4243249,8902,0,1117,',
    ]);

    $response = $this->get('/p/story-board/preflight')->assertOk();

    expect(preflightRows($response->getContent()))->toHaveCount(1);
    $response->assertSee('2 rows could not be read')->assertDontSee('short-row');
});

it('Given the mode filter is set to scoped, then only scoped rows show, without a server request (the filter data is in the page)', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-12T18:53:34Z,a,scoped,187,14,13,100,1,0,0,sonnet',
        '2026-09-11T18:53:34Z,b,full,187,14,13,100,1,0,0,sonnet',
        '2026-09-10T18:53:34Z,c,,187,14,13,100,1,0,0,sonnet',
    ]);

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    // Each row is shown or hidden by Alpine over the page's own run data: no wire: binding on the filters.
    expect($html)->toContain('data-mode-filter="scoped"')->toContain('data-mode-filter="full"')
        ->and($html)->toMatch('/<tr\b[^>]*data-run="0"[^>]*x-show="keep\(0\)"/')
        ->and($html)->not->toMatch('/data-mode-filter="[a-z]+"[^>]*wire:/');
});

it('Given 40 runs across 60 days, then the trend strip shows runs, median wall time and median tokens for the last 30 days and the 30 before', function () {
    $lines = [PREFLIGHT_OLD_HEADER];
    foreach (range(0, 39) as $k) {
        // Every 1.5 days from 6 hours ago back to ~59 days ago: runs 0-19 in the last 30 days, 20-39 before.
        $ts = now()->subMinutes((int) (($k * 1.5 + 0.25) * 1440))->utc()->format('Y-m-d\TH:i:s\Z');
        $lines[] = $ts.',b'.$k.',scoped,'.(600 + $k * 10).',1,1,'.(1_000_000 + $k * 200_000).',0,0,0,sonnet';
    }
    $this->claude->preflightCsv($this->folder, $lines);

    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    $figure = function (string $name) use ($html): array {
        preg_match('/data-trend="'.$name.'".*?data-current[^>]*>(.*?)<.*?data-previous[^>]*>(.*?)</s', $html, $m);

        return [trim($m[1] ?? ''), trim($m[2] ?? '')];
    };
    expect(preflightRows($html))->toHaveCount(40)
        ->and($figure('runs'))->toBe(['20', '20'])
        ->and($figure('wall'))->toBe(['11:35', '14:55'])
        ->and($figure('tokens'))->toBe(['2.9M', '6.9M']);

    $trend = app(ReadPreflightHistory::class)->handle($this->board)['trend'];
    expect($trend)->toBe([
        'current' => ['runs' => 20, 'wall' => 695.0, 'tokens' => 2_900_000.0],
        'previous' => ['runs' => 20, 'wall' => 895.0, 'tokens' => 6_900_000.0],
    ]);
});

it('Given no CSV for the project, then the empty state lists the paths looked in and the page returns 200', function () {
    $response = $this->get('/p/story-board/preflight')->assertOk()
        ->assertSee('story-board has no preflight runs recorded yet.')
        ->assertSee("{$this->claude->root}/{$this->folder}/preflight-cost.csv")
        ->assertSee("{$this->claude->root}/{$this->folder}--claude-worktrees-*/preflight-cost.csv")
        ->assertSee("{$this->claude->root}/{$this->folder}--claude-plans/preflight-cost.csv");

    expect(preflightRows($response->getContent()))->toBe([])
        ->and($response->getContent())->not->toContain('data-trend=');
});

it('shows the empty state and logs board.preflight_history_unreadable when the Claude projects root is missing', function () {
    config(['board.sessions_path' => $this->claude->root.'/nowhere']);
    Log::spy();

    $this->get('/p/story-board/preflight')->assertOk()->assertSee('story-board has no preflight runs recorded yet.');

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.preflight_history_unreadable'
        && $c === ['project' => 'story-board', 'file' => $this->claude->root.'/nowhere'])->once();
});

it('logs board.preflight_history_unreadable for a CSV with no ts column and lists the other files\' runs', function () {
    $bad = $this->claude->preflightCsv($this->folder.'--claude-worktrees-odd', ['when,branch', '2026-09-01,main']);
    $this->claude->preflightCsv($this->folder, [PREFLIGHT_OLD_HEADER, '2026-09-12T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,']);
    Log::spy();

    $rows = preflightRows($this->get('/p/story-board/preflight')->assertOk()->getContent());

    expect($rows)->toHaveCount(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.preflight_history_unreadable'
        && $c === ['project' => 'story-board', 'file' => $bad])->once();
});

it('never writes to a CSV it reads', function () {
    $file = $this->claude->preflightCsv($this->folder, [PREFLIGHT_OLD_HEADER, '2026-09-12T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,', 'junk']);
    $before = [md5_file($file), filemtime($file)];

    $this->get('/p/story-board/preflight')->assertOk();

    expect([md5_file($file), filemtime($file)])->toBe($before);
});

it('Given a disabled or unknown project, then /p/{project}/preflight returns 404 (CheckProjectShown)', function () {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get('/p/nope/preflight')->assertNotFound();
    $this->get('/p/acme-site/preflight')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c === ['project' => 'nope', 'reason' => 'unknown'])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c === ['project' => 'acme-site', 'reason' => 'disabled'])->once();
});

it('and hydrate() re-checks it: a project switched off mid-visit sends the owner home, logged, without reading its runs', function (string $how, string $reason) {
    $page = Livewire::test(ProjectPreflight::class, ['project' => $this->board]);
    $how === 'disable' ? $this->board->update(['is_enabled' => false]) : $this->board->delete();
    Log::spy();
    $this->mock(ReadPreflightHistory::class, fn ($mock) => $mock->shouldNotReceive('handle'));

    $page->call('$refresh')->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused'
        && $c === ['project' => 'story-board', 'reason' => $reason, 'request' => 'update'])->once();
})->with([
    'switched off' => ['disable', 'disabled'],
    'removed' => ['delete', 'unknown'],
]);

it('Given the page loads, then board.preflight_history_viewed is logged with project, runs, skipped', function () {
    $this->claude->preflightCsv($this->folder, [
        PREFLIGHT_OLD_HEADER,
        '2026-09-12T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,',
        '2026-09-11T18:53:34Z,main,,187,14,13,4243249,8902,0,1117,',
        'bad,main,,187,14,13,4243249,8902,0,1117,',
    ]);
    Log::spy();

    $this->get('/p/story-board/preflight')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.preflight_history_viewed'
        && $c === ['project' => 'story-board', 'runs' => 2, 'skipped' => 1])->once();
});

it('adds a Preflight tab to the project tabs, marked current on this page', function () {
    $html = $this->get('/p/story-board/preflight')->assertOk()->getContent();

    expect($html)->toMatch('/data-project-tab="preflight"[^>]*aria-current="page"/')
        ->and($html)->toContain('href="'.route('projects.preflight', 'story-board').'"');
    expect($this->get('/p/story-board/stories')->assertOk()->getContent())
        ->toMatch('/data-project-tab="preflight"/')
        ->not->toMatch('/data-project-tab="preflight"[^>]*aria-current/');
});
