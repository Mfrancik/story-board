<?php

use App\Livewire\Board\ManageProjects;
use App\Livewire\Board\ProductionSettings;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Models\Project;
use App\Services\ProductionReader;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ProductionFixture;

/**
 * SB-17 acceptance criteria: a project's read-only production connection on
 * /projects — saved only after SHOW GRANTS proves it read-only, credentials
 * encrypted and never shown back — and its metrics (presets and custom SELECTs),
 * each tested against a real second MySQL database (tests/Support/ProductionFixture).
 * Every refusal has a test and a log line (L-5).
 */
beforeEach(function () {
    Queue::fake();
    $this->coins = Project::factory()->create(['name' => 'coins', 'state' => Project::STATE_OK, 'indexed_at' => now()]);
    $this->fx = ProductionFixture::boot();
    $this->fx->seed([
        ['name' => 'ada', 'created_at' => '2026-09-01 10:00:00', 'last_seen_at' => '2026-09-29 03:59:00'],
        ['name' => 'bob', 'created_at' => '2026-09-29 05:00:00', 'last_seen_at' => '2026-09-29 04:00:30'],
        ['name' => 'cy', 'created_at' => '2026-09-10 10:00:00', 'last_seen_at' => '2026-09-29 15:00:00'],
        ['name' => 'di', 'created_at' => '2026-09-11 10:00:00', 'last_seen_at' => null],
    ]);

    // Every log line of the test, to prove what is — and is never — logged.
    $this->logs = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->logs->push($m));
});

afterAll(fn () => ProductionFixture::drop());

/**
 * The Production panel for coins with the form filled from the fixture.
 *
 * @param  'ro'|'rw'|'all'  $who
 */
function prodForm(Project $project, ProductionFixture $fx, string $who = 'ro'): Testable
{
    $test = Livewire::test(ProductionSettings::class, ['project' => $project]);
    foreach ($fx->input($who) as $field => $value) {
        $test->set($field, $value);
    }

    return $test;
}

/**
 * Save the fixture's read-only user as coins's connection, the way the form does.
 */
function saveReadOnly(Project $project, ProductionFixture $fx): void
{
    prodForm($project, $fx)->call('save')->assertHasNoErrors();
    expect(ProdConnection::where('project_id', $project->id)->exists())->toBeTrue();
}

/**
 * The captured log lines named `event`.
 *
 * @param  Collection<int, MessageLogged>  $logs
 * @return Collection<int, MessageLogged>
 */
function logsNamed($logs, string $event)
{
    return $logs->filter(fn (MessageLogged $m) => $m->message === $event)->values();
}

it('stores valid read-only credentials with host, database, username and password encrypted at rest, and never renders the password', function () {
    $test = prodForm($this->coins, $this->fx)->call('save')
        ->assertHasNoErrors()
        ->assertSee('Connection saved — read-only confirmed')
        ->assertSee('SELECT only')
        ->assertDontSee($this->fx->password);

    $row = DB::table('prod_connections')->where('project_id', $this->coins->id)->first();
    expect($row)->not->toBeNull();
    foreach (['host' => $this->fx->host, 'database' => $this->fx->database, 'username' => $this->fx->users['ro'], 'password' => $this->fx->password] as $column => $plain) {
        expect($row->{$column})->not->toContain($plain)
            ->and(Crypt::decryptString($row->{$column}))->toBe($plain);
    }
    expect((bool) $row->use_ssl)->toBeTrue()
        ->and($row->verified_at)->not->toBeNull()
        ->and($test->get('password'))->toBe('');

    // A fresh panel and the whole page: the password is never rendered back.
    Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->assertSee('saved — leave blank to keep')
        ->assertSet('password', '')
        ->assertDontSee($this->fx->password);
    $this->get('/projects')->assertOk()->assertDontSee($this->fx->password);
});

it('refuses credentials whose grants include INSERT with "this user can write (INSERT) — create a SELECT-only user", and stores nothing', function () {
    prodForm($this->coins, $this->fx, 'rw')->call('save')
        ->assertSee('This user can write (INSERT) — create a SELECT-only user.')
        ->assertSee('Nothing was saved.')
        ->assertDontSee($this->fx->password);

    expect(ProdConnection::count())->toBe(0);
});

