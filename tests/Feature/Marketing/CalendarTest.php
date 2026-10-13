<?php

use App\Livewire\Marketing\Calendar;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * MK-10 acceptance criteria, calendar half: `/p/{project}/marketing/calendar` draws every campaign's posts on a month
 * (or week) grid with their state, open dates as ghosts, and a banner over the first run of 4+ days with nothing
 * approved. The clock is frozen at Mon 12 Oct 2026 11:00 UTC; campaigns post in UTC and the owner is in New York, so
 * every date in these tests is the same day in both. The quick-peek click and 375 px are in
 * tests/Browser/Marketing/CalendarTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    config(['board.timezone' => 'America/New_York']);
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create();
    $this->set = MarketingCampaign::factory()->for($this->coins)->named('Set Saturday')->on([3, 6], '10:00')->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The state of `$campaign`'s chip on `$date` in the calendar grid, or null when that day has none.
 */
function calendarChipState(string $html, string $campaign, string $date): ?string
{
    $chips = htmlQuery($html, '[data-calendar-grid] [data-calendar-day="'.$date.'"] [data-chip="'.$campaign.'"]');

    return $chips === [] ? null : $chips[0]->getAttribute('data-chip-state');
}

/**
 * The dates of the grid's days, in order.
 *
 * @return list<string>
 */
function calendarGridDays(string $html): array
{
    return array_map(fn ($el) => $el->getAttribute('data-calendar-day'), htmlQuery($html, '[data-calendar-grid] [data-calendar-day]'));
}

/**
 * An approved one-off post of `$project` at noon New York time on `$date`.
 */
function approvedOneOff(Project $project, string $date, string $caption = 'One-off'): MarketingPost
{
    return MarketingPost::factory()->for($project)->approved()->create([
        'scheduled_for' => Carbon::parse($date.' 12:00', 'America/New_York')->utc(), 'caption' => $caption,
    ]);
}

it('Given posts across two campaigns in October, when I open the calendar, then each appears on its date with its state, and open occurrences show as ghosts', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-06 19:00')->posted()->create();
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();
    MarketingPost::factory()->count(2)->forCampaign($this->tvt, '2026-10-20 19:00')->create();
    MarketingPost::factory()->forCampaign($this->set, '2026-10-10 10:00')->approved()->create();
    MarketingPost::factory()->forCampaign($this->set, '2026-10-14 10:00')->approved()->create();
    // The studio's Skip writes an empty-caption skipped placeholder: the date needs nothing, so no chip and no ghost.
    MarketingPost::factory()->forCampaign($this->set, '2026-10-24 10:00')->skipped()->create(['caption' => '']);
    MarketingPost::factory()->forCampaign($this->set, '2026-10-28 10:00')->discarded()->create();

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->assertSee('October 2026')->getContent();

    expect(calendarChipState($html, 'trueview-tuesday', '2026-10-06'))->toBe('posted')
        ->and(calendarChipState($html, 'trueview-tuesday', '2026-10-13'))->toBe('approved')
        ->and(calendarChipState($html, 'trueview-tuesday', '2026-10-20'))->toBe('review')
        ->and(calendarChipState($html, 'set-saturday', '2026-10-10'))->toBe('missed')
        ->and(calendarChipState($html, 'set-saturday', '2026-10-14'))->toBe('approved')
        // Ghosts: open dates of the schedule from today on.
        ->and(calendarChipState($html, 'trueview-tuesday', '2026-10-27'))->toBe('open')
        ->and(calendarChipState($html, 'set-saturday', '2026-10-17'))->toBe('open')
        ->and(calendarChipState($html, 'set-saturday', '2026-10-21'))->toBe('open')
        // The skipped date shows nothing, a discarded post leaves its date open, and a past open date is no ghost.
        ->and(calendarChipState($html, 'set-saturday', '2026-10-24'))->toBeNull()
        ->and(calendarChipState($html, 'set-saturday', '2026-10-28'))->toBe('open')
        ->and(calendarChipState($html, 'set-saturday', '2026-10-07'))->toBeNull();

    $drafts = htmlQuery($html, '[data-calendar-grid] [data-calendar-day="2026-10-20"] [data-chip="trueview-tuesday"]')[0];
    expect($drafts->textContent)->toContain('2 drafts');
    expect(htmlQuery($html, '[data-calendar-grid] [data-chip-state="open"]')[0]->getAttribute('class'))->toContain('border-dashed');
});

