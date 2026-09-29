<?php

use App\Console\Commands\BoardProdSnapshot;
use App\Jobs\ReadProductionMetrics;
use App\Livewire\Board\ProductionDashboard;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ProductionFixture;

/**
 * SB-18 acceptance criteria: /prod shows every connected project's metrics side
 * by side, read live through the queue (one ReadProductionMetrics job per
 * project), snapshotted once per metric per local day, with changes against
 * yesterday's and 7-days-ago's snapshots. The "production" database is
 * tests/Support/ProductionFixture, never a real one. Time is frozen at
 * 2026-09-29 16:00 UTC — noon in board.timezone (America/New_York).
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-29 16:00:00', 'UTC'));
    config(['board.timezone' => 'America/New_York']);

    $fresh = ['state' => Project::STATE_OK, 'indexed_at' => now(), 'refresh_attempted_at' => now()];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->rent = Project::factory()->create(['name' => 'rent-track', ...$fresh]);

    $this->fx = ProductionFixture::boot();
    // Local "today" is 2026-09-29 04:00 UTC to 2026-09-30 04:00 UTC: bob and cy were created today.
    $this->fx->seed([
        ['name' => 'ada', 'created_at' => '2026-09-01 10:00:00', 'last_seen_at' => null],
        ['name' => 'bob', 'created_at' => '2026-09-29 05:00:00', 'last_seen_at' => null],
        ['name' => 'cy', 'created_at' => '2026-09-29 15:00:00', 'last_seen_at' => null],
        ['name' => 'di', 'created_at' => '2026-09-11 10:00:00', 'last_seen_at' => null],
    ]);

    $this->logs = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->logs->push($m));
    $this->logged = fn (string $event) => $this->logs->filter(fn (MessageLogged $m) => $m->message === $event)->values();
});

afterAll(fn () => ProductionFixture::drop());

/**
 * Give a project a connection to the fixture as `who` (or `unreachable`: a closed
 * local port) and the Total users and New today presets.
 *
 * @param  'ro'|'rw'|'all'|'unreachable'  $who
 */
function connectProd(Project $project, ProductionFixture $fx, string $who = 'ro'): ProdConnection
{
    $connection = $who === 'unreachable'
        ? ProdConnection::factory()->create(['project_id' => $project->id])
        : ProdConnection::factory()->create(['project_id' => $project->id, ...collect($fx->input($who))
            ->only(['host', 'database', 'username', 'password'])->all(), 'port' => $fx->port, 'use_ssl' => false]);

    ProdMetric::factory()->create(['project_id' => $project->id, 'key' => 'total_users', 'label' => 'Total users', 'position' => 0]);
    ProdMetric::factory()->create(['project_id' => $project->id, 'key' => 'new_today', 'label' => 'New today',
        'config' => ['table' => 'users', 'column' => 'created_at'], 'position' => 1]);

    return $connection;
}

/**
 * Run every ReadProductionMetrics job the fake queue caught, as a worker would —
 * including releasing its unique lock afterwards — and forget them.
 */
function runProdReads(): void
{
    $jobs = Queue::pushed(ReadProductionMetrics::class);
    Queue::fake();
    $jobs->each(function (ReadProductionMetrics $job) {
        app()->call([$job, 'handle']);
        (new UniqueLock(app(Cache::class)))->release($job);
    });
}

/**
 * Run one project's read job right now, as a worker would (the queue is faked).
 */
function readProdNow(Project $project): void
{
    app()->call([new ReadProductionMetrics($project), 'handle']);
}

/**
 * A snapshot for one metric on a local day.
 */
function prodSnap(Project $project, string $key, string $day, float $value): ProdSnapshot
{
    $metric = ProdMetric::where('project_id', $project->id)->where('key', $key)->firstOrFail();

    return ProdSnapshot::factory()->create(['project_id' => $project->id, 'prod_metric_id' => $metric->id,
        'day' => $day, 'value' => $value, 'read_at' => Carbon::parse($day.' 23:55', 'America/New_York')->utc()]);
}

