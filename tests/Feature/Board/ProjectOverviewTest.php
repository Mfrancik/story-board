<?php

use App\Actions\Board\ReadProjectStories;
use App\Actions\Board\ReadStoryProgress;
use App\Jobs\RefreshProjectJob;
use App\Livewire\Board\ProjectOverview;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * RD-11 acceptance criteria: `/p/{project}` opens the project's overview (option B), a tile grid read from the
 * board's existing readers — story progress, Needs you, Live now, latest preflight, production, About and the
 * biggest initiatives — with Overview the first project tab and Refresh this project in its header. Sessions and
 * preflight files are written to a temp root (never the real ~/.claude); About reads a real fixture repo.
 */
const OVERVIEW_CSV_HEADER = 'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-11T15:00:00Z'));
    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $this->fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    // No sha: About reads "not read yet" unless a test gives the project a real repo.
    $this->coins = Project::factory()->create(['name' => 'coins', 'path' => '/work/coins', ...$this->fresh]);
    $this->repos = [];
});

afterEach(function () {
    $this->sessions->destroy();
    foreach ($this->repos as $repo) {
        $repo->destroy();
    }
});

/**
 * One tile's markup by its `data-tile` name, up to the next tile, so assertions stay inside it.
 */
function overviewTile(string $html, string $tile): string
{
    preg_match('#<section\b[^>]*data-tile="'.$tile.'".*?</section>#s', $html, $m);

    return $m[0] ?? '';
}

/**
 * A run record for coins (ADR-055) finishing at `$finished` on `main`, with `$verdict` (or none at all).
 *
 * @return array<string, mixed>
 */
function overviewRunRecord(string $finished, ?string $verdict): array
{
    return [
        'schema' => 1, 'run_id' => 'run-'.$finished, 'project' => 'coins', 'branch' => 'main', 'sha' => str_repeat('a', 40),
        'runner' => 'brain', 'host' => 'brain.local', 'mode' => 'scoped', 'started_at' => $finished, 'finished_at' => $finished,
        'wall_s' => 300, 'verdict' => $verdict, 'gates' => [],
    ];
}

it('Given an enabled project, when I open /p/{project}, then the Overview renders with the project header, the Overview tab first and current, and every tile in option B\'s order', function () {
    $html = $this->get('/p/coins')->assertOk()
        ->assertSee('coins · Overview')
        ->getContent();

    preg_match('/<nav[^>]*data-project-tabs[^>]*>.*?<\/nav>/s', $html, $nav);
    preg_match_all('/data-project-tab="([^"]+)"/', $nav[0] ?? '', $tabs);
    preg_match_all('/data-tile="([^"]+)"/', $html, $tiles);

    expect($html)->toContain('<h1')->toContain('data-refresh-project')
        ->and($tabs[1])->toBe(['overview', 'stories', 'preflight', 'handbook', 'map', 'marketing'])
        ->and($nav[0])->toMatch('/data-project-tab="overview"[^>]*aria-current="page"/')
        ->and($tiles[1])->toBe(['progress', 'needs', 'live', 'preflight', 'production', 'about', 'initiatives']);
});

it('Given an unknown or disabled project, when I open /p/{project}, then it is refused and board.project_page_refused is logged with the reason', function (string $name, string $reason) {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get("/p/{$name}")->assertNotFound();

    Log::shouldHaveReceived('info')->with('board.project_page_refused', ['project' => $name, 'reason' => $reason])->once();
})->with([['nope', 'unknown'], ['acme-site', 'disabled']]);

it('Given the shell sidebar and a project sub-page\'s breadcrumb, then the project link points at /p/{project}', function (string $url) {
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-1', 'status' => 'draft']);
    $html = $this->get($url)->assertOk()->getContent();

    preg_match('/<a\b[^>]*data-nav-project="coins"[^>]*>/', $html, $side);
    preg_match('/<a\b[^>]*data-crumb-project[^>]*>/', $html, $crumb);

    expect($side[0] ?? '')->toContain('href="'.url('/p/coins').'"')
        ->and($crumb[0] ?? '')->toContain('href="'.url('/p/coins').'"');
})->with(['/p/coins/stories', '/p/coins/preflight', '/p/coins/handbook', '/p/coins/map', '/p/coins/s/CO-1', '/p/coins']);

