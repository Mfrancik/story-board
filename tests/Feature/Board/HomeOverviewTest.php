<?php

use App\Actions\Board\ListNeedsMe;
use App\Http\Middleware\RedirectProjectFilter;
use App\Livewire\Board\Overview;
use App\Models\Project;
use App\Models\Story;
use App\Services\SessionReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\SessionFixture;

/**
 * RD-5 acceptance criteria: `/` is the overview in the RD-2 shell — a sentence of counts, four
 * headline figures, Needs me (oldest first) and Live now (one card per session, with what it is
 * doing and how full its context is). Sessions are hand-written JSONL in a temp root.
 */
beforeEach(function () {
    Bus::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $this->coins = Project::factory()->create(['name' => 'coins', 'path' => '/work/coins', ...$fresh]);
    $this->ledger = Project::factory()->create(['name' => 'ledger-app', 'path' => '/work/ledger-app', ...$fresh]);

    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
});

afterEach(function () {
    $this->sessions->destroy();
});

/**
 * The six Needs me stories: two awaiting a pick, one draft, three approved, on different dates.
 */
function seedOverviewNeedsMe(Project $coins, Project $ledger): void
{
    $options = ['dir' => null, 'options' => ['a', 'b'], 'chosen' => null];
    Story::factory()->for($coins)->create(['story_id' => 'CO-1', 'title' => 'Wallet onboarding', 'status' => 'draft', 'mockups' => $options, 'dated_on' => '2026-10-05']);
    Story::factory()->for($ledger)->create(['story_id' => 'LA-1', 'title' => 'Project timeline', 'status' => 'approved', 'mockups' => $options, 'dated_on' => '2026-10-01']);
    Story::factory()->for($coins)->create(['story_id' => 'CO-2', 'title' => 'Payout export CSV', 'status' => 'draft', 'dated_on' => '2026-10-03']);
    Story::factory()->for($coins)->create(['story_id' => 'CO-3', 'title' => 'Recurring entries', 'status' => 'approved', 'dated_on' => '2026-10-09']);
    Story::factory()->for($ledger)->create(['story_id' => 'LA-2', 'title' => 'Home project filter', 'status' => 'approved', 'dated_on' => '2026-09-20']);
    Story::factory()->for($coins)->create(['story_id' => 'CO-4', 'title' => 'Preflight ran on', 'status' => 'approved', 'dated_on' => null]);

    // Not waiting on the owner: built, cancelled, parked, off main, and a disabled project.
    Story::factory()->for($coins)->create(['story_id' => 'CO-9', 'status' => 'built']);
    Story::factory()->for($coins)->create(['story_id' => 'CO-8', 'status' => 'draft', 'is_parked' => true]);
    Story::factory()->for($coins)->create(['story_id' => 'CO-7', 'status' => 'approved', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch feat/x', 'branch' => 'feat/x']);
    $off = Project::factory()->disabled()->create(['name' => 'acme', 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    Story::factory()->for($off)->create(['story_id' => 'AC-1', 'status' => 'approved']);
}

/**
 * One headline figure's markup, so its assertions stay inside that figure.
 */
function overviewFigure(string $html, string $key): string
{
    preg_match('/<article\b[^>]*data-figure="'.$key.'".*?<\/article>/s', $html, $m);

    return $m[0] ?? '';
}

/**
 * The Needs me rows' data-needs-row attributes in page order, as `<kind> <project>/<ID>`.
 *
 * @return list<string>
 */
function overviewNeedsRows(string $html): array
{
    preg_match_all('/data-needs-row="([a-z]+)" data-needs-story="([^"]+)"/', $html, $m, PREG_SET_ORDER);

    return array_map(fn ($r) => "{$r[1]} {$r[2]}", $m);
}

it('given 2 picks awaiting, 1 draft and 3 approved stories, then Needs me reads 6 with "2 picks · 1 draft · 3 ready", and the list shows 6 rows, oldest first', function () {
    Carbon::setTestNow('2026-10-11 09:00:00');
    seedOverviewNeedsMe($this->coins, $this->ledger);

    $html = $this->get('/')->assertOk()->getContent();

    $needs = overviewFigure($html, 'needs');
    expect($needs)->toContain('data-figure-value="6"')->toContain('2 picks · 1 draft · 3 ready');
    expect(overviewNeedsRows($html))->toBe([
        'ready ledger-app/LA-2',
        'pick ledger-app/LA-1',
        'draft coins/CO-2',
        'pick coins/CO-1',
        'ready coins/CO-3',
        'ready coins/CO-4',
    ]);
    // The sentence leads the page; the In flight figure counts every approved story not yet built.
    expect($html)->toContain('6 things need you.');
    expect(overviewFigure($html, 'in-flight'))->toContain('data-figure-value="4"');
});

it('given a Needs me row, when it is clicked, then it opens that story\'s page', function () {
    seedOverviewNeedsMe($this->coins, $this->ledger);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/<a\b[^>]*data-needs-story="coins\/CO-2"[^>]*>/', $html, $m);
    expect($m[0] ?? '')->toContain('href="'.route('stories.show', ['project' => 'coins', 'storyId' => 'CO-2']).'"')
        ->toContain('wire:navigate');
    $this->get(route('stories.show', ['project' => 'coins', 'storyId' => 'CO-2']))->assertOk()->assertSee('Payout export CSV');
});

it('given two live sessions in one project, then Live sessions reads 2, "1 project", and Live now shows two cards', function () {
    $this->sessions->session('/work/coins', 'main', 'aaaaaaaa-0000-0000-0000-000000000001', 1);
    $this->sessions->session('/work/coins/.claude/worktrees/prf14', 'fix/PRF-14', 'aaaaaaaa-0000-0000-0000-000000000002', 3);

    $html = $this->get('/')->assertOk()->getContent();

    expect(overviewFigure($html, 'live'))->toContain('data-figure-value="2"')->toContain('1 project');
    expect(substr_count($html, 'data-live-card'))->toBe(2);
    expect($html)->toContain('2 sessions are building.');
});

it('given a session whose last record is a Read tool call on tests/Feature/X.php, then its card reads "Reading tests/Feature/X.php"', function () {
    $cwd = '/work/coins';
    $id = 'aaaaaaaa-0000-0000-0000-000000000003';
    $this->sessions->file(SessionFixture::folderFor($cwd), $id, [
        SessionFixture::line($cwd, 'main', $id),
        SessionFixture::assistant($cwd, 'main', $id, [SessionFixture::tool('Bash', ['command' => 'php artisan test'])]),
        SessionFixture::assistant($cwd, 'main', $id, [SessionFixture::tool('Read', ['file_path' => "{$cwd}/tests/Feature/X.php"])]),
    ]);

    $this->get('/')->assertOk()
        ->assertSeeHtml('data-doing')
        ->assertSee('Reading tests/Feature/X.php')
        ->assertDontSee('Running php artisan test')
        ->assertDontSee('ASSISTANT-SENTINEL-TEXT');
});

it('given a session whose latest usage totals 142,000 tokens on a 200k model, then its bar is 71% full and reads "142k / 200k"; given no usage record yet, then the bar reads "—"', function () {
    $cwd = '/work/coins';
    $full = 'aaaaaaaa-0000-0000-0000-000000000004';
    $this->sessions->file(SessionFixture::folderFor($cwd), $full, [
        SessionFixture::assistant($cwd, 'main', $full, [], SessionFixture::usage(90_000)),
        SessionFixture::assistant($cwd, 'main', $full, [SessionFixture::tool('Read', ['file_path' => "{$cwd}/a.php"])], SessionFixture::usage(142_000)),
        SessionFixture::line($cwd, 'main', $full),
    ]);
    $ledger = '/work/ledger-app';
    $fresh = 'aaaaaaaa-0000-0000-0000-000000000005';
    $this->sessions->session($ledger, 'main', $fresh, 1);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/data-live-card="coins".*?<\/article>/s', $html, $coins);
    preg_match('/data-live-card="ledger-app".*?<\/article>/s', $html, $none);
    expect($coins[0] ?? '')->toContain('data-context-pct="71"')->toContain('style="width: 71%"')->toContain('142k / 200k');
    expect($none[0] ?? '')->toContain('data-context-pct=""')->toMatch('/data-context-label[^>]*>\s*—\s*</');
});

it('given 5 preflight runs in the last 7 days across projects, then the Preflight figure reads 5 with their median wall time; given none, then it reads 0 and "no runs this week"', function () {
    $header = 'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';
    $row = fn (int $daysAgo, int $wall) => now()->subDays($daysAgo)->subHour()->format('Y-m-d\TH:i:s\Z').",x,main,diff,{$wall},5,10,1000,100,0,2048,sonnet";

    $html = $this->get('/')->assertOk()->getContent();
    expect(overviewFigure($html, 'preflight'))->toContain('data-figure-value="0"')->toContain('no runs this week');

    $this->sessions->preflightCsv(SessionFixture::folderFor('/work/coins'), [$header, $row(1, 60), $row(2, 192), $row(6, 400), $row(9, 5000)]);
    $this->sessions->preflightCsv(SessionFixture::folderFor('/work/ledger-app').'--claude-worktrees-la1', [$header, $row(0, 120), $row(3, 300)]);

    $html = $this->get('/')->assertOk()->getContent();
    expect(overviewFigure($html, 'preflight'))->toContain('data-figure-value="5"')->toContain('median 3m 12s')
        ->not->toContain('no runs this week');
});

it('given no projects, then the page shows one empty state linking to /projects instead of the panels', function () {
    Project::query()->delete();
    Project::factory()->disabled()->create(['name' => 'off', 'indexed_at' => now(), 'refresh_attempted_at' => now()]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'data-overview-empty'))->toBe(1);
    expect($html)->toContain('No projects yet')->toContain('href="'.route('projects.manage').'"')
        ->not->toContain('data-figure=')->not->toContain('data-needs-panel')->not->toContain('data-live-now');
});

it('given the session reader throws, then Live now shows its error state and the rest of the page still renders', function () {
    Log::spy();
    seedOverviewNeedsMe($this->coins, $this->ledger);
    $this->mock(SessionReader::class)->shouldReceive('live')->andThrow(new RuntimeException('disk gone'));

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-live-now="error"')->toContain('Could not read sessions.')
        ->toContain('data-needs-panel')->toContain('data-needs-story="coins/CO-2"');
    expect(overviewFigure($html, 'live'))->toContain('data-figure-value=""');
    Log::shouldHaveReceived('error')->withArgs(fn ($event, $context) => $event === 'board.overview_panel_failed'
        && $context['panel'] === 'live' && $context['error'] === RuntimeException::class)->once();
});

// Beyond the criteria: the page's wiring, states, guards and log lines.

it('routes / to the overview in the RD-2 shell and keeps the old ?project= redirect', function () {
    expect(Route::getRoutes()->getByName('home')->gatherMiddleware())->toContain(RedirectProjectFilter::class);

    $this->get('/')->assertOk()->assertSeeLivewire(Overview::class)->assertSee('class="shell"', false);
    $this->get('/?project=coins')->assertRedirect('/p/coins');
});

it('refreshes every 30 s with one wire:poll on Live now and nowhere else', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'wire:poll'))->toBe(1);
    expect($html)->toMatch('/<section\b[^>]*wire:poll\.30s[^>]*data-live-now=/');
});