/**
 * The dashboard's row for a project, as handed to the view.
 *
 * @return array<string, mixed>
 */
function prodRow($test, Project $project): array
{
    return collect($test->viewData('rows'))->firstWhere('name', $project->name) ?? [];
}

it('renders both connected projects with a skeleton and fills in each metric\'s value from a queued read', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx);

    $this->get('/prod')->assertOk()->assertSeeLivewire(ProductionDashboard::class);
    runProdReads();
    ProdSnapshot::query()->delete();

    $test = Livewire::test(ProductionDashboard::class)
        ->assertSeeHtml('data-prod-skeleton="coins"')
        ->assertSeeHtml('data-prod-skeleton="rent-track"');
    Queue::assertPushed(ReadProductionMetrics::class, 2);
    Queue::assertPushed(ReadProductionMetrics::class, fn (ReadProductionMetrics $job) => $job->project->is($this->coins));
    Queue::assertPushed(ReadProductionMetrics::class, fn (ReadProductionMetrics $job) => $job->project->is($this->rent));

    runProdReads();
    $test->call('poll')
        ->assertDontSeeHtml('data-prod-skeleton="coins"')
        ->assertDontSeeHtml('data-prod-skeleton="rent-track"')
        ->assertSeeHtml('data-prod-value="coins:total_users"')
        ->assertSeeHtml('data-prod-value="rent-track:new_today"');

    foreach ([$this->coins, $this->rent] as $project) {
        $row = prodRow($test, $project);
        expect($row['state'])->toBe('ok')
            ->and($row['metrics']['total_users']['value'])->toBe(4.0)
            ->and($row['metrics']['new_today']['value'])->toBe(2.0);
    }
});