it('refuses GRANT ALL the same way', function () {
    prodForm($this->coins, $this->fx, 'all')->call('save')
        ->assertSee('This user can write (ALL) — create a SELECT-only user.')
        ->assertSee('Nothing was saved.');

    expect(ProdConnection::count())->toBe(0);
});

it('says unreachable within 6 s for an unreachable host, and nothing hangs', function () {
    $started = microtime(true);

    // Port 1 on this machine: closed, so the connect fails at once rather than waiting out the timeout.
    prodForm($this->coins, $this->fx)->set('port', '1')->call('testConnection')
        ->assertSee('Unreachable')
        ->assertSee('Not reachable, so grants and version were not checked');

    expect(microtime(true) - $started)->toBeLessThan(6.0)
        ->and(ProdConnection::count())->toBe(0);
});

it('keeps the stored password when the edit form is saved with the password left blank', function () {
    saveReadOnly($this->coins, $this->fx);

    Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->assertSet('host', $this->fx->host)
        ->assertSet('password', '')
        ->set('useSsl', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Connection saved — read-only confirmed');

    $connection = ProdConnection::where('project_id', $this->coins->id)->sole();
    expect($connection->password)->toBe($this->fx->password)
        ->and($connection->use_ssl)->toBeFalse();
});

it('asks for the password again when the host changes and the password is left blank', function () {
    saveReadOnly($this->coins, $this->fx);

    Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->set('host', 'localhost')
        ->call('save')
        ->assertHasErrors(['password']);

    expect(ProdConnection::sole()->host)->toBe($this->fx->host)
        ->and(logsNamed($this->logs, 'board.prod_connection_refused')->last()?->context)
        ->toMatchArray(['project' => 'coins', 'reason' => 'password_needed']);
});

it('shows the number when a custom metric SELECT count(*) FROM users is tested', function () {
    saveReadOnly($this->coins, $this->fx);

    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('addCustom')
        ->set('customs.0.label', 'Users')
        ->set('customs.0.sql', 'SELECT count(*) FROM users')
        ->call('testCustom', 0);

    $key = $test->get('customs.0.key');
    expect($test->get("results.{$key}.status"))->toBe('ok')
        ->and($test->get("results.{$key}.value"))->toBe(4);
    $test->assertSeeHtml('data-metric-value="4"');
});

it('refuses a custom metric with two statements or starting with UPDATE, DELETE, INSERT, DROP, SET or CALL before it reaches the database', function (string $sql, string $why) {
    // The saved connection points at a closed port: had the SQL been sent, the result would say "Unreachable".
    ProdConnection::factory()->for($this->coins)->create(['host' => '127.0.0.1', 'port' => 1]);

    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('addCustom')
        ->set('customs.0.label', 'Bad')
        ->set('customs.0.sql', $sql)
        ->call('testCustom', 0);

    $key = $test->get('customs.0.key');
    expect($test->get("results.{$key}.status"))->toBe('refused')
        ->and($test->get("results.{$key}.message"))->toContain('One SELECT only')->toContain($why)->not->toContain('Unreachable')
        ->and(logsNamed($this->logs, 'board.prod_connection_refused'))->toBeEmpty()
        ->and(logsNamed($this->logs, 'board.prod_metric_tested')->sole()->context)
        ->toMatchArray(['project' => 'coins', 'result' => 'refused', 'reason' => 'sql_refused']);
})->with([
    'two statements' => ['SELECT count(*) FROM users; SELECT 1', 'has 2 statements'],
    'UPDATE' => ['UPDATE users SET name = 1', 'starts with UPDATE'],
    'DELETE' => ['DELETE FROM users', 'starts with DELETE'],
    'INSERT' => ['INSERT INTO users (name) VALUES (1)', 'starts with INSERT'],
    'DROP' => ['DROP TABLE users', 'starts with DROP'],
    'SET' => ['SET @n = 1', 'starts with SET'],
    'CALL' => ['CALL purge_users()', 'starts with CALL'],
]);

it('refuses a custom metric returning two columns or two rows with "must return one number"', function (string $sql) {
    saveReadOnly($this->coins, $this->fx);

    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('addCustom')
        ->set('customs.0.label', 'Two')
        ->set('customs.0.sql', $sql)
        ->call('testCustom', 0)
        ->assertSee('must return one number');

    expect($test->get('results.'.$test->get('customs.0.key').'.status'))->toBe('refused');
})->with([
    'two columns' => ['SELECT count(*), 1 FROM users'],
    'two rows' => ['SELECT id FROM users WHERE id <= 2'],
]);

it('stops a query running longer than 5 s and reports it as timed out', function () {
    saveReadOnly($this->coins, $this->fx);
    $started = microtime(true);

    // A four-way cross join of ~290 collations is ~7 billion rows (the three-way one takes ~2.5 s): far past 5 s,
    // so max_execution_time stops it with an error rather than a value.
    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('addCustom')
        ->set('customs.0.label', 'Slow')
        ->set('customs.0.sql', 'SELECT count(*) FROM information_schema.COLLATIONS a, information_schema.COLLATIONS b, information_schema.COLLATIONS c, information_schema.COLLATIONS d')
        ->call('testCustom', 0);

    expect($test->get('results.'.$test->get('customs.0.key')))->toMatchArray(['status' => 'timed_out'])
        ->and($test->html())->toContain('Timed out')
        ->and(microtime(true) - $started)->toBeLessThan(8.0);
});

it('counts users seen since local midnight for the Active today preset pointed at users.last_seen_at', function () {
    saveReadOnly($this->coins, $this->fx);
    // Noon in New York is 16:00 UTC; local midnight is 04:00 UTC. ada (03:59) is yesterday; bob and cy are today.
    config(['board.timezone' => 'America/New_York']);
    Carbon::setTestNow(Carbon::parse('2026-09-29 16:00:00', 'UTC'));

    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->set('presets.active_today.enabled', true)
        ->set('presets.active_today.table', 'users')
        ->set('presets.active_today.column', 'last_seen_at')
        ->call('testPreset', 'active_today')
        ->assertSeeHtml('data-metric-value="2"')
        ->assertSee('2026-09-29 04:00:00');

    expect($test->get('results.active_today.value'))->toBe(2);
});

it('redacts the password from a production error message in the log and on the page', function () {
    saveReadOnly($this->coins, $this->fx);

    // A table named after the password: MySQL's "doesn't exist" error quotes it back (lower-cased on macOS).
    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('addCustom')
        ->set('customs.0.label', 'Leak')
        ->set('customs.0.sql', 'SELECT count(*) FROM '.$this->fx->password)
        ->call('testCustom', 0)
        ->assertSee('doesn&#039;t exist', false)
        ->assertSee('[redacted]');

    $message = (string) $test->get('results.'.$test->get('customs.0.key').'.message');
    $html = strtolower($test->html());
    $logged = strtolower($this->logs->map(fn (MessageLogged $m) => $m->message.json_encode($m->context))->implode("\n"));
    expect(strtolower($message))->not->toContain(strtolower($this->fx->password))
        ->and($html)->not->toContain(strtolower($this->fx->password))
        ->and($logged)->not->toContain(strtolower($this->fx->password))
        ->and(logsNamed($this->logs, 'board.prod_metric_tested')->last()->context)->toMatchArray(['result' => 'failed'])
        ->and(logsNamed($this->logs, 'board.prod_metric_tested')->last()->context['error'])->toContain('[redacted]');
});

it('deletes the credentials and metrics from the board DB when the connection is removed', function () {
    $other = Project::factory()->create(['name' => 'rent-track']);
    ProdConnection::factory()->for($this->coins)->create();
    ProdMetric::factory()->for($this->coins)->count(2)->sequence(['key' => 'total_users'], ['key' => 'new_today'])->create();
    ProdConnection::factory()->for($other)->create();
    ProdMetric::factory()->for($other)->create();

    Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('remove')
        ->assertSet('host', '')
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'Production connection removed');

    expect(ProdConnection::where('project_id', $this->coins->id)->count())->toBe(0)
        ->and(ProdMetric::where('project_id', $this->coins->id)->count())->toBe(0)
        ->and(ProdConnection::where('project_id', $other->id)->count())->toBe(1)
        ->and(ProdMetric::where('project_id', $other->id)->count())->toBe(1);
});