it('shows the empty states of Needs me and Live now, and the Try again button on an error', function () {
    $this->get('/')->assertOk()
        ->assertSee('Nothing needs you. No picks, drafts or ready stories.')
        ->assertSee('No sessions running.')
        ->assertSee('Nothing needs you.')
        ->assertSeeHtml('data-live-now="empty"');

    $this->mock(SessionReader::class)->shouldReceive('live')->andThrow(new RuntimeException('x'));
    Livewire::test(Overview::class)->assertSee('Try again')->assertSeeHtml('wire:click="$refresh"');
});

it('shows the Needs me error state when its query fails, and Live now still renders', function () {
    Log::spy();
    $this->sessions->session('/work/coins', 'main', 'aaaaaaaa-0000-0000-0000-000000000006', 1);
    $this->mock(ListNeedsMe::class)->shouldReceive('handle')->andThrow(new RuntimeException('db'));

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-needs-panel="error"')->toContain('Could not load what needs you.')
        ->toContain('data-live-card="coins"');
    Log::shouldHaveReceived('error')->withArgs(fn ($event, $context) => $event === 'board.overview_panel_failed' && $context['panel'] === 'needs')->once();
});

it('logs board.overview_viewed with the counts once per page view, not on each poll', function () {
    Log::spy();
    seedOverviewNeedsMe($this->coins, $this->ledger);
    $this->sessions->session('/work/coins', 'main', 'aaaaaaaa-0000-0000-0000-000000000007', 1);

    Livewire::test(Overview::class)->call('$refresh');

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context) => $event === 'board.overview_viewed' && $context === [
        'projects' => 2, 'needs' => 6, 'picks' => 2, 'drafts' => 1, 'ready' => 3, 'sessions' => 1, 'preflight_runs' => 0, 'in_flight' => 4,
        // RD-7: two tiles; nothing shipped (the projects' paths are not checkouts).
        'tiles' => 2, 'shipped' => 0,
    ])->once();
});

