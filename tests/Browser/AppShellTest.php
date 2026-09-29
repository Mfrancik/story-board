<?php

use App\Models\Project;

/**
 * SB-7 in a real browser: the drawer at 375px and the sidebar switcher at 1280px.
 */
beforeEach(function () {
    foreach (['asset-track', 'client-dashboard', 'coins', 'rent-track'] as $name) {
        Project::factory()->create(['name' => $name, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    }
});

it('hides the sidebar at 375px, opens it with the menu button and closes it with Escape', function () {
    visit('/')
        ->resize(375, 812)
        ->assertMissing('#board-sidebar')
        ->click('[aria-label="Open projects"]')
        ->assertVisible('#board-sidebar')
        ->keys('#sidebar-search', 'Escape')
        ->assertMissing('#board-sidebar')
        ->click('[aria-label="Open projects"]')
        ->assertVisible('#board-sidebar')
        ->click('[aria-label="Close projects"]')
        ->assertMissing('#board-sidebar')
        ->assertNoJavaScriptErrors();
});

it('switches project from the sidebar and filters the list as you type', function () {
    visit('/')
        ->resize(1280, 800)
        ->assertVisible('#board-sidebar')
        ->click('[data-sidebar-project="coins"]')
        ->assertPathIs('/p/coins')
        ->assertAttribute('[data-sidebar-project="coins"]', 'aria-current', 'page')
        ->type('#sidebar-search', 'cli')
        ->assertVisible('[data-sidebar-project="client-dashboard"]')
        ->assertMissing('[data-sidebar-project="coins"]')
        ->assertMissing('[data-sidebar-project="asset-track"]')
        ->assertMissing('[data-sidebar-project="rent-track"]')
        ->assertNoJavaScriptErrors();
});
