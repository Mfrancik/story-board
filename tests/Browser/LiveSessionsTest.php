<?php

use App\Models\Project;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * SB-11 in a real browser: a live coins session shows under Live now with its worktree,
 * branch and story; clicking the story opens the SB-8 modal; a poll re-render does not
 * move the card; the sidebar carries the live badge; and a project with no sessions
 * shows "No live sessions" at 375px without sideways scroll.
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo
        ->story('SET-42', 'approved', 'sets', "## Design mockup gate\n- Mockups: docs/mockups/SET-42/option-{a,b}.html\n- Chosen option: b\n")
        ->write('docs/mockups/SET-42/option-a.html', '<p>A</p>')
        ->write('docs/mockups/SET-42/option-b.html', '<p>B</p>')
        ->commitAndPush();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $coins = Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, ...$fresh]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    $coins->update($fresh);
    Project::factory()->create(['name' => 'asset-track', 'path' => '/code/asset-track', ...$fresh]);

    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $this->sessions->session($this->repo->project.'/.claude/worktrees/set42', 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-0000000000b1', 1);
});

afterEach(function () {
    $this->repo->destroy();
    $this->sessions->destroy();
});

it('shows the live coins session, keeps its card still through a poll, and opens its story in the modal', function () {
    $height = "Math.round(document.querySelector('[data-live-session]').getBoundingClientRect().height)";
    $page = visit('/')->resize(1280, 900);

    $page->assertVisible('[data-live-session][data-live-project="coins"]')
        ->assertSeeIn('[data-live-session]', 'worktree set42')
        ->assertSeeIn('[data-live-session]', 'feat/SET-42-lock-a-coin-in-its-slot')
        ->assertVisible('[data-sidebar-project="coins"] [data-live-count="1"]')
        ->assertVisible('[data-live-thumb="b"] iframe');

    $before = $page->script($height);
    $page->script("Livewire.getByName('board.live-sessions')[0].\$refresh()");
    $page->wait(1);
    expect($page->script($height))->toBe($before);

    $page->click('[data-live-story="coins/SET-42"]')
        ->assertVisible('[data-story-open="coins/SET-42"]')
        ->assertQueryStringHas('story', 'coins/SET-42')
        ->assertNoJavaScriptErrors();
});

it('shows "No live sessions" on a project with none, at 375px without sideways scroll', function () {
    $page = visit('/p/asset-track')->resize(375, 812);

    $page->assertSee('No live sessions')
        ->assertMissing('[data-live-session]');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