it('Given stories on the project\'s ref, then the progress tile\'s counts by status match the Stories page\'s counts', function () {
    $n = 0;
    foreach (['built' => 5, 'approved' => 3, 'draft' => 2, 'cancelled' => 1, 'done' => 2] as $status => $count) {
        for ($i = 0; $i < $count; $i++) {
            Story::factory()->for($this->coins)->create(['story_id' => 'CO-'.++$n, 'status' => $status, 'initiative' => $i % 2 ? 'mobile' : 'intake']);
        }
    }
    // Off main: not on the ref, so neither page counts it.
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-99', 'status' => 'built', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch x', 'branch' => 'x']);

    $tile = overviewTile($this->get('/p/coins')->assertOk()->getContent(), 'progress');
    preg_match_all('/data-progress-count="([a-z]+)".*?<span class="font-mono[^"]*">([\d,]+)<\/span>/s', $tile, $m);
    $shown = array_combine($m[1], array_map('intval', $m[2]));

    $stories = app(ReadProjectStories::class)->handle($this->coins)['tallies'];

    expect($shown)->toBe(['built' => 5, 'approved' => 3, 'draft' => 2, 'cancelled' => 1, 'other' => 2])
        ->and($shown)->toBe($stories)
        // 5 built of the 12 still owed (13 less the cancelled one).
        ->and($tile)->toContain('41%')->toContain('5 of 12 built');
});

it('Given this project has a draft, a story awaiting a pick and a ready story, then Needs you lists exactly those, each linking to its story page; given none, then it reads "Nothing needs you here"', function () {
    $other = Project::factory()->create(['name' => 'ledger', ...$this->fresh]);
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-1', 'status' => 'draft']);
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-2', 'status' => 'approved', 'mockups' => ['dir' => null, 'options' => ['a', 'b'], 'chosen' => null]]);
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-3', 'status' => 'approved']);
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-4', 'status' => 'built']);
    Story::factory()->for($other)->create(['story_id' => 'LE-1', 'status' => 'draft']);

    $tile = overviewTile($this->get('/p/coins')->assertOk()->getContent(), 'needs');
    preg_match_all('/<a\b[^>]*data-needs-row="([a-z]+)"[^>]*data-needs-story="([^"]+)"[^>]*>/', $tile, $rows, PREG_SET_ORDER);
    $found = collect($rows)->mapWithKeys(fn ($r) => [$r[2] => $r[1]])->sortKeys()->all();

    expect($found)->toBe(['CO-1' => 'draft', 'CO-2' => 'pick', 'CO-3' => 'ready']);
    foreach ($rows as $row) {
        expect($row[0])->toContain('href="'.route('stories.show', ['project' => 'coins', 'storyId' => $row[2]]).'"');
    }

    expect(overviewTile($this->get('/p/ledger')->assertOk()->getContent(), 'needs'))->not->toContain('Nothing needs you here');
    Story::query()->where('project_id', $this->coins->id)->update(['status' => 'built']);
    expect(overviewTile($this->get('/p/coins')->assertOk()->getContent(), 'needs'))->toContain('Nothing needs you here')
        ->not->toContain('data-needs-row');
});

it('Given a live session in this project and one in another, then Live now shows only this project\'s', function () {
    Project::factory()->create(['name' => 'ledger', 'path' => '/work/ledger', ...$this->fresh]);
    $this->sessions->session('/work/coins', 'main', 'aaaaaaaa-0000-0000-0000-000000000001', 1);
    $this->sessions->session('/work/ledger', 'main', 'aaaaaaaa-0000-0000-0000-000000000002', 1);

    $tile = overviewTile($this->get('/p/coins')->assertOk()->getContent(), 'live');

    expect(substr_count($tile, 'data-live-card="coins"'))->toBe(1)
        ->and($tile)->not->toContain('data-live-card="ledger"');

    // With none in this project, the empty state.
    expect(overviewTile($this->get('/p/'.Project::factory()->create(['name' => 'quiet', ...$this->fresh])->name)->getContent(), 'live'))
        ->toContain('Nothing running.');
});