it('logs board.live_session_unparsed once when a session\'s usage or tool record cannot be read, and still shows the card', function () {
    Log::spy();
    $cwd = '/work/coins';
    $id = 'aaaaaaaa-0000-0000-0000-000000000008';
    $this->sessions->file(SessionFixture::folderFor($cwd), $id, [
        SessionFixture::assistant($cwd, 'main', $id, [['type' => 'tool_use', 'name' => ['not', 'a', 'name'], 'input' => []]], ['input_tokens' => 'lots']),
    ]);

    $html = $this->get('/')->assertOk()->getContent();
    // Past the 20 s scan cache, so the file really is read twice: the warning is still logged once.
    Cache::forget('board.sessions');
    $this->get('/')->assertOk();

    preg_match('/data-live-card="coins".*?<\/article>/s', $html, $card);
    expect($card[0] ?? '')->toContain('No tool calls yet')->toMatch('/data-context-label[^>]*>\s*—\s*</');
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context) => $event === 'board.live_session_unparsed'
        && $context === ['file' => "{$id}.jsonl", 'reason' => 'usage'])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context) => $event === 'board.live_session_unparsed'
        && $context === ['file' => "{$id}.jsonl", 'reason' => 'tool'])->once();
});

it('summarises a session\'s latest tool call in one line, never its message, and names the story its branch builds', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'PRF-14', 'title' => 'Payout schedule', 'status' => 'approved']);
    $cwd = '/work/coins/.claude/worktrees/prf14';
    $id = 'aaaaaaaa-0000-0000-0000-000000000009';
    $this->sessions->file(SessionFixture::folderFor($cwd), $id, [
        SessionFixture::line($cwd, 'fix/PRF-14', $id, 72),
        SessionFixture::assistant($cwd, 'fix/PRF-14', $id, [SessionFixture::tool('Bash', ['command' => "cd {$cwd} && php artisan test --filter=Overview\necho SECOND-LINE", 'description' => 'Run tests'])]),
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/data-live-card="coins".*?<\/article>/s', $html, $card);
    expect($card[0] ?? '')->toContain('Running php artisan test --filter=Overview')->not->toContain('SECOND-LINE')
        ->toContain('fix/PRF-14')->toContain('href="'.route('stories.show', ['project' => 'coins', 'storyId' => 'PRF-14']).'"')
        ->toContain('1h 12m')->not->toContain('ASSISTANT-SENTINEL-TEXT')->not->toContain($id);
});