it('shows a one-off post on its date, and no ghosts for a paused campaign', function () {
    approvedOneOff($this->coins, '2026-10-16', 'Coin show this weekend');
    $this->set->update(['is_enabled' => false]);

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    expect(calendarChipState($html, 'one-off', '2026-10-16'))->toBe('approved')
        ->and(calendarChipState($html, 'set-saturday', '2026-10-17'))->toBeNull()
        ->and(calendarChipState($html, 'trueview-tuesday', '2026-10-13'))->toBe('open');
});

it('Given nothing approved for 4+ consecutive days ahead, then the gap banner names the range', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();
    MarketingPost::factory()->forCampaign($this->set, '2026-10-14 10:00')->approved()->create();
    approvedOneOff($this->coins, '2026-10-16');
    approvedOneOff($this->coins, '2026-10-23');

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    $banner = htmlQuery($html, '[data-gap-banner]');
    expect($banner)->toHaveCount(1)
        ->and(preg_replace('/\s+/', ' ', $banner[0]->textContent))->toContain('Nothing approved Sat 17 – Thu 22 Oct');
});

it('Given no such gap, then no banner', function () {
    // An approved post every third day from today to the horizon (today + 27 days): never 4 days in a row without.
    for ($day = Carbon::parse('2026-10-12'); $day->lte(Carbon::parse('2026-11-08')); $day->addDays(3)) {
        approvedOneOff($this->coins, $day->toDateString());
    }

    $this->get('/p/coins/marketing/calendar')->assertOk()->assertDontSee('data-gap-banner', false);
});

it('names an open-ended gap that runs to the horizon', function () {
    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    expect(preg_replace('/\s+/', ' ', htmlQuery($html, '[data-gap-banner]')[0]->textContent))
        ->toContain('Nothing approved Mon 12 Oct – Sun 8 Nov and beyond');
});

it('Given week view, then only that week\'s 7 days show; the toggle returns to month', function () {
    $page = Livewire::test(Calendar::class, ['project' => $this->coins]);
    expect(calendarGridDays($page->html()))->toHaveCount(35)->and(calendarGridDays($page->html())[0])->toBe('2026-09-28');

    $page->call('setView', 'week');
    expect(calendarGridDays($page->html()))->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']);
    $page->assertSee('12 Oct – 18 Oct 2026');

    $page->call('setView', 'month');
    expect(calendarGridDays($page->html()))->toHaveCount(35);
});

it('steps a month or a week back and forth, and Today returns to this one', function () {
    $page = Livewire::test(Calendar::class, ['project' => $this->coins]);

    $page->call('next')->assertSee('November 2026');
    expect(calendarGridDays($page->html()))->toHaveCount(42)->and(calendarGridDays($page->html())[0])->toBe('2026-10-26');
    $page->call('previous')->call('previous')->assertSee('September 2026');
    $page->call('today')->assertSee('October 2026');

    $page->call('setView', 'week')->call('next');
    expect(calendarGridDays($page->html())[0])->toBe('2026-10-19');
});

it('refuses a view that is neither month nor week, and logs why', function () {
    Log::spy();

    Livewire::test(Calendar::class, ['project' => $this->coins])->call('setView', 'year')->assertSet('mode', 'month');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context) => $event === 'marketing.calendar.view_refused'
        && $context['project'] === 'coins' && $context['view'] === 'year')->once();
});

it('Given a chip, its quick-peek carries the date, campaign, state and the campaign page\'s link', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    $chip = htmlQuery($html, '[data-calendar-grid] [data-calendar-day="2026-10-13"] [data-chip="trueview-tuesday"]')[0];
    // The attribute is `peek = JSON.parse('…')`, Js::from's form: a JS string literal holding the JSON.
    preg_match("/JSON\\.parse\\('(.*)'\\)/s", (string) $chip->getAttribute('x-on:click'), $m);
    $peek = json_decode(json_decode('"'.$m[1].'"'), true);
    expect($peek)->toMatchArray([
        'when' => 'Tue 13 Oct · 19:00', 'campaign' => 'TrueView Tuesday', 'state' => 'Approved',
        'campaignUrl' => url('/p/coins/marketing/campaigns/trueview-tuesday'),
    ]);
});