it('Given preflight runs with and without a verdict, then the latest-run tile shows the verdict only when recorded and "—" otherwise; given no runs, then the tile\'s empty state shows', function () {
    $folder = SessionFixture::folderFor('/work/coins');

    // No runs at all: the empty state.
    expect(overviewTile($this->get('/p/coins')->getContent(), 'preflight'))->toContain('data-preflight-state="empty"')
        ->toContain('No preflight runs recorded');

    // The latest row has no record: "—", never the older run's verdict.
    $this->sessions->preflightCsv($folder, [OVERVIEW_CSV_HEADER,
        '2026-10-11T10:00:00Z,coins,main,scoped,300,4,4,500000,1000,0,0,sonnet',
        '2026-10-11T14:00:00Z,coins,main,full,960,4,4,2000000,10000,0,0,sonnet',
    ]);
    $this->sessions->preflightRun($folder, 'a', overviewRunRecord('2026-10-11T10:04:00Z', 'GO'));
    $tile = overviewTile($this->get('/p/coins')->getContent(), 'preflight');
    expect($tile)->toContain('data-preflight-verdict=""')->toMatch('/data-preflight-verdict=""[^>]*>\s*—/')
        ->toContain('full')->toContain('2M tok')
        // Today: two runs, one of them all-pass.
        ->and(strip_tags(preg_replace('/\s+/', ' ', $tile)))->toContain('today 2 runs · 1 all-pass');

    // A record for the latest row: its verdict.
    $this->sessions->preflightRun($folder, 'b', overviewRunRecord('2026-10-11T14:03:00Z', 'NO-GO'));
    expect(overviewTile($this->get('/p/coins')->getContent(), 'preflight'))->toMatch('/data-preflight-verdict="NO-GO"[^>]*>\s*NO-GO/');
});

it('Given a project with no production connection, then the Production area is the single "not connected · Set up" line linking to /projects; given connected metrics with snapshots, then each shows its latest value and a sparkline', function () {
    $tile = overviewTile($this->get('/p/coins')->getContent(), 'production');
    expect($tile)->toContain('data-production-state="none"')
        ->and(trim(preg_replace('/\s+/', ' ', strip_tags($tile))))->toBe('Production not connected · Set up')
        ->and($tile)->toContain('href="'.route('projects.manage').'"');

    ProdConnection::factory()->for($this->coins)->create();
    $users = ProdMetric::factory()->for($this->coins)->create(['key' => 'users', 'label' => 'Total users', 'position' => 1]);
    $today = ProdMetric::factory()->for($this->coins)->create(['key' => 'today', 'label' => 'New today', 'position' => 2]);
    foreach ([3 => 2400, 1 => 2410, 0 => 2417] as $daysAgo => $value) {
        ProdSnapshot::factory()->for($users, 'metric')->create(['project_id' => $this->coins->id, 'day' => now()->subDays($daysAgo)->toDateString(), 'value' => $value]);
    }
    ProdSnapshot::factory()->for($today, 'metric')->create(['project_id' => $this->coins->id, 'day' => now()->subDay()->toDateString(), 'value' => 6]);

    $tile = overviewTile($this->get('/p/coins')->getContent(), 'production');
    preg_match_all('/data-metric="([^"]+)".*?data-metric-value[^>]*>([^<]+)</s', $tile, $m);

    expect(array_combine($m[1], $m[2]))->toBe(['Total users' => '2,417', 'New today' => '6'])
        ->and(substr_count($tile, 'data-sparkline'))->toBe(2)
        ->and($tile)->not->toContain('not connected');
});

