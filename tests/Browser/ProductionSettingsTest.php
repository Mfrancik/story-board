<?php

use App\Models\ProdConnection;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProductionFixture;

/**
 * SB-17 in a real browser: a row's Production panel loads only when opened, a
 * writing user is refused inline, a read-only one is saved, a preset's Test shows
 * its number, and Remove connection only deletes once confirmed. The "production"
 * database is tests/Support/ProductionFixture, never a real one.
 */
beforeEach(function () {
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    Project::factory()->create(['name' => 'rent-track', ...$fresh]);
    $this->fx = ProductionFixture::boot();
    $this->fx->seed([
        ['name' => 'ada', 'created_at' => '2026-09-01 10:00:00', 'last_seen_at' => null],
        ['name' => 'bob', 'created_at' => '2026-09-02 10:00:00', 'last_seen_at' => null],
        ['name' => 'cy', 'created_at' => '2026-09-03 10:00:00', 'last_seen_at' => null],
    ]);
});

afterAll(fn () => ProductionFixture::drop());

it('refuses a writing user inline, saves a read-only one, tests Total users, and removes the connection only once confirmed', function () {
    $fx = $this->fx;
    $page = visit('/projects')->resize(1280, 900)
        ->assertSeeIn('[data-prod-toggle="coins"]', 'Not set up')
        ->assertMissing('[data-prod-panel="coins"]')
        ->click('[data-prod-toggle="coins"]')
        ->assertVisible('[data-prod-panel="coins"]')
        ->assertSee('Metrics come after the connection');

    $page->fill('#prod-'.$this->coins->id.'-host', $fx->host)
        ->fill('#prod-'.$this->coins->id.'-port', (string) $fx->port)
        ->fill('#prod-'.$this->coins->id.'-db', $fx->database)
        ->fill('#prod-'.$this->coins->id.'-user', $fx->users['rw'])
        ->fill('#prod-'.$this->coins->id.'-pass', $fx->password)
        ->click('[data-prod-save]')
        ->assertSee('This user can write (INSERT) — create a SELECT-only user.')
        ->assertSee('Nothing was saved.');
    expect(ProdConnection::count())->toBe(0);

    $page->fill('#prod-'.$this->coins->id.'-user', $fx->users['ro'])
        ->click('[data-prod-save]')
        ->assertSee('Connection saved — read-only confirmed')
        ->assertSee('SELECT only')
        ->assertSeeIn('[data-prod-toggle="coins"]', 'Read-only · 0 metrics')
        ->assertValue('#prod-'.$this->coins->id.'-pass', '');
    expect(ProdConnection::count())->toBe(1);

    $page->click('[data-preset="total_users"] [role="switch"]')
        ->click('[data-preset="total_users"] button:not([role="switch"])')
        ->assertPresent('[data-preset="total_users"] [data-metric-value="3"]')
        ->click('[data-prod-save-metrics]')
        ->assertSee('Metrics saved')
        ->assertSeeIn('[data-prod-toggle="coins"]', 'Read-only · 1 metric');

    $page->click('[data-prod-remove]')
        ->assertVisible('[data-prod-remove-modal] [role="alertdialog"]')
        ->click('[data-prod-remove-modal] [data-confirm-cancel]')
        ->assertMissing('[data-prod-remove-modal] [role="alertdialog"]');
    expect(ProdConnection::count())->toBe(1);

    $page->click('[data-prod-remove]')
        ->click('[data-prod-remove-modal] [data-confirm-ok]')
        ->assertSee('Production connection removed')
        ->assertSeeIn('[data-prod-toggle="coins"]', 'Not set up')
        ->assertNoJavaScriptErrors();
    expect(ProdConnection::count())->toBe(0);
});

it('lays the panel out without sideways scroll at 375px', function () {
    visit('/projects')->resize(375, 812)
        ->click('[data-prod-toggle="coins"]')
        ->assertVisible('[data-prod-panel="coins"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
