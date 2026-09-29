<?php

use App\Models\Project;
use App\Models\Story;
use Tests\Support\GitFixture;

/**
 * SB-9 in a real browser: a row in each What needs me card opens the SB-8 modal, the three
 * cards sit side by side at 1280px and stack at 375px, and a tile leads to its project page.
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    // One story per card: a pick (options, no Chosen option), a draft, an approved story.
    $this->repo->story('MOB-65', 'draft', 'mobile')
        ->write('docs/mockups/MOB-65/option-a.html', '<p>A</p>')
        ->write('docs/mockups/MOB-65/option-b.html', '<p>B</p>')
        ->story('AUC-17', 'approved', 'auction')
        ->story('VIEW-48', 'draft', 'views')
        ->commitAndPush();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, ...$fresh]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    expect(Story::onRef()->count())->toBe(3);

    // rent-track's shape (SB-9 story): out-of-vocabulary statuses; no git needed for a tile.
    $rent = Project::factory()->create(['name' => 'rent-track', ...$fresh, 'sha' => str_repeat('c', 40)]);
    Story::factory()->for($rent)->create(['story_id' => 'MT-1', 'status' => 'draft']);
    Story::factory()->for($rent)->create(['story_id' => 'MT-2', 'status' => 'in']);
    Story::factory()->for($rent)->create(['story_id' => 'MT-3', 'status' => 'done']);
});

afterEach(function () {
    $this->repo->destroy();
});

it('opens the SB-8 modal for a row clicked in any card', function () {
    $page = visit('/')->resize(1280, 900);

    foreach (['pick' => 'MOB-65', 'approval' => 'VIEW-48', 'build' => 'AUC-17'] as $card => $id) {
        $page->click("[data-card=\"{$card}\"] [data-row=\"{$id}\"]")
            ->assertVisible("[data-story-open=\"coins/{$id}\"]")
            ->assertQueryStringHas('story', "coins/{$id}")
            ->keys('[aria-label="Close"]', 'Escape')
            ->assertMissing('[role="dialog"]');
    }

    $page->assertNoJavaScriptErrors();
});

it('puts the three cards side by side at 1280px, stacks them at 375px, and a tile leads to its project page', function () {
    $tops = "[...document.querySelectorAll('[data-card]')].map(c => Math.round(c.getBoundingClientRect().top)).join(',')";
    $page = visit('/')->resize(1280, 900);

    $wide = explode(',', (string) $page->script($tops));
    expect($wide)->toHaveCount(3)->and(array_unique($wide))->toHaveCount(1);
    $page->assertPresent('[data-project-card="rent-track"] [data-segment="in"][data-tone="danger"]');

    $page->resize(375, 812);
    $narrow = explode(',', (string) $page->script($tops));
    expect(array_unique($narrow))->toHaveCount(3);

    $page->resize(1280, 900)
        ->click('[data-project-card="rent-track"]')
        ->assertPathIs('/p/rent-track')
        ->assertNoJavaScriptErrors();
});