it('lists each campaign in the tray with its switch and a link to its page; the switch pauses it', function () {
    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();
    expect(htmlQuery($html, '[data-calendar-tray] [data-tray-campaign="set-saturday"] [role="switch"]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-tray-campaign="set-saturday"] a[data-tray-open][href$="/p/coins/marketing/campaigns/set-saturday"]'))->toHaveCount(1);

    Livewire::test(Calendar::class, ['project' => $this->coins])->call('toggleCampaign', $this->set->id);

    expect($this->set->fresh()->is_enabled)->toBeFalse();
});

it('refuses to switch a campaign of another project, and logs why', function () {
    $other = MarketingCampaign::factory()->for(Project::factory()->create(['name' => 'rent-track']))->create();
    Log::spy();

    Livewire::test(Calendar::class, ['project' => $this->coins])->call('toggleCampaign', $other->id)->assertNotFound();

    expect($other->fresh()->is_enabled)->toBeTrue();
    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context) => $event === 'marketing.calendar.campaign_refused'
        && $context['campaign_id'] === $other->id && $context['project'] === 'coins')->once();
});

it('draws the next two weeks as an agenda list for a narrow screen', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    $days = array_map(fn ($el) => $el->getAttribute('data-agenda-day'), htmlQuery($html, '[data-calendar-agenda] [data-agenda-day]'));
    expect($days)->toHaveCount(14)->and($days[0])->toBe('2026-10-12')->and(end($days))->toBe('2026-10-25')
        ->and(htmlQuery($html, '[data-calendar-agenda] [data-agenda-day="2026-10-13"] [data-chip="trueview-tuesday"]')[0]->getAttribute('data-chip-state'))->toBe('approved');
});

it('shows a campaign whose schedule cannot be read in the tray with why, logs it, and still draws the rest', function () {
    MarketingCampaign::factory()->for($this->coins)->named('Broken')->create(['timezone' => 'Mars/Olympus']);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();
    Log::spy();

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    expect(calendarChipState($html, 'trueview-tuesday', '2026-10-13'))->toBe('approved')
        ->and(htmlQuery($html, '[data-tray-campaign="broken"] [data-tray-error]'))->toHaveCount(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context) => $event === 'marketing.runway.failed'
        && $context['source'] === 'calendar' && str_contains($context['reason'], 'Mars/Olympus'))->once();
});

it('logs marketing.calendar.viewed at debug with the page\'s counts', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();
    Log::spy();

    $this->get('/p/coins/marketing/calendar')->assertOk();

    Log::shouldHaveReceived('debug')->withArgs(fn (string $event, array $context) => $event === 'marketing.calendar.viewed'
        && $context === ['project' => 'coins', 'view' => 'month', 'from' => '2026-09-28', 'to' => '2026-11-01', 'chips' => 1, 'ghosts' => 8, 'gap' => true])->once();
});

it('runs a fixed number of queries however many campaigns there are', function () {
    foreach (['A', 'B', 'C'] as $name) {
        $c = MarketingCampaign::factory()->for($this->coins)->named('Extra '.$name)->on([1, 4], '09:00')->create();
        MarketingPost::factory()->forCampaign($c, '2026-10-15 09:00')->approved()->create();
    }
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/p/coins/marketing/calendar')->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();
    foreach (['D', 'E'] as $name) {
        $c = MarketingCampaign::factory()->for($this->coins)->named('Extra '.$name)->on([1, 4], '09:00')->create();
        MarketingPost::factory()->forCampaign($c, '2026-10-15 09:00')->approved()->create();
    }

    expect($count())->toBe($before);
});

it('lists Calendar in the marketing sub-nav, current on the calendar', function () {
    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    expect(htmlQuery($html, '[data-marketing-page="calendar"]')[0]->getAttribute('aria-current'))->toBe('page');
});
