<?php

use App\Actions\Board\ListProjectTiles;
use App\Actions\Board\ListShippedThisWeek;
use App\Exceptions\GitReaderException;
use App\Livewire\Board\Overview;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use App\Services\ProjectFilter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * RD-7 acceptance criteria: one tile per enabled project on the home overview (story progress, off-main count,
 * last preflight, production headline) and Shipped this week — the build commits on each project's ref in the
 * last 7 days, read through GitReader's guarded `log`. Git-backed criteria use real fixture repos.
 */
beforeEach(function () {
    Bus::fake();
    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $this->fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $this->fixtures = [];
});

afterEach(function () {
    $this->sessions->destroy();
    foreach ($this->fixtures as $fixture) {
        $fixture->destroy();
    }
});

/**
 * One project's tile markup, so its assertions stay inside that tile.
 */
function homeTile(string $html, string $project): string
{
    preg_match('#<a[^>]*data-tile="'.preg_quote($project, '#').'".*?</a>#s', $html, $m);

    return $m[0] ?? '';
}

/**
 * The `<project>/<ID>` of every Shipped this week row, in page order.
 *
 * @return list<string>
 */
function shippedRowIds(string $html): array
{
    preg_match_all('/data-shipped-row="([^"]+)"/', $html, $m);

    return $m[1];
}

/**
 * A git fixture registered as an enabled project, its ref fetched so `origin/main` is current.
 */
function shippedFixtureProject(object $test, string $name): array
{
    $fixture = new GitFixture;
    $test->fixtures[] = $fixture;
    $project = Project::factory()->create(['name' => $name, 'path' => $fixture->project, ...$test->fresh]);

    return [$fixture, $project];
}

