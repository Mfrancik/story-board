<?php

use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * SB-10 in a real browser: `/p/coins` shows 8 initiative rows and "Show all" reveals the rest
 * without a round trip, the not-on-main kinds expand to their rows, a card row opens the SB-8
 * modal, "Refresh this project" confirms, and `/p/asset-track` shows "Nothing off main".
 */
beforeEach(function () {
    // Never a real refresh: the button's job is caught here.
    Queue::fake();
    $this->repo = new GitFixture;
    $this->repo->story('AUC-17', 'approved', 'auction')->commitAndPush();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, ...$fresh]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    $this->coins->update($fresh);

    foreach (range(1, 11) as $i) {
        Story::factory()->for($this->coins)->create(['status' => 'built', 'initiative' => sprintf('init-%02d', $i)]);
    }
    Story::factory()->for($this->coins)->count(3)->create(['location_kind' => Story::KIND_BRANCH, 'location' => 'branch design/ACQ-20-mockups', 'branch' => 'design/ACQ-20-mockups']);
    Story::factory()->for($this->coins)->create(['location_kind' => Story::KIND_UNTRACKED, 'location' => 'untracked in /code/coins', 'branch' => null]);
    Project::factory()->create(['name' => 'asset-track', ...$fresh]);
});

afterEach(function () {
    $this->repo->destroy();
});

it('shows 8 initiative rows, reveals the rest on Show all, and expands the branch rows', function () {
    $visible = "[...document.querySelectorAll('[data-initiative]')].filter(e => e.offsetParent !== null).length";
    $page = visit('/p/coins')->resize(1280, 900);

    // 12 initiatives: auction plus init-01..11.
    $page->assertScript($visible, 8)
        ->click('[data-show-all="initiatives"]')
        ->assertScript($visible, 12)
        ->assertMissing('[data-show-all="initiatives"]')
        ->assertSeeIn('[data-offmain-kind="branch"]', '3')
        ->assertSeeIn('[data-offmain-kind="untracked"]', '1')
        ->click('[data-offmain-kind="branch"]')
        ->assertVisible('[data-offmain-rows="branch"]')
        ->assertScript("document.querySelectorAll('[data-offmain-rows=\"branch\"] [data-offmain-row]').length", 3)
        ->assertScript("document.querySelector('[data-offmain-rows=\"branch\"]').textContent.includes('design/ACQ-20-mockups')", true)
        ->assertMissing('[data-offmain-rows="untracked"]')
        ->assertNoJavaScriptErrors();
});

it('opens a card row in the SB-8 modal and confirms Refresh this project', function () {
    visit('/p/coins')->resize(1280, 900)
        ->click('[data-card="build"] [data-row="AUC-17"]')
        ->assertVisible('[data-story-open="coins/AUC-17"]')
        ->keys('[aria-label="Close"]', 'Escape')
        ->assertMissing('[role="dialog"]')
        ->click('[data-refresh-project]')
        ->assertSee('Refresh queued for coins')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(RefreshProjectJob::class, fn (RefreshProjectJob $job) => $job->project->is($this->coins));
});

it('reads Nothing off main on asset-track and fits 375px without sideways scroll', function () {
    visit('/p/asset-track')->resize(375, 812)
        ->assertSeeIn('[data-offmain-panel]', 'Nothing off main')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);

    visit('/p/coins')->resize(375, 812)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
