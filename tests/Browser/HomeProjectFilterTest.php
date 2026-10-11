<?php

use App\Models\Project;
use App\Models\Story;
use Tests\Support\SessionFixture;

/**
 * RD-6 in a real browser: the home Projects filter. Choosing one project from the keyboard-reachable dropdown
 * narrows every panel and the URL without a reload; Back returns every project, and the box unticks; a chip removes
 * its project; at 375px the open dropdown causes no sideways scroll.
 */
beforeEach(function () {
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);

    foreach (['coins' => 'CO', 'ledger-app' => 'LA'] as $name => $prefix) {
        $project = Project::factory()->create(['name' => $name, 'path' => "/work/{$name}", ...$fresh]);
        Story::factory()->for($project)->create(['story_id' => "{$prefix}-1", 'title' => "{$name} draft", 'status' => 'draft', 'dated_on' => '2026-10-03']);
        $this->sessions->session("/work/{$name}", 'main', 'cccccccc-0000-0000-0000-'.str_pad((string) strlen($name), 12, '0', STR_PAD_LEFT), 1);
    }
});

afterEach(function () {
    $this->sessions->destroy();
});

it('narrows every panel and the URL to the one project chosen, without a reload, and Back returns all projects', function () {
    $page = visit('/')->resize(1280, 900);
    $page->assertVisible('[data-live-card="ledger-app"]')->assertVisible('[data-needs-story="ledger-app/LA-1"]')
        ->assertSeeIn('[data-filter-label]', 'All projects');
    // A full reload would drop this marker.
    $page->script('window.__sameDocument = true');

    // Keyboard: focus the button, open with Enter, Tab to the first box, tick it with Space.
    $page->script("document.querySelector('[data-filter-button]').focus()");
    $page->keys('[data-filter-button]', 'Enter');
    $page->wait(0.3);
    $page->assertVisible('[data-filter-panel]')->assertAttribute('[data-filter-button]', 'aria-expanded', 'true');
    $page->keys('[data-filter-button]', 'Tab');
    expect($page->script('document.activeElement.dataset.filterToggle'))->toBe('coins');
    $page->keys('[data-filter-toggle="coins"]', 'Space');
    $page->wait(1);

    $page->assertVisible('[data-filter-chip="coins"]')
        ->assertMissing('[data-live-card="ledger-app"]')->assertMissing('[data-needs-story="ledger-app/LA-1"]')
        ->assertVisible('[data-live-card="coins"]')->assertVisible('[data-needs-story="coins/CO-1"]')
        ->assertAttribute('[data-figure="live"]', 'data-figure-value', '1')
        ->assertSeeIn('[data-filter-label]', 'coins')
        ->assertQueryStringHas('projects', 'coins')
        ->assertChecked('[data-filter-toggle="coins"]');
    expect($page->script('window.__sameDocument === true'))->toBeTrue();

    // Escape closes the dropdown and hands focus back to its button.
    $page->keys('[data-filter-toggle="coins"]', 'Escape');
    $page->wait(0.3);
    $page->assertMissing('[data-filter-panel]');
    expect($page->script("document.activeElement.hasAttribute('data-filter-button')"))->toBeTrue();

    $page->back();
    $page->wait(1);

    $page->assertVisible('[data-live-card="ledger-app"]')->assertVisible('[data-needs-story="ledger-app/LA-1"]')
        ->assertMissing('[data-filter-chip="coins"]')
        ->assertQueryStringMissing('projects')
        ->assertSeeIn('[data-filter-label]', 'All projects')
        ->assertNotChecked('[data-filter-toggle="coins"]')
        ->assertNoJavaScriptErrors();
});

it('removes a project when its chip is clicked, and the panels widen again', function () {
    $page = visit('/?projects=coins')->resize(1280, 900);
    $page->assertVisible('[data-filter-chip="coins"]')->assertMissing('[data-live-card="ledger-app"]');
    expect($page->script("Math.round(document.querySelector('[data-filter-chip=\"coins\"]').getBoundingClientRect().height)"))->toBeGreaterThanOrEqual(44);

    $page->click('[data-filter-chip="coins"]');
    $page->wait(1);

    $page->assertVisible('[data-live-card="ledger-app"]')->assertMissing('[data-filter-chip]')
        ->assertQueryStringMissing('projects')
        ->assertNoJavaScriptErrors();
});

it('has no sideways scroll at 375px with the dropdown open and a chip shown', function () {
    $page = visit('/?projects=ledger-app')->resize(375, 812);

    $page->click('[data-filter-button]');
    $page->wait(0.3);
    $page->assertVisible('[data-filter-panel]')->assertVisible('[data-filter-chip="ledger-app"]');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($page->script("Math.round(document.querySelector('[data-filter-option=\"coins\"]').getBoundingClientRect().height)"))->toBeGreaterThanOrEqual(44);
    $page->assertNoJavaScriptErrors();
});