it('stores one snapshot per project, metric and local day, and a second read the same day updates it', function () {
    connectProd($this->coins, $this->fx);
    $total = ProdMetric::where('key', 'total_users')->first();

    readProdNow($this->coins);
    expect(ProdSnapshot::count())->toBe(2);
    $row = ProdSnapshot::where('prod_metric_id', $total->id)->sole();
    expect($row->project_id)->toBe($this->coins->id)
        ->and($row->day->toDateString())->toBe('2026-09-29')
        ->and($row->value)->toBe(4.0);

    // Later the same local day (23:30 New York is already the 30th in UTC), with one more user.
    $this->fx->seed([...array_fill(0, 5, ['name' => 'x', 'created_at' => '2026-09-01 10:00:00', 'last_seen_at' => null])]);
    $this->travelTo(Carbon::parse('2026-09-30 03:30:00', 'UTC'));
    readProdNow($this->coins);

    $row = ProdSnapshot::where('prod_metric_id', $total->id)->sole();
    expect(ProdSnapshot::count())->toBe(2)
        ->and($row->day->toDateString())->toBe('2026-09-29')
        ->and($row->value)->toBe(5.0)
        ->and($row->read_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-30 03:30');

    // Past local midnight a new day starts a new row.
    $this->travelTo(Carbon::parse('2026-09-30 04:30:00', 'UTC'));
    readProdNow($this->coins);
    expect(ProdSnapshot::where('prod_metric_id', $total->id)->pluck('day')->map->toDateString()->all())
        ->toEqualCanonicalizing(['2026-09-29', '2026-09-30']);
});

it('shows each stat\'s change against the snapshots for yesterday and 7 days ago', function () {
    connectProd($this->coins, $this->fx);
    prodSnap($this->coins, 'total_users', '2026-09-22', 1);
    prodSnap($this->coins, 'total_users', '2026-09-28', 6);
    readProdNow($this->coins);

    $test = Livewire::test(ProductionDashboard::class);
    runProdReads();
    $test->call('poll');

    $stat = prodRow($test, $this->coins)['metrics']['total_users'];
    expect($stat['value'])->toBe(4.0)
        ->and($stat['day'])->toBe(['text' => '−2', 'dir' => 'down'])
        ->and($stat['week'])->toBe(['text' => '+3', 'dir' => 'up'])
        ->and($stat['series'])->toHaveCount(30);
    $test->assertSeeHtml('data-prod-change="coins:total_users:day"');
    expect($test->html())->toMatch('/−2.*?vs yest\..*?\+3.*?vs 7 d ago/s');
});

it('shows — for the change when there is no snapshot from 7 days ago', function () {
    connectProd($this->coins, $this->fx);
    prodSnap($this->coins, 'total_users', '2026-09-28', 4);
    readProdNow($this->coins);

    $test = Livewire::test(ProductionDashboard::class);
    runProdReads();
    $test->call('poll');

    $stat = prodRow($test, $this->coins)['metrics']['total_users'];
    expect($stat['day'])->toBe(['text' => '±0', 'dir' => 'flat'])
        ->and($stat['week'])->toBe(['text' => '—', 'dir' => 'none']);
    expect($test->html())->toMatch('/±0.*?vs yest\..*?—.*?vs 7 d ago/s');
});

it('shows "Unreachable" with the last good values greyed and timed for one project while the other project\'s numbers still show', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx, 'unreachable');
    $good = prodSnap($this->rent, 'total_users', '2026-09-29', 23);
    $good->update(['read_at' => Carbon::parse('2026-09-29 13:00:00', 'UTC')]);

    $test = Livewire::test(ProductionDashboard::class);
    runProdReads();
    $test->call('poll');

    $rent = prodRow($test, $this->rent);
    expect($rent['state'])->toBe('error')
        ->and($rent['error']['label'])->toBe('Unreachable')
        ->and($rent['metrics']['total_users']['value'])->toBe(23.0)
        ->and($rent['metrics']['total_users']['stale'])->toBeTrue()
        ->and($rent['metrics']['total_users']['readAgo'])->toBe('3 hours ago');
    $coins = prodRow($test, $this->coins);
    expect($coins['state'])->toBe('ok')
        ->and($coins['metrics']['total_users']['value'])->toBe(4.0)
        ->and($coins['metrics']['total_users']['stale'])->toBeFalse();

    $test->assertSee('Unreachable')
        ->assertSee('last good read 3 hours ago')
        ->assertSeeHtml('data-prod-stale="rent-track:total_users"')
        ->assertDontSeeHtml('data-prod-stale="coins:total_users"');

    $failed = ($this->logged)('board.prod_read_failed');
    expect($failed)->toHaveCount(1)
        ->and($failed[0]->level)->toBe('warning')
        ->and($failed[0]->context)->toBe(['project' => 'rent-track', 'reason' => 'unreachable']);
});

it('refuses the read of a connection whose grants now include a write privilege, queries nothing and says so', function () {
    connectProd($this->coins, $this->fx, 'rw');

    $test = Livewire::test(ProductionDashboard::class);
    runProdReads();
    $test->call('poll')
        ->assertSee('Refused: this user can now write')
        ->assertSee('This user can write (INSERT) — create a SELECT-only user.');

    expect(ProdSnapshot::count())->toBe(0)
        ->and(prodRow($test, $this->coins)['state'])->toBe('error');
    $read = ($this->logged)('board.prod_read')->sole();
    expect($read->context['metrics'])->toBe(0)
        ->and($read->context['ok'])->toBeFalse();
    expect(($this->logged)('board.prod_read_failed')->sole()->context)->toBe(['project' => 'coins', 'reason' => 'can_write']);
});

it('reads every connected project\'s enabled metrics once and snapshots them when board:prod-snapshot runs', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx);
    ProdMetric::factory()->create(['project_id' => $this->coins->id, 'key' => 'active_today', 'label' => 'Active today',
        'config' => ['table' => 'users', 'column' => 'last_seen_at'], 'position' => 2, 'is_enabled' => false]);
    Project::factory()->create(['name' => 'asset-track']);

    $this->artisan(BoardProdSnapshot::class)->assertSuccessful();

    expect(ProdSnapshot::count())->toBe(4)
        ->and(ProdSnapshot::where('project_id', $this->coins->id)->count())->toBe(2)
        ->and(ProdSnapshot::where('project_id', $this->rent->id)->count())->toBe(2);
    expect(($this->logged)('board.prod_read'))->toHaveCount(2);
    $run = ($this->logged)('board.prod_snapshot_run')->sole();
    expect($run->level)->toBe('info')
        ->and($run->context)->toBe(['projects' => 2, 'ok' => 2, 'failed' => 0, 'skipped' => 0]);
});

