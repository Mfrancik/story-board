<?php

use App\Models\Project;
use App\Models\Story;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * RD-7 in a real browser: the Projects grid and Shipped this week on `/`. A tile's counts match its project's
 * Stories page, which the tile opens; Shipped this week lists the build commits on each project's ref; the RD-6
 * filter narrows both; at 375px the page has no sideways scroll.
 */
beforeEach(function () {
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $this->fixtures = [];

    foreach (['story-board' => ['SB', ['built', 'built', 'built', 'approved', 'draft']], 'coins' => ['CO', ['built', 'draft']]] as $name => [$prefix, $statuses]) {
        $fixture = new GitFixture;
        $this->fixtures[] = $fixture;
        $fixture->write('a.txt', '1')->commitAt("feat({$prefix}-1): first", now()->subDays(1));
        $fixture->git($fixture->project, 'fetch', '--quiet');

        $project = Project::factory()->create(['name' => $name, 'path' => $fixture->project, ...$fresh]);
        foreach ($statuses as $i => $status) {
            Story::factory()->for($project)->create(['story_id' => $prefix.'-'.($i + 1), 'title' => "{$name} story ".($i + 1), 'status' => $status, 'initiative' => 'demo']);
        }
    }
});

afterEach(function () {
    $this->sessions->destroy();
    foreach ($this->fixtures as $fixture) {
        $fixture->destroy();
    }
});

it('shows a tile whose counts match its Stories page, and Shipped this week lists the build commits', function () {
    $page = visit('/')->resize(1280, 900);

    $page->assertSeeIn('[data-tile="story-board"] [data-tile-counts]', '3 built · 1 in flight · 1 draft')
        ->assertSeeIn('[data-tile="story-board"] [data-tile-offmain]', 'clean')
        ->assertSeeIn('[data-tile="story-board"] [data-tile-preflight]', 'no runs yet')
        ->assertVisible('[data-shipped-row="story-board/SB-1"]')
        ->assertVisible('[data-shipped-row="coins/CO-1"]')
        ->assertSeeIn('[data-shipped-row="story-board/SB-1"]', 'story-board story 1')
        ->assertNoJavaScriptErrors();

    $page->click('[data-tile="story-board"]');
    $page->wait(1);

    $page->assertPathIs('/p/story-board/stories')
        ->assertSeeIn('[data-filter="built"] [data-tally]', '3')
        ->assertSeeIn('[data-filter="approved"] [data-tally]', '1')
        ->assertSeeIn('[data-filter="draft"] [data-tally]', '1');
});

it('narrows the tiles and the shipped rows to the one project the filter names', function () {
    $page = visit('/?projects=coins')->resize(1280, 900);

    $page->assertVisible('[data-tile="coins"]')->assertMissing('[data-tile="story-board"]')
        ->assertVisible('[data-shipped-row="coins/CO-1"]')->assertMissing('[data-shipped-row="story-board/SB-1"]')
        ->assertNoJavaScriptErrors();
});

it('has no sideways scroll at 375px with tiles and shipped rows', function () {
    $page = visit('/')->resize(375, 812);

    $page->assertVisible('[data-tile="story-board"]')->assertVisible('[data-shipped-row="coins/CO-1"]');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
