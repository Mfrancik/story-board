<?php

use App\Models\Project;
use App\Models\Story;
use Tests\Support\GitFixture;

/**
 * SB-8 in a real browser: a row opens the modal without moving the list, Escape and
 * Back close it and hand focus back, and `?story=` opens it directly (one column at 375px).
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo->story('ACQ-20', 'approved', 'acquisition', "## Design mockup gate\n- Chosen option: a\n## Links\nJourney: none · Depends on: ACQ-19\n")
        ->write('docs/mockups/ACQ-20/option-a.html', '<p>A</p>')
        ->write('docs/mockups/ACQ-20/option-b.html', '<p>B</p>')
        ->story('ACQ-19', 'built', 'acquisition', "## Why\nThe engine keeps its near misses.\n");
    // Enough rows that the page scrolls, so "keeps its scroll position" means something.
    foreach (range(1, 9) as $n) {
        $this->repo->story("FX-{$n}", 'approved')->story("DR-{$n}", 'draft');
    }
    $this->repo->commitAndPush();
    Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    expect(Story::onRef()->count())->toBe(20);
});

afterEach(function () {
    $this->repo->destroy();
});

it('opens a clicked row in the modal and keeps the list at its scroll position, and Back closes it', function () {
    // SB-9: a card shows its top 5; FX-9 is behind Show all.
    $page = visit('/')->resize(1280, 600)->click('[data-show-all="build"]')->assertPresent('[data-row="FX-9"]');
    $page->script("document.querySelector('[data-row=\"FX-9\"]').scrollIntoView({ block: 'center' })");
    $y = $page->script('Math.round(window.scrollY)');
    expect($y)->toBeGreaterThan(0);

    $page->click('[data-row="FX-9"]')
        ->assertVisible('[data-story-open="coins/FX-9"]')
        ->assertSeeIn('#story-modal-title', 'Story FX-9')
        ->assertQueryStringHas('story', 'coins/FX-9')
        ->assertScript('Math.round(window.scrollY)', $y)
        ->back()
        ->assertMissing('[role="dialog"]')
        ->assertQueryStringMissing('story')
        ->assertScript('Math.round(window.scrollY)', $y)
        ->assertNoJavaScriptErrors();
});

it('keeps the scroll position on Back after arriving at /p/{project} through the sidebar', function () {
    // wire:navigate stamps the history entry with a page snapshot; Back must not restore it over the list.
    $page = visit('/')->resize(1280, 600)->click('[data-sidebar-project="coins"]')->assertPathIs('/p/coins')
        ->click('[data-show-all="build"]')->assertPresent('[data-row="FX-9"]');
    $page->script("document.querySelector('[data-row=\"FX-9\"]').scrollIntoView({ block: 'center' })");
    $y = $page->script('Math.round(window.scrollY)');
    expect($y)->toBeGreaterThan(0);

    $page->click('[data-row="FX-9"]')
        ->assertVisible('[data-story-open="coins/FX-9"]')
        ->back()
        ->assertMissing('[role="dialog"]')
        ->assertPathIs('/p/coins')
        ->assertQueryStringMissing('story')
        ->wait(0.2)
        ->assertScript('Math.round(window.scrollY)', $y)
        ->assertNoJavaScriptErrors();
});

it('closes on Escape, drops ?story from the URL and returns focus to the row', function () {
    visit('/')
        ->resize(1280, 800)
        ->click('[data-row="ACQ-20"]')
        ->assertVisible('[data-story-open="coins/ACQ-20"]')
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Close')
        ->keys('[aria-label="Close"]', 'Escape')
        ->assertMissing('[role="dialog"]')
        ->assertQueryStringMissing('story')
        // focus-trap hands focus back on the next task, not synchronously.
        ->wait(0.2)
        ->assertScript('document.activeElement.getAttribute("data-row") || (document.activeElement.tagName + " " + document.activeElement.outerHTML.slice(0, 200))', 'ACQ-20')
        ->assertNoJavaScriptErrors();
});

it('opens directly from the URL, one column at 375px, and follows a dependency chip', function () {
    visit('/?story=coins/ACQ-20')
        ->resize(375, 812)
        ->assertVisible('[data-story-open="coins/ACQ-20"]')
        ->assertPresent('[data-thumb="a"][data-chosen]')
        ->assertScript("getComputedStyle(document.querySelector('[data-story-grid]')).gridTemplateColumns.split(' ').length", 1)
        ->click('[data-dep="ACQ-19"]')
        ->assertVisible('[data-story-open="coins/ACQ-19"]')
        ->assertQueryStringHas('story', 'coins/ACQ-19')
        ->assertSeeIn('[role="dialog"]', 'The engine keeps its near misses.')
        ->assertNoJavaScriptErrors();
});

it('shows two columns from 768px and closes on a backdrop click', function () {
    $page = visit('/?story=coins/ACQ-20')
        ->resize(1024, 800)
        ->assertVisible('[data-story-open="coins/ACQ-20"]')
        ->assertScript("getComputedStyle(document.querySelector('[data-story-grid]')).gridTemplateColumns.split(' ').length", 2);
    // The backdrop's centre is under the panel, so it is clicked where it shows: its corner.
    $page->script("document.querySelector('[data-story-backdrop]').click()");

    $page->assertMissing('[role="dialog"]')
        ->assertQueryStringMissing('story')
        ->assertNoJavaScriptErrors();
});
