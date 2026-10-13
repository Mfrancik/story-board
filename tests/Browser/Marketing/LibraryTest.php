<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * MK-10 library in a real browser at 1280 px: search narrows the sections as the owner types, the grid / list toggle
 * (Alpine, no request), a campaign filter chip, and a card opening its post in the studio — a one-off included, which
 * the studio reopens by its post id. The 375 px check is in tests/Browser/Marketing/CalendarTest.php. Clock frozen at
 * Mon 12 Oct 2026 11:00.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    config(['board.timezone' => 'America/New_York']);
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $tvt = MarketingCampaign::factory()->for($coins)->named('TrueView Tuesday')->on([2], '19:00')->create(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    $this->rainbow = MarketingPost::factory()->forCampaign($tvt, '2026-10-13 19:00')->approved()->create(['caption' => 'Rainbow toning on a Morgan.']);
    MarketingPost::factory()->forCampaign($tvt, '2026-10-06 19:00')->posted()->create(['caption' => 'Toning on a Walker half.']);
    $this->show = MarketingPost::factory()->for($coins)->approved()->create(['scheduled_for' => Carbon::parse('2026-10-16 16:00'), 'caption' => 'Coin show this weekend']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Script: how many library cards the page shows. */
const LIBRARY_CARDS = "document.querySelectorAll('[data-library-card]').length";

it('narrows both sections as the owner searches, and filters by campaign', function () {
    visit('/p/coins/marketing/library')->resize(1280, 900)
        ->assertScript(LIBRARY_CARDS, 3)
        ->type('[data-library-search]', 'toning')
        ->assertScript(LIBRARY_CARDS, 2)
        ->assertSeeIn('[data-library-section="posted"]', 'October 2026')
        ->clear('[data-library-search]')
        ->click('[data-library-filter="one-off"]')
        ->assertScript(LIBRARY_CARDS, 1)
        ->assertSeeIn('[data-library-card="'.$this->show->id.'"] [data-campaign-chip]', 'One-off')
        ->assertSee('Nothing posted matches.')
        ->assertNoJavaScriptErrors();
});

it('switches between grid and list without a request', function () {
    visit('/p/coins/marketing/library')->resize(1280, 900)
        ->assertScript("new Set([...document.querySelectorAll('[data-library-section=\"coming\"] [data-library-card]')].map(e => Math.round(e.getBoundingClientRect().top))).size", 1)
        ->click('[data-library-layout="list"]')
        ->assertAttribute('[data-marketing-library]', 'data-layout', 'list')
        ->assertScript("new Set([...document.querySelectorAll('[data-library-section=\"coming\"] [data-library-card]')].map(e => Math.round(e.getBoundingClientRect().top))).size", 2)
        ->assertNoJavaScriptErrors();
});

it('opens a campaign post and a one-off post in the studio from their cards', function () {
    visit('/p/coins/marketing/library')->resize(1280, 900)
        ->click('[data-library-card="'.$this->rainbow->id.'"]')
        ->assertVisible('[data-studio] [role="dialog"]')
        ->assertSeeIn('[data-studio-when]', 'Tue 13 Oct · 19:00')
        ->click('[data-studio-close]')
        ->assertMissing('[data-studio] [role="dialog"]')
        ->click('[data-library-card="'.$this->show->id.'"]')
        ->assertVisible('[data-studio] [role="dialog"]')
        ->assertSeeIn('[data-studio-when]', 'Fri 16 Oct · 12:00')
        ->assertNoJavaScriptErrors();
});