it('schedules board:prod-snapshot every day at 23:55 in the board timezone', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'board:prod-snapshot'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('55 23 * * *')
        ->and($event->timezone)->toBe('America/New_York');
});

it('lists a project with no connection under "Not connected" with a link to /projects', function () {
    connectProd($this->coins, $this->fx);

    $test = Livewire::test(ProductionDashboard::class)
        ->assertSee('Not connected')
        ->assertSeeHtml('data-prod-not-connected="rent-track"')
        ->assertSeeHtml('href="'.route('projects.manage').'"')
        ->assertSee('set up on /projects');

    expect(collect($test->viewData('notConnected'))->pluck('name')->all())->toBe(['rent-track'])
        ->and(collect($test->viewData('rows'))->pluck('name')->all())->toBe(['coins']);
});

it('shows an empty state pointing to /projects and returns 200 when no project is connected', function () {
    $this->get('/prod')->assertOk()
        ->assertSee('No project is connected to production')
        ->assertSee('Set up on /projects')
        ->assertSee(route('projects.manage'));

    Queue::assertNothingPushed();
});

it('leaves a disabled project off /prod and skips its snapshot', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx);
    $this->rent->update(['is_enabled' => false]);

    $test = Livewire::test(ProductionDashboard::class)
        ->assertDontSeeHtml('data-prod-row="rent-track"')
        ->assertDontSeeHtml('data-prod-not-connected="rent-track"');
    expect(collect($test->viewData('rows'))->pluck('name')->all())->toBe(['coins']);
    Queue::assertPushed(ReadProductionMetrics::class, 1);

    $this->artisan(BoardProdSnapshot::class)->assertSuccessful();
    expect(ProdSnapshot::where('project_id', $this->rent->id)->count())->toBe(0)
        ->and(ProdSnapshot::where('project_id', $this->coins->id)->count())->toBe(2)
        ->and(($this->logged)('board.prod_snapshot_run')->sole()->context)
        ->toBe(['projects' => 1, 'ok' => 1, 'failed' => 0, 'skipped' => 1]);
});

it('logs board.prod_viewed with the project count and board.prod_read with project, metrics, ms and ok for each read', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx, 'unreachable');

    Livewire::test(ProductionDashboard::class);
    runProdReads();

    $viewed = ($this->logged)('board.prod_viewed')->sole();
    expect($viewed->level)->toBe('info')->and($viewed->context)->toBe(['projects' => 2]);

    $reads = ($this->logged)('board.prod_read')->keyBy(fn (MessageLogged $m) => $m->context['project']);
    expect($reads)->toHaveCount(2)
        ->and(array_keys($reads['coins']->context))->toBe(['project', 'metrics', 'ms', 'ok'])
        ->and($reads['coins']->level)->toBe('info')
        ->and($reads['coins']->context['metrics'])->toBe(2)
        ->and($reads['coins']->context['ms'])->toBeInt()
        ->and($reads['coins']->context['ok'])->toBeTrue()
        ->and($reads['rent-track']->context['ok'])->toBeFalse();

    // Never a value, a credential or MySQL's own error text in a log line.
    $this->logs->each(fn (MessageLogged $m) => expect(json_encode($m->context))
        ->not->toContain($this->fx->password)
        ->not->toContain($this->fx->users['ro'])
        ->not->toContain('127.0.0.1'));
});

it('re-reads one project when its Refresh button is pressed', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx);

    $test = Livewire::test(ProductionDashboard::class);
    runProdReads();
    $test->call('poll')->assertDontSeeHtml('data-prod-skeleton="coins"');

    $test->call('refresh', $this->coins->id)
        ->assertSeeHtml('data-prod-skeleton="coins"')
        ->assertDontSeeHtml('data-prod-skeleton="rent-track"');
    Queue::assertPushed(ReadProductionMetrics::class, 1);
    Queue::assertPushed(ReadProductionMetrics::class, fn (ReadProductionMetrics $job) => $job->project->is($this->coins));

    runProdReads();
    $test->call('poll')->assertDontSeeHtml('data-prod-skeleton="coins"');
});

