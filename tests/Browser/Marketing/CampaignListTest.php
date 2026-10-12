<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * MK-1 in a real browser: the Campaigns list at 1280 px (banner, rows in columns, runway blocks, the Marketing tab's
 * count) and at 375 px, where every row stacks its fields in one column with no sideways scroll. Clock frozen at
 * Mon 12 Oct 2026 11:00, as in tests/Feature/Marketing/CampaignListTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $tvt = MarketingCampaign::factory()->for($coins)->named('TrueView Tuesday')->on([2], '19:00')->create();
    MarketingPost::factory()->forCampaign($tvt, '2026-10-13 19:00')->approved()->create();
    MarketingPost::factory()->count(3)->forCampaign($tvt, '2026-10-20 19:00')->create();
    $set = MarketingCampaign::factory()->for($coins)->named('Set Saturday With A Much Longer Campaign Name')->on([3, 6], '10:00')->create();
    MarketingPost::factory()->forCampaign($set, '2026-10-14 10:00')->approved()->create();
    MarketingCampaign::factory()->for($coins)->named('Old Friday')->on([5], '12:00')->paused()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Script: how many distinct left edges the fields of one row have (1 = stacked in one column). */
const ROW_COLUMNS = "new Set([...document.querySelector('[data-campaign-row=\"trueview-tuesday\"]').children].map(e => Math.round(e.getBoundingClientRect().left))).size";

it('shows the banner, the rows in columns with their runway, and the Marketing tab\'s count at 1280px', function () {
    visit('/p/coins/marketing')->resize(1280, 900)
        ->assertSeeIn('[data-marketing-alerts]', '1 post needs your approval')
        ->assertSeeIn('[data-campaign-row="trueview-tuesday"] [data-approved-through]', 'Approved through Tue 13 Oct')
        ->assertSeeIn('[data-campaign-row="trueview-tuesday"]', '1 to approve')
        ->assertSeeIn('[data-paused-campaigns]', 'Old Friday')
        ->assertSeeIn('[data-project-tab="marketing"] [data-marketing-count]', '1')
        ->assertAttribute('[data-project-tab="marketing"]', 'aria-current', 'page')
        ->assertScript("document.querySelectorAll('[data-campaign-row=\"trueview-tuesday\"] [data-runway-state]').length", 6)
        ->assertScript(ROW_COLUMNS, 4)
        ->assertNoJavaScriptErrors();
});

it('Given a 375px viewport, then rows stack with no sideways scroll', function () {
    visit('/p/coins/marketing')->resize(375, 812)
        ->assertSeeIn('[data-campaign-row="trueview-tuesday"]', 'Approved through Tue 13 Oct')
        ->assertScript(ROW_COLUMNS, 1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