it('Given a brief with a "What it is" section, then About shows it; given no brief, then About shows the empty state and the page still renders', function () {
    $withBrief = new GitFixture;
    $withBrief->write('docs/PROJECT-BRIEF.md', "# Brief\n\n## What it is, and for whom\nA catalog for **coin collectors**.\n\n### Users\nCollectors first.\n\n## Domain model\nCoins and sets.\n")
        ->commitAndPush()->syncProject();
    $bare = new GitFixture;
    $bare->write('README.md', "# Bare\n")->commitAndPush()->syncProject();
    $this->repos = [$withBrief, $bare];
    $this->coins->update(['path' => $withBrief->project, 'sha' => $withBrief->originSha()]);
    Project::factory()->create(['name' => 'bare', 'path' => $bare->project, 'sha' => $bare->originSha(), ...$this->fresh]);

    $about = overviewTile($this->get('/p/coins')->assertOk()->getContent(), 'about');
    expect($about)->toContain('data-about-state="ok"')->toContain('<strong>coin collectors</strong>')->toContain('Collectors first.')
        ->not->toContain('Domain model')->not->toContain('Coins and sets.');

    $html = $this->get('/p/bare')->assertOk()->getContent();
    expect(overviewTile($html, 'about'))->toContain('data-about-state="missing"')->toContain('data-empty="docs/PROJECT-BRIEF.md"')
        ->and($html)->toContain('data-tile="initiatives"');
});

it('When I press Refresh this project, then one RefreshProjectJob is queued for this project and board.project_refresh_requested is logged; given the project is disabled, then the button is disabled', function () {
    Log::spy();

    Livewire::test(ProjectOverview::class, ['project' => $this->coins])
        ->assertSeeHtml('data-refresh-project')
        ->assertDontSeeHtml('data-refreshing')
        ->call('refresh')
        ->assertSeeHtml('data-refreshing')
        ->assertSee('Refreshing…')
        ->assertSeeHtml('data-refresh-poll');

    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn (RefreshProjectJob $job) => $job->project->is($this->coins));
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.project_refresh_requested'
        && $context['project'] === 'coins' && array_key_exists('request_id', $context))->once();

    // Mounted directly (the route would 404 it first), a disabled project's button is disabled.
    $off = Project::factory()->disabled()->create(['name' => 'acme-site', ...$this->fresh]);
    $html = Livewire::test(ProjectOverview::class, ['project' => $off])->html();
    preg_match('/<button\b[^>]*data-refresh-project[^>]*>/', $html, $button);
    expect($button[0] ?? '')->toContain('disabled');
});

it('Given the queued refresh has re-indexed the project, then the button reads Refresh this project again and the page stops polling', function () {
    $page = Livewire::test(ProjectOverview::class, ['project' => $this->coins])->call('refresh')->assertSeeHtml('data-refreshing');

    $this->travel(30)->seconds();
    $this->coins->update(['indexed_at' => now(), 'refresh_attempted_at' => now()]);

    $page->call('$refresh')->assertDontSeeHtml('data-refreshing')->assertDontSeeHtml('data-refresh-poll')->assertSee('Refresh this project');
});

it('Given the project is switched off after the page loaded, when Refresh is pressed, then nothing is queued and the refusal is logged', function () {
    Log::spy();
    $page = Livewire::test(ProjectOverview::class, ['project' => $this->coins]);
    $this->coins->update(['is_enabled' => false]);

    $page->call('refresh')->assertRedirect(route('home'));

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->with('board.project_page_refused', ['project' => 'coins', 'reason' => 'disabled', 'request' => 'update'])->once();
});

it('Given a tile whose source throws, then that tile shows its error, the others still render, and board.overview_panel_failed is logged', function () {
    Log::spy();
    $this->mock(ReadStoryProgress::class)->shouldReceive('handle')->andThrow(new RuntimeException('boom'));

    $html = $this->get('/p/coins')->assertOk()->getContent();

    expect(overviewTile($html, 'progress'))->toContain('Could not count the stories')
        ->and(overviewTile($html, 'needs'))->toContain('Nothing needs you here');
    Log::shouldHaveReceived('error')->withArgs(fn ($event, $context = []) => $event === 'board.overview_panel_failed' && $context['panel'] === 'progress')->once();
});
