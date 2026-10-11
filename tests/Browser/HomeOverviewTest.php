<?php

use App\Models\Project;
use App\Models\Story;
use Tests\Support\SessionFixture;

/**
 * RD-5 in a real browser: `/` is the overview in the dark shell. A Needs me row opens its story page; a live
 * session's card shows its branch, what it is doing and a context bar, and keeps its height through a poll;
 * at 375px the page has no sideways scroll.
 */
beforeEach(function () {
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $coins = Project::factory()->create(['name' => 'coins', 'path' => '/work/coins', ...$fresh]);
    Story::factory()->for($coins)->create(['story_id' => 'CO-2', 'title' => 'Payout export CSV', 'status' => 'draft', 'dated_on' => '2026-10-03']);
    Story::factory()->for($coins)->create(['story_id' => 'PRF-14', 'title' => 'Payout schedule', 'status' => 'approved', 'dated_on' => '2026-10-01']);

    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $cwd = '/work/coins/.claude/worktrees/prf14';
    $id = 'aaaaaaaa-0000-0000-0000-0000000000c1';
    $this->sessions->file(SessionFixture::folderFor($cwd), $id, [
        SessionFixture::line($cwd, 'fix/PRF-14', $id, 24),
        SessionFixture::assistant($cwd, 'fix/PRF-14', $id, [SessionFixture::tool('Read', ['file_path' => "{$cwd}/tests/Feature/X.php"])], SessionFixture::usage(142_000)),
    ], 1);
});

afterEach(function () {
    $this->sessions->destroy();
});

it('shows the live card with its branch, what it is doing and its context bar, steady through a poll, and opens a Needs me row\'s story page', function () {
    $height = "Math.round(document.querySelector('[data-live-card]').getBoundingClientRect().height)";
    $page = visit('/')->resize(1280, 900);

    $page->assertVisible('[data-live-card="coins"]')
        ->assertSeeIn('[data-live-card="coins"]', 'fix/PRF-14')
        ->assertSeeIn('[data-live-card="coins"] [data-doing]', 'Reading tests/Feature/X.php')
        ->assertSeeIn('[data-live-card="coins"] [data-context-label]', '142k / 200k')
        ->assertAttribute('[data-live-card="coins"] [data-context]', 'data-context-pct', '71')
        ->assertSeeIn('[data-figure="needs"]', '0 picks · 1 draft · 1 ready');

    $before = $page->script($height);
    $page->script("Livewire.getByName('board.overview')[0].\$refresh()");
    $page->wait(1);
    expect($page->script($height))->toBe($before);

    $page->click('[data-needs-story="coins/CO-2"]')
        ->assertPathIs('/p/coins/s/CO-2')
        ->assertSee('Payout export CSV')
        ->assertNoJavaScriptErrors();
});

it('has no sideways scroll at 375px', function () {
    $page = visit('/')->resize(375, 812);

    $page->assertVisible('[data-live-card="coins"]')->assertVisible('[data-needs-story="coins/CO-2"]');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