it('logs board.prod_connection_saved, tested and removed with project, a reason for a refusal, and never host credentials', function () {
    prodForm($this->coins, $this->fx)->call('testConnection');
    prodForm($this->coins, $this->fx, 'rw')->call('testConnection');
    prodForm($this->coins, $this->fx, 'rw')->call('save');
    saveReadOnly($this->coins, $this->fx);
    Livewire::test(ProductionSettings::class, ['project' => $this->coins])->call('remove');

    $tested = logsNamed($this->logs, 'board.prod_connection_tested');
    expect($tested)->toHaveCount(2)
        ->and($tested[0]->context)->toMatchArray(['project' => 'coins', 'result' => 'ok'])
        ->and($tested[0]->context)->not->toHaveKey('reason')
        ->and($tested[1]->context)->toMatchArray(['project' => 'coins', 'result' => 'refused', 'reason' => 'can_write', 'privilege' => 'INSERT'])
        ->and(logsNamed($this->logs, 'board.prod_connection_refused')->map->context->all())
        ->toContain(['project' => 'coins', 'reason' => 'can_write', 'privilege' => 'INSERT', 'during' => 'check'])
        ->and(logsNamed($this->logs, 'board.prod_connection_refused')->first()->level)->toBe('warning')
        ->and(logsNamed($this->logs, 'board.prod_connection_saved')->sole()->context)->toMatchArray(['project' => 'coins'])
        ->and(logsNamed($this->logs, 'board.prod_connection_removed')->sole()->context)->toMatchArray(['project' => 'coins']);

    $all = $this->logs->map(fn (MessageLogged $m) => $m->message.json_encode($m->context))->implode("\n");
    foreach ([$this->fx->password, $this->fx->users['ro'], $this->fx->users['rw'], $this->fx->database, $this->fx->host] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

it('refuses Remove when the project has no connection, and logs why', function () {
    Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->call('remove')
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'There is no production connection to remove.');

    expect(logsNamed($this->logs, 'board.prod_connection_remove_refused')->sole()->context)
        ->toMatchArray(['project' => 'coins', 'reason' => 'none']);
});

it('refuses to test a metric before a connection is saved, and logs why', function () {
    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->set('presets.total_users.enabled', true)
        ->call('testPreset', 'total_users');

    // The metrics only render once a connection exists; this guards a stale tab calling Test anyway.
    expect($test->get('results.total_users.status'))->toBe('refused')
        ->and($test->get('results.total_users.message'))->toContain('Save a connection first')
        ->and(logsNamed($this->logs, 'board.prod_metric_tested')->sole()->context)
        ->toMatchArray(['project' => 'coins', 'metric' => 'total_users', 'result' => 'refused', 'reason' => 'no_connection']);
});

it('refuses a host that would add options to the MySQL address, before connecting, and logs why', function () {
    // Past the form's own validation (a stored row, or SB-18's reads): the reader guards the DSN itself.
    $connection = ProdConnection::factory()->for($this->coins)->create(['host' => '127.0.0.1;unix_socket=/tmp/mysql.sock']);

    $check = app(ProductionReader::class)->check($connection);

    expect($check->status)->toBe('failed')
        ->and($check->reason)->toBe('bad_target')
        ->and(logsNamed($this->logs, 'board.prod_connection_refused')->sole()->context)
        ->toBe(['project' => 'coins', 'reason' => 'bad_target', 'during' => 'check']);
});

it('reads every enabled metric in order in one session, and nothing when the check refuses (SB-18 entry point)', function () {
    saveReadOnly($this->coins, $this->fx);
    ProdMetric::factory()->for($this->coins)->create(['key' => 'total_users', 'position' => 0]);
    ProdMetric::factory()->for($this->coins)->create(['key' => 'new_today', 'position' => 1, 'is_enabled' => false]);
    ProdMetric::factory()->for($this->coins)->custom('SELECT count(*) FROM users WHERE last_seen_at IS NULL')->create(['key' => 'custom-nulls', 'position' => 2]);
    $reader = app(ProductionReader::class);

    $read = $reader->readEnabledMetrics(ProdConnection::sole());
    expect($read->check->ok())->toBeTrue()
        ->and(array_keys($read->readings))->toBe(['total_users', 'custom-nulls'])
        ->and($read->readings['total_users']->value)->toBe(4)
        ->and($read->readings['custom-nulls']->value)->toBe(1);

    // The grants check re-runs on every read: a connection swapped to a writing user reads nothing.
    ProdConnection::sole()->update(['username' => $this->fx->users['rw']]);
    $refused = $reader->readEnabledMetrics(ProdConnection::sole());
    expect($refused->check->reason)->toBe('can_write')
        ->and($refused->readings)->toBe([])
        ->and(logsNamed($this->logs, 'board.prod_connection_refused')->last()->context)->toMatchArray(['during' => 'read']);
});

it('refuses a preset whose table or column is not a plain name, before it reaches the database', function () {
    ProdConnection::factory()->for($this->coins)->create(['host' => '127.0.0.1', 'port' => 1]);

    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->set('presets.new_today.enabled', true)
        ->set('presets.new_today.table', 'users; DROP TABLE users')
        ->call('testPreset', 'new_today');

    expect($test->get('results.new_today.status'))->toBe('refused')
        ->and($test->get('results.new_today.message'))->toContain('letters, digits and _')
        ->and(logsNamed($this->logs, 'board.prod_metric_tested')->sole()->context)->toMatchArray(['reason' => 'bad_name']);
});

it('saves enabled presets and custom metrics in order, and refuses to save a custom metric that is not one SELECT', function () {
    saveReadOnly($this->coins, $this->fx);

    $test = Livewire::test(ProductionSettings::class, ['project' => $this->coins])
        ->set('presets.total_users.enabled', true)
        ->set('presets.logged_in_today.enabled', true)
        ->set('presets.logged_in_today.table', 'user_login_events')
        ->set('presets.logged_in_today.column', 'occurred_at')
        ->set('presets.logged_in_today.distinct', 'user_id')
        ->call('addCustom')
        ->set('customs.0.label', 'Everyone')
        ->set('customs.0.sql', 'SELECT count(*) FROM users')
        ->call('saveMetrics')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'Metrics saved');

    $metrics = ProdMetric::where('project_id', $this->coins->id)->orderBy('position')->get();
    expect($metrics->where('is_enabled', true)->pluck('key')->all())->toBe(['total_users', 'logged_in_today', $test->get('customs.0.key')])
        ->and($metrics->firstWhere('key', 'logged_in_today')->config)->toBe(['table' => 'user_login_events', 'column' => 'occurred_at', 'distinct' => 'user_id'])
        ->and($metrics->firstWhere('kind', ProdMetric::KIND_CUSTOM)->sql)->toBe('SELECT count(*) FROM users')
        ->and(logsNamed($this->logs, 'board.prod_metrics_saved')->sole()->context)->toMatchArray(['project' => 'coins', 'enabled' => 3]);

    $test->set('customs.0.sql', 'DELETE FROM users')->call('saveMetrics')->assertHasErrors(['customs.0.sql']);
    expect(ProdMetric::where('kind', ProdMetric::KIND_CUSTOM)->value('sql'))->toBe('SELECT count(*) FROM users')
        ->and(logsNamed($this->logs, 'board.prod_metrics_refused')->sole()->context)->toMatchArray(['project' => 'coins', 'reason' => 'sql_refused']);
});

it('shows each project\'s production state in its row: Not set up, or Read-only with its enabled metric count', function () {
    ProdConnection::factory()->for($this->coins)->create();
    ProdMetric::factory()->for($this->coins)->count(3)->sequence(['key' => 'a'], ['key' => 'b'], ['key' => 'c', 'is_enabled' => false])->create();
    Project::factory()->create(['name' => 'rent-track']);

    $html = Livewire::test(ManageProjects::class)->html();

    expect($html)->toMatch('/data-project-row="coins".*?data-prod-state="connected".*?Read-only · <span[^>]*>2 metrics/s')
        ->and($html)->toMatch('/data-project-row="rent-track".*?data-prod-state="none".*?Not set up/s');
});