it('shows a Needs me row whose ID is malformed without a link, since it has no story page', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'bad-1', 'title' => 'Odd one', 'status' => 'draft', 'path' => 'stories/demo/bad-1.md']);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/<a\b[^>]*data-needs-story="coins\/bad-1"[^>]*>/', $html, $m);
    expect($m[0] ?? '')->toContain('data-needs-row="draft"')->not->toContain('href=')->not->toContain('wire:navigate');
    expect($html)->toContain('Odd one');
});

it('reads a long session\'s start from the head of its file, past the tail the reader keeps', function () {
    $cwd = '/work/coins';
    $id = 'aaaaaaaa-0000-0000-0000-000000000010';
    $filler = SessionFixture::line($cwd, 'main', $id, 30, ['message' => ['role' => 'user', 'content' => str_repeat('x', 1000)]]);
    $this->sessions->file(SessionFixture::folderFor($cwd), $id, [
        SessionFixture::line($cwd, 'main', $id, 150),
        ...array_fill(0, (int) ceil(SessionReader::TAIL_BYTES / 1000) + 10, $filler),
        SessionFixture::assistant($cwd, 'main', $id, [SessionFixture::tool('Edit', ['file_path' => "{$cwd}/app/X.php"])]),
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/data-live-card="coins".*?<\/article>/s', $html, $card);
    expect($card[0] ?? '')->toContain('2h 30m')->toContain('Editing app/X.php');
});

it('ignores a subagent\'s (sidechain) lines when it reads what a session is doing and how full its context is', function () {
    $cwd = '/work/coins';
    $id = 'aaaaaaaa-0000-0000-0000-000000000011';
    $this->sessions->file(SessionFixture::folderFor($cwd), $id, [
        SessionFixture::assistant($cwd, 'main', $id, [SessionFixture::tool('Read', ['file_path' => "{$cwd}/main.php"])], SessionFixture::usage(50_000)),
        SessionFixture::assistant($cwd, 'main', $id, [SessionFixture::tool('Read', ['file_path' => "{$cwd}/sub.php"])], SessionFixture::usage(9_000), overrides: ['isSidechain' => true]),
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/data-live-card="coins".*?<\/article>/s', $html, $card);
    expect($card[0] ?? '')->toContain('Reading main.php')->toContain('50k / 200k')->not->toContain('sub.php');
});

it('measures a session past the standard window, or on a [1m] model, against the long window', function () {
    $cwd = '/work/coins';
    $this->sessions->file(SessionFixture::folderFor($cwd), 'aaaaaaaa-0000-0000-0000-000000000012', [
        SessionFixture::assistant($cwd, 'main', 'aaaaaaaa-0000-0000-0000-000000000012', [], SessionFixture::usage(250_000)),
    ]);
    $this->sessions->file(SessionFixture::folderFor('/work/ledger-app'), 'aaaaaaaa-0000-0000-0000-000000000013', [
        SessionFixture::assistant('/work/ledger-app', 'main', 'aaaaaaaa-0000-0000-0000-000000000013', [], SessionFixture::usage(100_000), 'claude-opus-4-1[1m]'),
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/data-live-card="coins".*?<\/article>/s', $html, $coins);
    preg_match('/data-live-card="ledger-app".*?<\/article>/s', $html, $ledger);
    expect($coins[0] ?? '')->toContain('data-context-pct="25"')->toContain('250k / 1M');
    expect($ledger[0] ?? '')->toContain('data-context-pct="10"')->toContain('100k / 1M');
});
