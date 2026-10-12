<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * MK-7 in a real browser: the Alpine "Unsaved changes" marker and Discard, the story's browser check (create
 * "TrueView Tuesday", add two own topics, move its day and see the timeline move), and the 375 px layout. Clock frozen
 * at Mon 12 Oct 2026 11:00, as in tests/Feature/Marketing/CampaignPageTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Script: the first timeline date shown. */
const FIRST_OCCURRENCE = "document.querySelector('[data-occurrence]')?.dataset.occurrence";

it('Given I change the time without saving, then "Unsaved changes" shows; Discard restores the stored values', function () {
    MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create();

    visit('/p/coins/marketing/campaigns/trueview-tuesday')->resize(1280, 900)
        ->assertVisible('[data-saved]')
        ->assertMissing('[data-unsaved]')
        ->fill('#cp-time', '20:00')
        ->assertVisible('[data-unsaved]')
        ->assertSeeIn('[data-unsaved]', 'Unsaved changes')
        ->click('[data-discard]')
        ->assertValue('#cp-time', '19:00')
        ->assertMissing('[data-unsaved]')
        ->assertVisible('[data-saved]')
        ->assertNoJavaScriptErrors();

    expect(MarketingCampaign::where('slug', 'trueview-tuesday')->value('time'))->toBe('19:00:00');
});

it('creates TrueView Tuesday with two own topics, then moves its day and the timeline moves', function () {
    $page = visit('/p/coins/marketing')->resize(1280, 900)
        ->click('[data-new-campaign]')
        ->assertPathIs('/p/coins/marketing/campaigns/new')
        ->fill('#cp-name', 'TrueView Tuesday')
        ->click('[data-day="2"] + span')
        ->fill('[data-new-topic]', 'Album toning')
        ->keys('[data-new-topic]', 'Enter')
        ->assertScript("document.querySelectorAll('[data-own-topic]').length", 1)
        ->fill('[data-new-topic]', 'Rate the rainbow')
        ->click('[data-add-topic]')
        ->assertScript("document.querySelectorAll('[data-own-topic]').length", 2)
        ->click('[data-save]')
        ->assertPathIs('/p/coins/marketing/campaigns/trueview-tuesday')
        ->assertSeeIn('[data-sentence]', 'Every Tuesday at 7pm')
        ->assertScript(FIRST_OCCURRENCE, '2026-10-13');

    $page->click('[data-day="2"] + span')
        ->click('[data-day="3"] + span')
        ->assertVisible('[data-unsaved]')
        ->click('[data-save]')
        ->assertScript(FIRST_OCCURRENCE, '2026-10-14')
        ->assertSeeIn('[data-sentence]', 'Every Wednesday at 7pm')
        ->assertMissing('[data-unsaved]')
        ->assertNoJavaScriptErrors();

    $campaign = MarketingCampaign::where('slug', 'trueview-tuesday')->firstOrFail();
    expect($campaign->days)->toBe([3])
        ->and(array_column($campaign->topics['own'], 'text'))->toBe(['Album toning', 'Rate the rainbow']);
});

it('Given a 375px viewport, then the timeline and criteria stack with no sideways scroll', function () {
    $tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday With A Much Longer Campaign Name')->on([2], '19:00')->create([
        'topics' => ['ideas' => [], 'own' => [['text' => 'A rather long topic written in the owner\'s own words about album toning', 'detail' => 'the rainbow came from the paper']]],
    ]);
    MarketingPost::factory()->forCampaign($tvt, '2026-10-13 19:00')->approved()->create(['caption' => str_repeat('A very long caption without breaks ', 6)]);
    MarketingPost::factory()->count(3)->forCampaign($tvt, '2026-10-20 19:00')->create();
    MarketingPost::factory()->forCampaign($tvt, '2026-10-06 19:00')->posted()->create();

    visit('/p/coins/marketing/campaigns/trueview-tuesday-with-a-much-longer-campaign-name')->resize(375, 812)
        ->assertVisible('[data-future-posts]')
        ->assertVisible('[data-criteria]')
        ->assertScript("Math.round(document.querySelector('[data-future-posts]').getBoundingClientRect().left) === Math.round(document.querySelector('[data-criteria]').getBoundingClientRect().left)", true)
        ->assertScript("document.querySelector('[data-criteria]').getBoundingClientRect().top > document.querySelector('[data-past-posts]').getBoundingClientRect().top", true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