it('ignores and logs a Refresh for a project that is not shown on /prod', function () {
    connectProd($this->coins, $this->fx);
    $test = Livewire::test(ProductionDashboard::class);
    Queue::fake();

    $test->call('refresh', $this->rent->id)->call('refresh', 999999);

    Queue::assertNothingPushed();
    $refused = ($this->logged)('board.prod_refresh_refused');
    expect($refused)->toHaveCount(2)
        ->and($refused[0]->level)->toBe('warning')
        ->and($refused[0]->context)->toBe(['project_id' => $this->rent->id]);
});

it('queues one read per project however often the page loads', function () {
    connectProd($this->coins, $this->fx);

    Livewire::test(ProductionDashboard::class);
    Livewire::test(ProductionDashboard::class);

    Queue::assertPushed(ReadProductionMetrics::class, 1);
});

it('skips and logs a queued read whose project was switched off or disconnected after it was queued', function () {
    $connection = connectProd($this->coins, $this->fx);
    $this->rent->update(['is_enabled' => false]);
    connectProd($this->rent, $this->fx);

    readProdNow($this->rent);
    $connection->delete();
    readProdNow($this->coins);

    expect(ProdSnapshot::count())->toBe(0);
    $skipped = ($this->logged)('board.prod_read_skipped');
    expect($skipped->pluck('context')->all())->toBe([
        ['project' => 'rent-track', 'reason' => 'disabled'],
        ['project' => 'coins', 'reason' => 'not_connected'],
    ]);
});

it('keeps the other metrics when one metric fails on its own, and shows that metric\'s error', function () {
    connectProd($this->coins, $this->fx);
    ProdMetric::factory()->custom('SELECT count(*) FROM no_such_table')->create([
        'project_id' => $this->coins->id, 'label' => 'Listings live', 'position' => 2]);

    $test = Livewire::test(ProductionDashboard::class);
    runProdReads();
    $test->call('poll')->assertSee('Listings live')->assertSee('Custom');

    expect(ProdSnapshot::count())->toBe(2);
    $row = prodRow($test, $this->coins);
    $custom = collect($row['metrics'])->firstWhere('label', 'Listings live');
    expect($row['state'])->toBe('ok')
        ->and($custom['error'])->toContain('no_such_table')
        ->and($row['metrics']['total_users']['value'])->toBe(4.0);
    $failed = ($this->logged)('board.prod_read_failed')->sole();
    expect($failed->context['reason'])->toBe('metrics_failed')
        ->and(array_values($failed->context['metrics']))->toBe(['query_failed'])
        ->and(json_encode($failed->context))->not->toContain('no_such_table');
});

it('stops waiting and says so when no queue worker answers within a minute', function () {
    connectProd($this->coins, $this->fx);
    $test = Livewire::test(ProductionDashboard::class)->assertSeeHtml('data-prod-skeleton="coins"');

    $this->travel(30)->seconds();
    $test->call('poll')->assertSeeHtml('data-prod-skeleton="coins"');

    $this->travel(31)->seconds();
    $test->call('poll')
        ->assertDontSeeHtml('data-prod-skeleton="coins"')
        ->assertSee('No answer from the queue worker');
    expect(($this->logged)('board.prod_read_stalled')->sole()->context)->toBe(['project' => 'coins']);
});

it('links Production from the sidebar with how many shown projects are connected', function () {
    connectProd($this->coins, $this->fx);

    $this->get('/')->assertOk()
        ->assertSeeHtml('data-sidebar-prod')
        ->assertSee('1/2');
    $page = $this->get('/prod')->assertOk()->assertSee('· Production');
    expect($page->getContent())->toMatch('/data-sidebar-prod\s+aria-current="page"/');
});
