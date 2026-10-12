<?php

use App\Models\Project;

/**
 * Example journey (SB-22): the owner opens the board and then one project,
 * with `journeyStep()` at each step. A normal run only walks and asserts;
 * `JOURNEY_SHOTS=1` also saves each step to
 * `storage/app/journey-shots/browse-the-board/` for the board to show.
 */
beforeEach(function () {
    foreach (['coins', 'rent-track'] as $name) {
        Project::factory()->create(['name' => $name, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    }
});

it('opens the board, then a project from the sidebar', function () {
    // `/` is the RD-5 overview in the shell, whose sidebar lists projects as data-nav-project.
    journeyStep('browse-the-board', 'all projects', '/')
        ->assertVisible('[data-nav-project="coins"]');

    // RD-11: a project's sidebar entry opens its Overview (option B); its Stories are one tab away.
    $page = journeyStep('browse-the-board', 'one project', '/p/coins')
        ->assertPathIs('/p/coins')
        ->assertAttribute('[data-nav-project="coins"]', 'aria-current', 'page')
        ->assertAttribute('[data-project-tab="overview"]', 'aria-current', 'page')
        ->assertVisible('[data-tile="progress"]');

    // A step reached by clicking rather than visiting: hand journeyStep() the page the test is on.
    $page->click('[data-project-tab="stories"]')->assertPathIs('/p/coins/stories');
    journeyStep('browse-the-board', 'project stories', '/p/coins/stories', $page)
        ->assertAttribute('[data-project-tab="stories"]', 'aria-current', 'page');

    $page->click('[data-nav-project="rent-track"]')->assertPathIs('/p/rent-track');
    journeyStep('browse-the-board', 'switch project', '/p/rent-track', $page)
        ->assertAttribute('[data-nav-project="rent-track"]', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});
