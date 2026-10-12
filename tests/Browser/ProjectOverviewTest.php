<?php

use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Queue;

/**
 * RD-11 in a real browser: a project's sidebar entry opens its Overview with every option B tile, the tabs walk
 * away and back, Refresh this project turns into "Refreshing…", and at 375 px the tiles stack in one column with
 * no sideways scroll (4 → 2 → 1 columns at 1280 / 768 / 375).
 */
beforeEach(function () {
    Queue::fake();
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    foreach (['built', 'built', 'approved', 'draft'] as $i => $status) {
        Story::factory()->for($coins)->create(['story_id' => 'CO-'.($i + 1), 'status' => $status, 'initiative' => 'mobile']);
    }
});

/** Script: how many columns the overview's tile grid lays out. */
const OVERVIEW_COLUMNS = "getComputedStyle(document.querySelector('[data-overview-grid]')).gridTemplateColumns.split(' ').length";

it('opens a project\'s Overview from the sidebar with every tile, walks the tabs and back, and shows Refreshing… on Refresh', function () {
    $page = visit('/')->resize(1280, 900)->click('[data-nav-project="coins"]')->assertPathIs('/p/coins');

    foreach (['progress', 'needs', 'live', 'preflight', 'production', 'about', 'initiatives'] as $tile) {
        $page->assertVisible('[data-tile="'.$tile.'"]');
    }
    $page->assertAttribute('[data-project-tab="overview"]', 'aria-current', 'page')
        ->assertScript(OVERVIEW_COLUMNS, 4);

    foreach (['stories' => '/p/coins/stories', 'preflight' => '/p/coins/preflight', 'handbook' => '/p/coins/handbook', 'map' => '/p/coins/map'] as $tab => $path) {
        $page->click('[data-project-tab="'.$tab.'"]')->assertPathIs($path)
            ->click('[data-project-tab="overview"]')->assertPathIs('/p/coins')->assertVisible('[data-tile="progress"]');
    }

    $page->click('[data-refresh-project]')
        ->assertVisible('[data-refreshing]')
        ->assertSeeIn('[data-refresh-project]', 'Refreshing…')
        ->assertScript("document.querySelector('[data-refresh-project]').disabled", true)
        ->assertNoJavaScriptErrors();
});

it('stacks the tiles in two columns at 768px and one at 375px with no sideways scroll', function () {
    visit('/p/coins')->resize(768, 1000)->assertScript(OVERVIEW_COLUMNS, 2);

    visit('/p/coins')->resize(375, 812)
        ->assertScript(OVERVIEW_COLUMNS, 1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