it('Given 21 built, 2 approved and 2 draft stories in story-board, then its tile shows a bar in those proportions and "21 built · 2 in flight · 2 draft"', function () {
    $project = Project::factory()->create(['name' => 'story-board', ...$this->fresh]);
    $n = 0;
    foreach (['built' => 21, 'approved' => 2, 'draft' => 2] as $status => $count) {
        for ($i = 0; $i < $count; $i++) {
            Story::factory()->for($project)->create(['story_id' => 'SB-'.++$n, 'status' => $status]);
        }
    }
    // Off main and on another project: neither may count.
    Story::factory()->for($project)->create(['story_id' => 'SB-99', 'status' => 'built', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch x', 'branch' => 'x']);

    $tile = homeTile(Livewire::test(Overview::class)->html(), 'story-board');

    expect($tile)->toContain('21 built · 2 in flight · 2 draft')
        ->toMatch('/data-segment="built"[^>]*width: 84%/')
        ->toMatch('/data-segment="approved"[^>]*width: 8%/')
        ->toMatch('/data-segment="draft"[^>]*width: 8%/')
        ->toContain('href="'.route('projects.stories', ['project' => 'story-board']).'"');
});

it('Given a project with an unmerged branch and an untracked mockup directory, then its tile reads "2 off main"; given none, then "clean"', function () {
    [$fixture, $project] = shippedFixtureProject($this, 'fx');
    $fixture->story('FX-1', 'draft')->commitAndPush();
    $project->update(['indexed_at' => null, 'refresh_attempted_at' => null]);
    $clean = Project::factory()->create(['name' => 'tidy', ...$this->fresh]);

    $fixture->story('FX-2', 'draft')->pushBranch('docs/FX-2-story');
    $fixture->untracked($fixture->project, 'docs/mockups/FX-3/option-a.html', 'a');
    $this->artisan('board:refresh')->assertSuccessful();

    $html = Livewire::test(Overview::class)->html();

    expect(homeTile($html, 'fx'))->toContain('2 off main')
        ->and(homeTile($html, 'tidy'))->toContain('clean')->not->toContain('off main');
});

it('Given a preflight run 6 hours ago taking 3m 12s, then the tile reads "6h ago · 3m 12s"; given no runs, then "no runs yet"', function () {
    Project::factory()->create(['name' => 'story-board', 'path' => '/work/story-board', ...$this->fresh]);
    Project::factory()->create(['name' => 'coins', 'path' => '/work/coins', ...$this->fresh]);
    $header = 'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';
    $this->sessions->preflightCsv(SessionFixture::folderFor('/work/story-board'), [
        $header,
        now()->subHours(30)->format('Y-m-d\TH:i:s\Z').',story-board,main,full,600,5,10,1000,100,0,2048,sonnet',
        now()->subHours(6)->format('Y-m-d\TH:i:s\Z').',story-board,main,scoped,192,5,10,1000,100,0,2048,sonnet',
    ]);

    $html = Livewire::test(Overview::class)->html();

    expect(homeTile($html, 'story-board'))->toContain('6h ago · 3m 12s')->toContain('scoped')->not->toContain('no runs yet')
        ->and(homeTile($html, 'coins'))->toContain('no runs yet');
});

it('Given a project with a production connection and snapshots, then the tile shows the metric\'s label, latest value and a sparkline; given none, then no production row shows', function () {
    $coins = Project::factory()->create(['name' => 'coins', ...$this->fresh]);
    ProdConnection::factory()->for($coins)->create();
    $metric = ProdMetric::factory()->for($coins)->create(['key' => 'wallets', 'label' => 'Active wallets', 'position' => 1]);
    ProdMetric::factory()->for($coins)->create(['key' => 'second', 'label' => 'Second metric', 'position' => 2]);
    // Switched off, though first by position: not read, so never the headline.
    $off = ProdMetric::factory()->for($coins)->create(['key' => 'off', 'label' => 'Disabled metric', 'position' => 0, 'is_enabled' => false]);
    ProdSnapshot::factory()->for($off, 'metric')->create(['project_id' => $coins->id, 'value' => 7]);
    foreach ([6 => 1200, 3 => 1250, 0 => 1284] as $daysAgo => $value) {
        ProdSnapshot::factory()->for($metric, 'metric')->create(['project_id' => $coins->id, 'day' => now()->subDays($daysAgo)->toDateString(), 'value' => $value]);
    }
    // Older than the 7-day line: must not be the latest value.
    ProdSnapshot::factory()->for($metric, 'metric')->create(['project_id' => $coins->id, 'day' => now()->subDays(20)->toDateString(), 'value' => 9999]);
    Project::factory()->create(['name' => 'ledger-app', ...$this->fresh]);

    $html = Livewire::test(Overview::class)->html();
    $tile = homeTile($html, 'coins');

    expect($tile)->toContain('data-tile-prod')->toContain('Active wallets')->toContain('1,284')->not->toContain('Second metric')->not->toContain('Disabled metric')
        ->toMatch('/<svg[^>]*data-sparkline[^>]*aria-hidden="true"/')
        ->and(homeTile($html, 'ledger-app'))->not->toContain('data-tile-prod');
});

it('hides the production row when the connection has no snapshots, or the project has snapshots but no connection', function () {
    $connected = Project::factory()->create(['name' => 'connected', ...$this->fresh]);
    ProdConnection::factory()->for($connected)->create();
    ProdMetric::factory()->for($connected)->create(['label' => 'Total users']);
    $removed = Project::factory()->create(['name' => 'removed', ...$this->fresh]);
    $metric = ProdMetric::factory()->for($removed)->create(['label' => 'Total users']);
    ProdSnapshot::factory()->for($metric, 'metric')->create(['project_id' => $removed->id, 'value' => 5]);

    $html = Livewire::test(Overview::class)->html();

    expect(homeTile($html, 'connected'))->not->toContain('data-tile-prod')
        ->and(homeTile($html, 'removed'))->not->toContain('data-tile-prod');
});

it('Given three `feat(SB-n)` commits and one `docs(SB-n)` commit on the ref this week, then Shipped this week lists the three, newest first, and not the docs commit', function () {
    [$fixture, $project] = shippedFixtureProject($this, 'story-board');
    $fixture->story('SB-1', 'built')->story('SB-2', 'built')->story('SB-3', 'built')->commitAt('chore: stories', now()->subDays(12));
    Story::factory()->for($project)->create(['story_id' => 'SB-2', 'title' => 'Second story', 'status' => 'built']);

    // In committer-date order, as a real history is: `git log --since` stops walking at old commits.
    $fixture->write('e.txt', '1')->commitAt('feat(SB-4): too old', now()->subDays(9));
    $fixture->write('a.txt', '1')->commitAt('feat(SB-1): first', now()->subDays(3));
    $fixture->write('b.txt', '1')->commitAt('feat(SB-2): second', now()->subDays(2));
    $fixture->write('c.txt', '1')->commitAt('docs(SB-2): its docs', now()->subHours(30));
    $fixture->write('d.txt', '1')->commitAt('feat(SB-3): third', now()->subHours(5));
    $fixture->git($fixture->project, 'fetch', '--quiet');

    $html = Livewire::test(Overview::class)->html();

    expect(shippedRowIds($html))->toBe(['story-board/SB-3', 'story-board/SB-2', 'story-board/SB-1'])
        ->and($html)->toContain('Second story')
        ->toContain('href="'.route('stories.show', ['project' => 'story-board', 'storyId' => 'SB-2']).'"')
        // SB-3 is not in the snapshot: no page to open, so its row is not a link and shows the commit's subject.
        ->not->toContain('href="'.route('stories.show', ['project' => 'story-board', 'storyId' => 'SB-3']).'"')
        ->toContain('third');
});

it('counts fix and test commits as build commits and ignores chore, refactor and malformed IDs', function () {
    [$fixture] = shippedFixtureProject($this, 'story-board');
    $fixture->write('a.txt', '1')->commitAt('fix(SB-5): a fix', now()->subDays(1));
    $fixture->write('b.txt', '1')->commitAt('test(MT-2a): a test', now()->subHours(20));
    $fixture->write('c.txt', '1')->commitAt('chore(SB-6): chore', now()->subHours(10));
    $fixture->write('d.txt', '1')->commitAt('refactor(SB-7): refactor', now()->subHours(9));
    $fixture->write('e.txt', '1')->commitAt('feat(x-1): lower case', now()->subHours(8));
    $fixture->write('f.txt', '1')->commitAt('feat: no id', now()->subHours(7));
    $fixture->git($fixture->project, 'fetch', '--quiet');

    $shipped = app(ListShippedThisWeek::class)->handle(ProjectFilter::all());

    expect(array_column($shipped['rows'], 'story_id'))->toBe(['MT-2a', 'SB-5']);
});

it('Given `GitReader` is asked for `log --output=x`, then it refuses and logs the refusal', function () {
    Log::spy();

    expect(fn () => app(GitReader::class)->run('/tmp', ['log', '--output=x']))->toThrow(GitReaderException::class, 'not allowed');

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.git_refused' && $c['subcommand'] === 'log')->once();
});

it('refuses log with anything but the fixed format, one --since and a ref, and logs each refusal', function (array $args) {
    Log::spy();

    expect(fn () => app(GitReader::class)->run('/tmp', $args))->toThrow(GitReaderException::class, 'not allowed');

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.git_refused' && $c['subcommand'] === 'log')->once();
})->with([
    'a patch' => [['log', '--format=%H%x1f%ct%x1f%s', '--since=@1700000000', '-p', 'origin/main', '--']],
    'another format' => [['log', '--format=%B', '--since=@1700000000', 'origin/main', '--']],
    'all refs' => [['log', '--format=%H%x1f%ct%x1f%s', '--since=@1700000000', '--all', '--']],
    'a loose since' => [['log', '--format=%H%x1f%ct%x1f%s', '--since=yesterday', 'origin/main', '--']],
    'an option as ref' => [['log', '--format=%H%x1f%ct%x1f%s', '--since=@1700000000', '--exec=x', '--']],
    'a path' => [['log', '--format=%H%x1f%ct%x1f%s', '--since=@1700000000', 'origin/main', '--', '.env']],
    'bare' => [['log']],
]);

it('reads the log of a ref since a time through GitReader', function () {
    $fixture = new GitFixture;
    $this->fixtures[] = $fixture;
    $fixture->write('a.txt', '1')->commitAt('feat(SB-1): old', now()->subDays(10));
    $at = now()->subDays(1);
    $fixture->write('b.txt', '1')->commitAt('feat(SB-2): new', $at);
    $fixture->git($fixture->project, 'fetch', '--quiet');

    $log = app(GitReader::class)->log($fixture->project, 'origin/main', now()->subDays(7));

    expect(array_column($log, 'subject'))->toBe(['feat(SB-2): new'])
        ->and($log[0]['sha'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($log[0]['at']->timestamp)->toBe($at->timestamp);
});

it('logs board.shipped_read with the project and its count', function () {
    Log::spy();
    [$fixture] = shippedFixtureProject($this, 'story-board');
    $fixture->write('a.txt', '1')->commitAt('feat(SB-1): one', now()->subDays(1));
    $fixture->git($fixture->project, 'fetch', '--quiet');

    app(ListShippedThisWeek::class)->handle(ProjectFilter::all());

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.shipped_read' && $c === ['project' => 'story-board', 'count' => 1])->once();
});

it('reads each project\'s log once while its snapshot is unchanged, so the 30s poll does not re-run git', function () {
    [$fixture] = shippedFixtureProject($this, 'story-board');
    $fixture->write('a.txt', '1')->commitAt('feat(SB-1): one', now()->subDays(1));
    $fixture->git($fixture->project, 'fetch', '--quiet');
    Log::spy();

    $shipped = app(ListShippedThisWeek::class);
    $shipped->handle(ProjectFilter::all());
    $shipped->handle(ProjectFilter::all());

    Log::shouldHaveReceived('info')->withArgs(fn ($e) => $e === 'board.shipped_read')->once();
});

it('lists the other projects and names the one whose git history could not be read', function () {
    Log::spy();
    [$fixture] = shippedFixtureProject($this, 'story-board');
    $fixture->write('a.txt', '1')->commitAt('feat(SB-1): one', now()->subDays(1));
    $fixture->git($fixture->project, 'fetch', '--quiet');
    Project::factory()->create(['name' => 'gone', 'path' => '/nonexistent/gone', ...$this->fresh]);

    $html = Livewire::test(Overview::class)->html();

    expect(shippedRowIds($html))->toBe(['story-board/SB-1'])
        ->and($html)->toContain('data-shipped-failed="gone"')->toContain('Could not read git history for gone');
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.shipped_unreadable' && $c['project'] === 'gone')->once();
});

it('shows the empty state when nothing shipped in the last 7 days', function () {
    [$fixture] = shippedFixtureProject($this, 'story-board');
    $fixture->write('a.txt', '1')->commitAt('docs(SB-1): only docs', now()->subDays(1));
    $fixture->git($fixture->project, 'fetch', '--quiet');

    Livewire::test(Overview::class)
        ->assertSeeHtml('data-shipped-panel="empty"')
        ->assertSee('Nothing shipped in the last 7 days.');
});

it('shows each new panel\'s error state alone when its source throws', function (string $action, string $panel) {
    Log::spy();
    Project::factory()->create(['name' => 'coins', ...$this->fresh]);
    $this->mock($action)->shouldReceive('handle')->andThrow(new RuntimeException('boom'));

    $html = Livewire::test(Overview::class)->html();

    expect($html)->toContain("data-{$panel}-panel=\"error\"")
        ->and($html)->toContain('data-needs-panel="');
    Log::shouldHaveReceived('error')->withArgs(fn ($e, $c = []) => $e === 'board.overview_panel_failed' && $c['panel'] === $panel)->once();
})->with([
    'tiles' => [ListProjectTiles::class, 'tiles'],
    'shipped' => [ListShippedThisWeek::class, 'shipped'],
]);

it('marks a tile live while a session is running in its project', function () {
    Project::factory()->create(['name' => 'coins', 'path' => '/work/coins', ...$this->fresh]);
    Project::factory()->create(['name' => 'ledger-app', 'path' => '/work/ledger-app', ...$this->fresh]);
    $this->sessions->session('/work/coins', 'main', 'bbbbbbbb-0000-0000-0000-000000000001', 1);

    $html = Livewire::test(Overview::class)->html();

    expect(homeTile($html, 'coins'))->toContain('data-tile-live')
        ->and(homeTile($html, 'ledger-app'))->not->toContain('data-tile-live');
});

it('leaves a tile\'s last preflight without where it ran when no run record matches it (RD-8)', function () {
    $project = Project::factory()->create(['name' => 'story-board', 'path' => '/work/story-board', ...$this->fresh]);
    $this->sessions->preflightCsv(SessionFixture::folderFor('/work/story-board'), [
        'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model',
        now()->subHours(2)->format('Y-m-d\TH:i:s\Z').',story-board,main,full,40,5,10,1000,100,0,2048,sonnet',
    ]);

    $tile = collect(app(ListProjectTiles::class)->handle(ProjectFilter::all()))->firstWhere('name', 'story-board');

    expect($tile['preflight'])->toMatchArray(['wall' => 40, 'mode' => 'full', 'ran_on' => null])
        ->and($project->name)->toBe('story-board');
});

it('Given the RD-6 filter set to one project, then only its tile and its shipped rows show', function () {
    [$sb] = shippedFixtureProject($this, 'story-board');
    [$coins] = shippedFixtureProject($this, 'coins');
    $sb->write('a.txt', '1')->commitAt('feat(SB-1): one', now()->subDays(1));
    $coins->write('a.txt', '1')->commitAt('feat(CO-1): one', now()->subDays(2));
    $sb->git($sb->project, 'fetch', '--quiet');
    $coins->git($coins->project, 'fetch', '--quiet');

    $all = Livewire::test(Overview::class)->html();
    $one = Livewire::withQueryParams(['projects' => 'coins'])->test(Overview::class)->html();

    expect(homeTile($all, 'story-board'))->not->toBe('')
        ->and(shippedRowIds($all))->toBe(['story-board/SB-1', 'coins/CO-1'])
        ->and(homeTile($one, 'coins'))->not->toBe('')
        ->and(homeTile($one, 'story-board'))->toBe('')
        ->and(shippedRowIds($one))->toBe(['coins/CO-1']);
});
