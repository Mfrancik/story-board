<?php

use App\Jobs\ReadProductionMetrics;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProductionFixture;

/**
 * SB-18 in a real browser: /prod shows a skeleton per project until its queued
 * read is back, then its numbers; Refresh re-reads one project; an unreachable
 * project keeps its last good values greyed beside its error; below 768 px each
 * project is a card and nothing scrolls sideways. The "production" database is
 * tests/Support/ProductionFixture; "unreachable" is a closed local port.
 */
beforeEach(function () {
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->asset = Project::factory()->create(['name' => 'asset-track', ...$fresh]);
    Project::factory()->create(['name' => 'rent-track', ...$fresh]);

    $this->fx = ProductionFixture::boot();
    $this->fx->seed([
        ['name' => 'ada', 'created_at' => '2026-09-01 10:00:00', 'last_seen_at' => null],
        ['name' => 'bob', 'created_at' => '2026-09-02 10:00:00', 'last_seen_at' => null],
        ['name' => 'cy', 'created_at' => '2026-09-03 10:00:00', 'last_seen_at' => null],
    ]);

    ProdConnection::factory()->create(['project_id' => $this->coins->id, 'host' => $this->fx->host, 'port' => $this->fx->port,
        'database' => $this->fx->database, 'username' => $this->fx->users['ro'], 'password' => $this->fx->password, 'use_ssl' => false]);
    // The factory's default: 127.0.0.1:1, closed, so unreachable at once.
    ProdConnection::factory()->create(['project_id' => $this->asset->id]);
    foreach ([$this->coins, $this->asset] as $project) {
        ProdMetric::factory()->create(['project_id' => $project->id, 'key' => 'total_users', 'label' => 'Total users']);
    }
    // asset-track's last good value, from yesterday.
    $metric = ProdMetric::where('project_id', $this->asset->id)->sole();
    ProdSnapshot::factory()->create(['prod_metric_id' => $metric->id, 'project_id' => $this->asset->id,
        'day' => now('America/New_York')->subDay()->toDateString(), 'value' => 23, 'read_at' => now()->subDay()]);
});

afterAll(fn () => ProductionFixture::drop());

/**
 * Work the fake queue the way a worker would: run each caught read and release its unique lock.
 */
function workProdQueue(): void
{
    $jobs = Queue::pushed(ReadProductionMetrics::class);
    Queue::fake();
    $jobs->each(function (ReadProductionMetrics $job) {
        app()->call([$job, 'handle']);
        (new UniqueLock(app(Cache::class)))->release($job);
    });
}

it('shows a skeleton per project, then its numbers; Refresh re-reads one; an unreachable project keeps its last value greyed', function () {
    $page = visit('/prod')->resize(1280, 900)
        ->assertSee('Production')
        ->assertPresent('[data-prod-row="coins"] [data-prod-skeleton="coins"]')
        ->assertPresent('[data-prod-row="asset-track"] [data-prod-skeleton="asset-track"]')
        ->assertSeeIn('[data-prod-not-connected="rent-track"]', 'set up on /projects');

    // The worker picks both reads up; the page's poll (2 s) settles each row on its own.
    workProdQueue();
    $page->assertSeeIn('[data-prod-row="coins"] [data-prod-value="coins:total_users"]', '3')
        ->assertSeeIn('[data-prod-row="asset-track"] [data-prod-error-label]', 'Unreachable')
        ->assertSeeIn('[data-prod-row="asset-track"]', 'last good read 1 day ago')
        ->assertPresent('[data-prod-row="asset-track"] [data-prod-stale="asset-track:total_users"]')
        ->assertMissing('[data-prod-row="coins"] [data-prod-stale]')
        ->assertPresent('[data-prod-row="coins"] svg[role="img"]');

    $page->click('[data-prod-row="coins"] [data-prod-refresh="coins"]')
        ->assertPresent('[data-prod-skeleton="coins"]')
        ->assertMissing('[data-prod-row="asset-track"] [data-prod-skeleton]');
    workProdQueue();
    $page->assertSeeIn('[data-prod-row="coins"] [data-prod-value="coins:total_users"]', '3')
        ->assertNoJavaScriptErrors();

    expect(ProdSnapshot::where('project_id', $this->coins->id)->count())->toBe(1);
});

it('shows one card per project and no sideways scroll at 375px', function () {
    workProdQueue();
    $page = visit('/prod')->resize(375, 812);
    workProdQueue();

    $page->assertVisible('[data-prod-card="coins"]')
        ->assertVisible('[data-prod-card="asset-track"]')
        ->assertSeeIn('[data-prod-card="coins"] [data-prod-value="coins:total_users"]', '3')
        ->assertSeeIn('[data-prod-card="asset-track"] [data-prod-error-label]', 'Unreachable')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
