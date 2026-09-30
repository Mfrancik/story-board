<?php

use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * SB-15 in a real browser: the Stories tab, the first initiative (most open work) shown on arrival,
 * choosing another with no request to the server, the status filter chips hiding rows and emptied
 * initiatives while the counts stay whole, Expand all / Collapse all with a jump to a group, a row
 * opening the SB-8 modal, and the phone layout's select with no sideways scroll.
 */
beforeEach(function () {
    Queue::fake();
    $this->repo = new GitFixture;
    // AC 1's shape: branding 7 built + 3 draft, acquisition 2 built, 1 cancelled, 4 approved.
    foreach (range(1, 10) as $i) {
        $this->repo->story("BR-{$i}", $i <= 7 ? 'built' : 'draft', 'branding');
    }
    $this->repo->story('ACQ-20', 'built', 'acquisition')->story('ACQ-21', 'built', 'acquisition')
        ->story('ACQ-22', 'cancelled', 'acquisition');
    foreach (range(25, 28) as $i) {
        $this->repo->story("ACQ-{$i}", 'approved', 'acquisition');
    }
    // An initiative with only built work, which a Built-off filter empties.
    $this->repo->story('DW-1', 'built', 'done-work')->commitAndPush();

    Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    expect(Story::onRef()->count())->toBe(18);
});

afterEach(function () {
    $this->repo->destroy();
});

/** Script: the IDs of the story rows a person can see, in page order. */
const VISIBLE_ROWS = "[...document.querySelectorAll('[data-story-row]')].filter(e => e.offsetParent !== null).map(e => e.dataset.storyRow).join()";

/** Script: fetch requests made so far (Livewire's requests are fetches). */
const FETCHES = "performance.getEntriesByType('resource').filter(e => e.initiatorType === 'fetch').length";

it('opens on the initiative with the most open work and lists another\'s stories in natural ID order without asking the server', function () {
    $page = visit('/p/coins')->resize(1280, 900)->click('[data-project-tab="stories"]')->assertPathIs('/p/coins/stories');

    $page->assertScript(VISIBLE_ROWS, 'ACQ-20,ACQ-21,ACQ-22,ACQ-25,ACQ-26,ACQ-27,ACQ-28')
        ->assertScript("getComputedStyle(document.querySelector('[data-story-row=\"ACQ-22\"] span')).textDecorationLine", 'line-through')
        ->assertSeeIn('[data-story-row="ACQ-22"]', 'Cancelled')
        ->assertSeeIn('[data-story-row="ACQ-25"]', 'To do');
    $before = $page->script(FETCHES);

    $page->click('[data-initiative-entry="branding"]')
        ->assertScript(VISIBLE_ROWS, 'BR-1,BR-2,BR-3,BR-4,BR-5,BR-6,BR-7,BR-8,BR-9,BR-10')
        ->assertSeeIn('[data-story-row="BR-7"]', 'Built')
        ->assertSeeIn('[data-story-row="BR-10"]', 'Draft')
        ->assertScript(FETCHES, $before)
        ->assertNoJavaScriptErrors();
});

it('hides built rows and the initiative left with none when Built is turned off, keeping every count whole', function () {
    $page = visit('/p/coins/stories')->resize(1280, 900);
    $before = $page->script(FETCHES);

    $page->assertSeeIn('[data-initiatives-shown]', '3 of 3 initiatives')
        ->click('[data-filter="built"]')
        ->assertScript(VISIBLE_ROWS, 'ACQ-22,ACQ-25,ACQ-26,ACQ-27,ACQ-28')
        ->assertMissing('[data-initiative-entry="done-work"]')
        ->assertSeeIn('[data-initiatives-shown]', '2 of 3 initiatives')
        ->assertSeeIn('[data-initiative-entry="branding"] [data-initiative-counts]', '3 open / 10')
        ->assertSeeIn('[data-filter="built"] [data-tally]', '10')
        ->click('[data-initiative-entry="branding"]')
        ->assertScript(VISIBLE_ROWS, 'BR-8,BR-9,BR-10')
        ->click('[data-filter="built"]')
        ->assertVisible('[data-initiative-entry="done-work"]')
        ->assertSeeIn('[data-initiatives-shown]', '3 of 3 initiatives')
        ->assertScript(FETCHES, $before)
        ->assertNoJavaScriptErrors();
});

it('lists every initiative grouped on Expand all, jumps to a group chosen on the left, and shows one again on Collapse all', function () {
    // Short, so the page scrolls far enough to bring branding's group to the top.
    $page = visit('/p/coins/stories')->resize(1280, 400);

    $page->click('[data-expand-all]')
        ->assertSeeIn('[data-expand-all]', 'Collapse all')
        ->assertScript("[...document.querySelectorAll('[data-group]')].filter(e => e.offsetParent !== null).map(e => e.dataset.group).join()", 'acquisition,branding,done-work')
        ->assertScript(VISIBLE_ROWS, 'ACQ-20,ACQ-21,ACQ-22,ACQ-25,ACQ-26,ACQ-27,ACQ-28,BR-1,BR-2,BR-3,BR-4,BR-5,BR-6,BR-7,BR-8,BR-9,BR-10,DW-1')
        ->click('[data-initiative-entry="branding"]')
        ->assertScript("Math.abs(document.querySelector('[data-group=\"branding\"]').getBoundingClientRect().top) < 40", true)
        ->assertScript('window.scrollY > 0', true)
        ->click('[data-expand-all]')
        ->assertSeeIn('[data-expand-all]', 'Expand all')
        ->assertScript(VISIBLE_ROWS, 'BR-1,BR-2,BR-3,BR-4,BR-5,BR-6,BR-7,BR-8,BR-9,BR-10')
        ->assertNoJavaScriptErrors();
});

it('opens a clicked story row in the SB-8 modal', function () {
    $page = visit('/p/coins/stories')->resize(1280, 900);

    $page->click('[data-initiative-entry="branding"]')
        ->click('[data-story-link="coins/BR-3"]')
        ->assertVisible('[data-story-open="coins/BR-3"]')
        ->assertSeeIn('#story-modal-title', 'Story BR-3')
        ->assertQueryStringHas('story', 'coins/BR-3')
        ->keys('[role="dialog"]', 'Escape')
        ->assertMissing('[role="dialog"]')
        ->assertNoJavaScriptErrors();
});

it('turns the initiative list into a select at 375px with no sideways scroll', function () {
    $page = visit('/p/coins/stories')->resize(375, 800);

    $page->assertVisible('[data-initiative-select]')
        ->assertMissing('[data-initiative-entry="branding"]')
        ->select('[data-initiative-select]', 'g1')
        ->assertScript(VISIBLE_ROWS, 'BR-1,BR-2,BR-3,BR-4,BR-5,BR-6,BR-7,BR-8,BR-9,BR-10')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
