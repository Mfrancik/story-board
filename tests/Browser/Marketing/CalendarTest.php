<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * MK-10 in a real browser: the calendar's quick-peek, the week / month toggle, the studio opened from a peek, and both
 * new pages at 375 px (the calendar becomes an agenda list, the library one column). Clock frozen at Mon 12 Oct 2026
 * 11:00, as in tests/Feature/Marketing/CalendarTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    config(['board.timezone' => 'America/New_York']);
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $tvt = MarketingCampaign::factory()->for($coins)->named('TrueView Tuesday')->on([2], '19:00')->create(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    MarketingPost::factory()->forCampaign($tvt, '2026-10-13 19:00')->approved()->create(['caption' => 'Rainbow toning on a Morgan.']);
    MarketingPost::factory()->forCampaign($tvt, '2026-10-06 19:00')->posted()->create(['caption' => 'Toning on a Walker half.']);
    MarketingPost::factory()->count(2)->forCampaign($tvt, '2026-10-20 19:00')->create();
    $set = MarketingCampaign::factory()->for($coins)->named('Set Saturday')->on([3, 6], '10:00')->create();
    MarketingPost::factory()->forCampaign($set, '2026-10-14 10:00')->approved()->create(['caption' => 'A proof Peace dollar set.']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('Given a chip, when clicked, then the quick-peek shows and "Open campaign" links to that campaign page', function () {
    visit('/p/coins/marketing/calendar')->resize(1280, 900)
        ->assertMissing('[data-peek]')
        ->click('[data-calendar-grid] [data-chip="trueview-tuesday"][data-chip-date="2026-10-13"]')
        ->assertVisible('[data-peek]')
        ->assertSeeIn('[data-peek]', 'TrueView Tuesday')
        ->assertSeeIn('[data-peek-when]', 'Tue 13 Oct · 19:00')
        ->assertSeeIn('[data-peek-state]', 'Approved')
        ->click('[data-peek-campaign]')
        ->assertPathIs('/p/coins/marketing/campaigns/trueview-tuesday')
        ->assertNoJavaScriptErrors();
});

it('opens the post studio from a quick-peek\'s "Open in studio"', function () {
    visit('/p/coins/marketing/calendar')->resize(1280, 900)
        ->click('[data-calendar-grid] [data-chip="trueview-tuesday"][data-chip-date="2026-10-20"]')
        ->assertSeeIn('[data-peek-state]', '2 drafts to approve')
        ->click('[data-peek-studio]')
        ->assertVisible('[data-studio] [role="dialog"]')
        ->assertSeeIn('[data-studio-when]', 'Tue 20 Oct · 19:00')
        ->assertMissing('[data-peek]')
        ->assertNoJavaScriptErrors();
});

it('Given week view, then only that week\'s 7 days show; the toggle returns to month (in the browser)', function () {
    visit('/p/coins/marketing/calendar')->resize(1280, 900)
        ->assertScript("document.querySelectorAll('[data-calendar-grid] [data-calendar-day]').length", 35)
        ->click('[data-view="week"]')
        ->assertScript("document.querySelectorAll('[data-calendar-grid] [data-calendar-day]').length", 7)
        ->assertSeeIn('[data-calendar-range]', '12 Oct – 18 Oct 2026')
        ->click('[data-view="month"]')
        ->assertScript("document.querySelectorAll('[data-calendar-grid] [data-calendar-day]').length", 35)
        ->assertVisible('[data-gap-banner]')
        ->assertNoJavaScriptErrors();
});

it('Given a 375px viewport, then the calendar is an agenda list and the library is one column, no sideways scroll', function () {
    visit('/p/coins/marketing/calendar')->resize(375, 812)
        ->assertVisible('[data-calendar-agenda]')
        ->assertMissing('[data-calendar-grid]')
        ->assertSeeIn('[data-agenda-day="2026-10-13"]', 'TrueView Tuesday')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();

    visit('/p/coins/marketing/library')->resize(375, 812)
        ->assertSeeIn('[data-library-section="coming"]', 'This week')
        // One column: every card in Coming up starts at the same left edge.
        ->assertScript("new Set([...document.querySelectorAll('[data-library-section=\"coming\"] [data-library-card]')].map(e => Math.round(e.getBoundingClientRect().left))).size", 1)
        ->assertScript("document.querySelectorAll('[data-library-section=\"coming\"] [data-library-card]').length", 2)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
