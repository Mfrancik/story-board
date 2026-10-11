<?php

use App\Actions\Board\ReadProductionProject;
use App\Console\Commands\BoardProdSnapshot;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProductionFixture;

/**
 * SB-18's production read and daily snapshot, at the action and command level. These assertions lived in
 * ProductionDashboardTest until RD-9 deleted /prod; ReadProductionProject and `board:prod-snapshot` stay
 * because RD-7's project tiles draw their numbers and trend lines from the snapshots. The "production"
 * database is tests/Support/ProductionFixture, never a real one. Time is frozen at 2026-09-29 16:00 UTC —
 * noon in board.timezone (America/New_York).
 */
beforeEach(function () {
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
 * Give a project a connection to the fixture as `who` (or `unreachable`: a closed local port) and the
 * Total users and New today presets.
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
 * Read one project now, as the snapshot command does.
 *
 * @return array<string, mixed>|null the recorded outcome, or null when the read was skipped
 */
function readProdNow(Project $project): ?array
{
    return app(ReadProductionProject::class)->handle($project->fresh());
}

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

it('refuses the read of a connection whose grants now include a write privilege, queries nothing and says so', function () {
    connectProd($this->coins, $this->fx, 'rw');

    $outcome = readProdNow($this->coins);

    expect($outcome['ok'])->toBeFalse()
        ->and($outcome['reason'])->toBe('can_write')
        ->and($outcome['message'])->toBe('This user can write (INSERT) — create a SELECT-only user.')
        ->and(ProdSnapshot::count())->toBe(0);
    $read = ($this->logged)('board.prod_read')->sole();
    expect($read->context['metrics'])->toBe(0)
        ->and($read->context['ok'])->toBeFalse();
    expect(($this->logged)('board.prod_read_failed')->sole()->context)->toBe(['project' => 'coins', 'reason' => 'can_write']);
});

it('logs board.prod_read with project, metrics, ms and ok for each read, and never a credential or host', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx, 'unreachable');

    readProdNow($this->coins);
    readProdNow($this->rent);

    $reads = ($this->logged)('board.prod_read')->keyBy(fn (MessageLogged $m) => $m->context['project']);
    expect($reads)->toHaveCount(2)
        ->and(array_keys($reads['coins']->context))->toBe(['project', 'metrics', 'ms', 'ok'])
        ->and($reads['coins']->level)->toBe('info')
        ->and($reads['coins']->context['metrics'])->toBe(2)
        ->and($reads['coins']->context['ms'])->toBeInt()
        ->and($reads['coins']->context['ok'])->toBeTrue()
        ->and($reads['rent-track']->context['ok'])->toBeFalse();
    expect(($this->logged)('board.prod_read_failed')->sole()->context)->toBe(['project' => 'rent-track', 'reason' => 'unreachable']);

    $this->logs->each(fn (MessageLogged $m) => expect(json_encode($m->context))
        ->not->toContain($this->fx->password)
        ->not->toContain($this->fx->users['ro'])
        ->not->toContain('127.0.0.1'));
});

it('skips and logs a read whose project was switched off or disconnected after it was asked for', function () {
    $connection = connectProd($this->coins, $this->fx);
    $this->rent->update(['is_enabled' => false]);
    connectProd($this->rent, $this->fx);

    expect(readProdNow($this->rent))->toBeNull();
    $connection->delete();
    expect(readProdNow($this->coins))->toBeNull();

    expect(ProdSnapshot::count())->toBe(0);
    expect(($this->logged)('board.prod_read_skipped')->pluck('context')->all())->toBe([
        ['project' => 'rent-track', 'reason' => 'disabled'],
        ['project' => 'coins', 'reason' => 'not_connected'],
    ]);
});

it('keeps the other metrics when one metric fails on its own, and logs only that metric\'s reason code', function () {
    connectProd($this->coins, $this->fx);
    $custom = ProdMetric::factory()->custom('SELECT count(*) FROM no_such_table')->create([
        'project_id' => $this->coins->id, 'label' => 'Listings live', 'position' => 2]);

    $outcome = readProdNow($this->coins);

    expect(ProdSnapshot::count())->toBe(2)
        ->and($outcome['ok'])->toBeFalse()
        ->and($outcome['status'])->toBe('ok')
        ->and($outcome['metrics'])->toHaveKey($custom->key)
        ->and($outcome['metrics'][$custom->key]['message'])->toContain('no_such_table');
    $failed = ($this->logged)('board.prod_read_failed')->sole();
    expect($failed->context['reason'])->toBe('metrics_failed')
        ->and(array_values($failed->context['metrics']))->toBe(['query_failed'])
        ->and(json_encode($failed->context))->not->toContain('no_such_table');
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

it('skips a disabled project\'s snapshot when board:prod-snapshot runs, and counts it as skipped', function () {
    connectProd($this->coins, $this->fx);
    connectProd($this->rent, $this->fx);
    $this->rent->update(['is_enabled' => false]);

    $this->artisan(BoardProdSnapshot::class)->assertSuccessful();

    expect(ProdSnapshot::where('project_id', $this->rent->id)->count())->toBe(0)
        ->and(ProdSnapshot::where('project_id', $this->coins->id)->count())->toBe(2)
        ->and(($this->logged)('board.prod_snapshot_run')->sole()->context)
        ->toBe(['projects' => 1, 'ok' => 1, 'failed' => 0, 'skipped' => 1]);
});

it('schedules board:prod-snapshot every day at 23:55 in the board timezone', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'board:prod-snapshot'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('55 23 * * *')
        ->and($event->timezone)->toBe('America/New_York');
});
