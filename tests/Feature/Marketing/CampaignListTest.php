<?php

use App\Actions\Marketing\CampaignSchedule;
use App\Exceptions\CampaignScheduleException;
use App\Livewire\Marketing\CampaignList;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * MK-1 acceptance criteria: `/p/{project}/marketing` lists a project's campaigns with their next post,
 * approved-through date, 6-date runway and what needs the owner, under an alerts banner. The clock is frozen at
 * Mon 12 Oct 2026 11:00 (UTC, the app's timezone), so a Tue 19:00 campaign's next dates are Tue 13, 20, 27 Oct,
 * 3, 10, 17 Nov. The 375 px criterion is in tests/Browser/Marketing/CampaignListTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A Tue 19:00 campaign of `$project`, on unless `$paused`.
 */
function tuesdayCampaign(Project $project, string $name = 'TrueView Tuesday', bool $paused = false): MarketingCampaign
{
    $factory = MarketingCampaign::factory()->for($project)->named($name)->on([2], '19:00');

    return ($paused ? $factory->paused() : $factory)->create();
}

/**
 * One post of `$campaign` at `$at` (campaign-local) in `$status`.
 */
function postOn(MarketingCampaign $campaign, string $at, string $status): MarketingPost
{
    return MarketingPost::factory()->forCampaign($campaign, $at)->create(['status' => $status]);
}

/**
 * One campaign row of the page, `<li>` to `</li>`.
 */
function campaignRow(string $html, string $slug): string
{
    preg_match('/<li\b[^>]*data-campaign-row="'.preg_quote($slug, '/').'"[^>]*>.*?<\/li>/s', $html, $m);

    return $m[0] ?? '';
}

/**
 * The runway blocks' states inside `$html`, in order.
 *
 * @return list<string>
 */
function runwayStates(string $html): array
{
    preg_match_all('/data-runway-state="([^"]*)"/', $html, $m);

    return $m[1];
}

/**
 * The page's text with tags dropped and whitespace collapsed, for reading a sentence across elements.
 */
function pageText(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
}

it('Given an enabled project, when I open /p/{project}/marketing, then the campaigns list renders with the project header and the Marketing tab current', function () {
    tuesdayCampaign($this->coins);

    $html = $this->get('/p/coins/marketing')->assertOk()->assertSeeLivewire(CampaignList::class)->getContent();

    expect($html)->toContain('data-crumb-project')
        ->and($html)->toMatch('/data-project-tab="marketing"[^>]*aria-current="page"/')
        ->and($html)->toContain('data-campaign-list')
        ->and(campaignRow($html, 'trueview-tuesday'))->not->toBe('');
    // Marketing is the last tab, after RD-11's Overview-first order.
    preg_match_all('/data-project-tab="([^"]+)"/', $html, $m);
    expect($m[1])->toBe(['overview', 'stories', 'preflight', 'handbook', 'map', 'marketing']);
});

it('Given an unknown or disabled project, then the page is refused and board.project_page_refused is logged', function (string $name, string $reason) {
    Project::factory()->disabled()->create(['name' => 'parked']);
    Log::spy();

    $this->get("/p/{$name}/marketing")->assertNotFound();

    Log::shouldHaveReceived('info')->with('board.project_page_refused', ['project' => $name, 'reason' => $reason])->once();
})->with([
    'unknown' => ['nope', 'unknown'],
    'disabled' => ['parked', 'disabled'],
]);

it('Given a campaign on Tue 19:00 with Tue 13 and Tue 20 approved and Tue 27 open, when today is Mon 12 Oct, then the row reads "Approved through Tue 20 Oct" and the runway shows 2 approved, 1 open, …', function () {
    $campaign = tuesdayCampaign($this->coins);
    postOn($campaign, '2026-10-13 19:00', MarketingPost::STATUS_APPROVED);
    postOn($campaign, '2026-10-20 19:00', MarketingPost::STATUS_APPROVED);

    $row = campaignRow($this->get('/p/coins/marketing')->getContent(), 'trueview-tuesday');

    expect(pageText($row))->toContain('Approved through Tue 20 Oct')
        ->toContain('Tue 13 Oct · 19:00')
        ->toContain('2 of 6 approved')
        ->and(runwayStates($row))->toBe(['approved', 'approved', 'open', 'open', 'open', 'open']);
});

it('Given Tue 13 approved, Tue 20 open and Tue 27 approved, then approved-through is Tue 13 (unbroken run only)', function () {
    $campaign = tuesdayCampaign($this->coins);
    postOn($campaign, '2026-10-13 19:00', MarketingPost::STATUS_APPROVED);
    postOn($campaign, '2026-10-27 19:00', MarketingPost::STATUS_APPROVED);

    $row = campaignRow($this->get('/p/coins/marketing')->getContent(), 'trueview-tuesday');

    expect(pageText($row))->toContain('Approved through Tue 13 Oct')->not->toContain('Approved through Tue 27 Oct')
        ->and(runwayStates($row))->toBe(['approved', 'open', 'approved', 'open', 'open', 'open'])
        // Tue 13 is the only approved date this week (Mon 12 – Sun 18).
        ->and(pageText($row))->toContain('Nothing approved past this week');
});

it('Given a date with drafts and no approved post, then the row shows "1 to approve" and the banner counts it', function () {
    $campaign = tuesdayCampaign($this->coins);
    // Three candidates for one date are one date to approve, not three.
    MarketingPost::factory()->count(3)->forCampaign($campaign, '2026-10-13 19:00')->create();
    // A discarded candidate never needs the owner.
    MarketingPost::factory()->forCampaign($campaign, '2026-10-20 19:00')->discarded()->create();

    $html = $this->get('/p/coins/marketing')->getContent();
    $row = campaignRow($html, 'trueview-tuesday');

    expect(pageText($row))->toContain('1 to approve')->toContain('Nothing approved yet')
        ->and(runwayStates($row))->toBe(['review', 'open', 'open', 'open', 'open', 'open'])
        ->and($html)->toMatch('/data-marketing-alerts[^>]*>.*1 post needs your approval/s')
        ->and($html)->toMatch('/data-marketing-count[^>]*>\s*1\s*</');
});

it('Given an approved post scheduled yesterday and not marked posted, then the row shows "Missed <day>"; and no stored status says missed', function () {
    $campaign = MarketingCampaign::factory()->for($this->coins)->named('Sunday Set')->on([7], '19:00')->create();
    $post = postOn($campaign, '2026-10-11 19:00', MarketingPost::STATUS_APPROVED);
    // The week before was marked posted, so it is not missed.
    MarketingPost::factory()->forCampaign($campaign, '2026-10-04 19:00')->posted()->create();

    $html = $this->get('/p/coins/marketing')->getContent();

    expect(pageText(campaignRow($html, 'sunday-set')))->toContain('Missed Sun')
        ->and(pageText($html))->toContain('Sunday Set missed Sun 11 Oct')
        ->and($post->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and(MarketingPost::STATUSES)->not->toContain('missed')
        ->and(MarketingPost::where('status', 'missed')->exists())->toBeFalse();
});

it('Given a disabled campaign, then it is listed under Paused and not counted in the banner', function () {
    $paused = tuesdayCampaign($this->coins, 'Old Friday', paused: true);
    MarketingPost::factory()->forCampaign($paused, '2026-10-13 19:00')->create();

    $html = $this->get('/p/coins/marketing')->getContent();

    preg_match('/<section\b[^>]*data-paused-campaigns[^>]*>.*?<\/section>/s', $html, $group);
    expect($group[0] ?? '')->toContain('data-campaign-row="old-friday"')
        ->and(pageText($html))->not->toContain('need your approval')->not->toContain('needs your approval')
        ->and(pageText(campaignRow($html, 'old-friday')))->toContain('Paused')->not->toContain('to approve')
        ->and($html)->not->toContain('data-marketing-count');
});

it('Given no campaigns, then the empty state shows', function () {
    $this->get('/p/coins/marketing')->assertOk()
        ->assertSee('data-campaigns-empty', false)
        ->assertSeeText('No campaigns yet')
        ->assertDontSee('data-marketing-alerts', false);
});

it('Given a campaign in another project, then it is not listed', function () {
    $rent = Project::factory()->create(['name' => 'rent-track', 'state' => Project::STATE_OK, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $theirs = tuesdayCampaign($rent, 'Rent Reminders');
    MarketingPost::factory()->forCampaign($theirs, '2026-10-13 19:00')->create();
    tuesdayCampaign($this->coins);

    $html = $this->get('/p/coins/marketing')->getContent();

    expect($html)->not->toContain('rent-reminders')->not->toContain('Rent Reminders')
        ->and(campaignRow($html, 'trueview-tuesday'))->not->toBe('')
        ->and(pageText($html))->not->toContain('needs your approval');
});

/*
 * Guards and the rest of the story's In scope, beyond the acceptance criteria.
 */

it('logs marketing.runway.failed and shows the row\'s error state for a campaign whose timezone is not real, and still renders the page', function () {
    $bad = MarketingCampaign::factory()->for($this->coins)->named('Broken Clock')->create(['timezone' => 'Mars/Olympus']);
    tuesdayCampaign($this->coins);
    Log::spy();

    $html = $this->get('/p/coins/marketing')->assertOk()->getContent();

    expect(campaignRow($html, 'broken-clock'))->toContain('data-runway-error')
        ->and(pageText(campaignRow($html, 'broken-clock')))->toContain('Mars/Olympus')
        ->and(campaignRow($html, 'trueview-tuesday'))->toContain('data-runway-state');
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.runway.failed'
        && $context['campaign_id'] === $bad->id && $context['project'] === 'coins' && str_contains($context['reason'], 'Mars/Olympus'))->once();
});

it('logs marketing.campaigns.viewed at debug with the page\'s counts', function () {
    $campaign = tuesdayCampaign($this->coins);
    MarketingPost::factory()->forCampaign($campaign, '2026-10-13 19:00')->create();
    tuesdayCampaign($this->coins, 'Old Friday', paused: true);
    Log::spy();

    $this->get('/p/coins/marketing')->assertOk();

    Log::shouldHaveReceived('debug')->with('marketing.campaigns.viewed', ['project' => 'coins', 'campaigns' => 2, 'enabled' => 1, 'paused' => 1, 'to_approve' => 1, 'failed' => 0])->once();
});

it('shows the Marketing tab\'s count on every project page when something needs approval, and none when nothing does', function () {
    $campaign = tuesdayCampaign($this->coins);
    $this->get('/p/coins/stories')->assertOk()->assertDontSee('data-marketing-count', false);

    MarketingPost::factory()->forCampaign($campaign, '2026-10-13 19:00')->create();
    MarketingPost::factory()->forCampaign($campaign, '2026-10-20 19:00')->create();

    expect($this->get('/p/coins/stories')->getContent())->toMatch('/data-project-tab="marketing".*?data-marketing-count[^>]*>\s*2\s*</s');
});

it('lists only built Marketing pages in the sub-nav: Campaigns, Calendar and Library (MK-10) and Settings (MK-2), no dead links', function () {
    $html = $this->get('/p/coins/marketing')->getContent();

    preg_match('/<nav\b[^>]*data-marketing-subnav[^>]*>.*?<\/nav>/s', $html, $nav);
    preg_match_all('/data-marketing-page="([^"]+)"/', $nav[0] ?? '', $m);
    expect($m[1])->toBe(['campaigns', 'calendar', 'library', 'settings'])
        ->and($nav[0])->toMatch('/data-marketing-page="campaigns"[^>]*aria-current="page"/');
});

it('shows a campaign\'s open dates and a needed photo as badges, and on-track campaigns with nothing to do', function () {
    $campaign = tuesdayCampaign($this->coins);
    // A draft with no photo in a photo-per-post campaign needs one before it can go.
    MarketingPost::factory()->forCampaign($campaign, '2026-10-13 19:00')->create();
    $calm = MarketingCampaign::factory()->for($this->coins)->named('Text Thursday')->on([4], '10:00')
        ->create(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    foreach (['2026-10-15', '2026-10-22', '2026-10-29', '2026-11-05', '2026-11-12', '2026-11-19'] as $day) {
        postOn($calm, "{$day} 10:00", MarketingPost::STATUS_APPROVED);
    }

    $html = $this->get('/p/coins/marketing')->getContent();

    expect(pageText(campaignRow($html, 'trueview-tuesday')))->toContain('Needs a photo')->toContain('5 open dates')
        ->and(pageText(campaignRow($html, 'text-thursday')))->toContain('Approved through Thu 19 Nov')->toContain('Nothing')
        ->not->toContain('open date')->not->toContain('Nothing approved past this week');
});

it('refuses an update request once the project is switched off mid-visit', function () {
    $component = Livewire::test(CampaignList::class, ['project' => $this->coins]);
    $this->coins->update(['is_enabled' => false]);
    Log::spy();

    $component->call('$refresh')->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->with('board.project_page_refused', ['project' => 'coins', 'reason' => 'disabled', 'request' => 'update'])->once();
});

it('lists a campaign\'s next occurrences from now in its own timezone, skipping a time already passed today', function () {
    // Mon 12 Oct 11:00 UTC is 04:00 in Los Angeles; Mondays 09:00 there is 16:00 UTC, still ahead today.
    $la = MarketingCampaign::factory()->for($this->coins)->on([1, 3], '09:00')->create(['timezone' => 'America/Los_Angeles']);
    // 10:00 UTC on Mondays has already passed today.
    $utc = MarketingCampaign::factory()->for($this->coins)->on([1], '10:00')->create();
    $schedule = app(CampaignSchedule::class);

    expect(array_map(fn ($d) => $d->format('D j M H:i e'), $schedule->handle($la, now(), 3)))
        ->toBe(['Mon 12 Oct 09:00 America/Los_Angeles', 'Wed 14 Oct 09:00 America/Los_Angeles', 'Mon 19 Oct 09:00 America/Los_Angeles'])
        ->and($schedule->handle($utc, now(), 1)[0]->format('D j M H:i'))->toBe('Mon 19 Oct 10:00');
});

it('matches a post to its date in the campaign\'s timezone, not UTC\'s', function () {
    // Tue 19:00 in Los Angeles is Wed 02:00 UTC: the post still belongs to Tuesday's date.
    $campaign = MarketingCampaign::factory()->for($this->coins)->named('West Coast')->on([2], '19:00')->create(['timezone' => 'America/Los_Angeles']);
    postOn($campaign, '2026-10-13 19:00', MarketingPost::STATUS_APPROVED);

    $row = campaignRow($this->get('/p/coins/marketing')->getContent(), 'west-coast');

    expect(pageText($row))->toContain('Approved through Tue 13 Oct')
        ->and(runwayStates($row)[0])->toBe('approved');
});

it('leaves a campaign whose schedule cannot be read out of the tab count on other project pages, logs why, and still renders the page', function () {
    $bad = MarketingCampaign::factory()->for($this->coins)->create(['timezone' => 'Mars/Olympus']);
    MarketingPost::factory()->forCampaign(tuesdayCampaign($this->coins), '2026-10-13 19:00')->create();
    Log::spy();

    expect($this->get('/p/coins/stories')->assertOk()->getContent())->toMatch('/data-marketing-count[^>]*>\s*1\s*</');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.runway.failed'
        && $context['campaign_id'] === $bad->id && $context['source'] === 'tab_count')->once();
});

it('refuses a schedule whose days or time cannot be read', function (array $attributes, string $reason) {
    $campaign = MarketingCampaign::factory()->for($this->coins)->create($attributes);

    expect(fn () => app(CampaignSchedule::class)->handle($campaign, now(), 6))
        ->toThrow(CampaignScheduleException::class, $reason);
})->with([
    'a day outside 1–7' => [['days' => [0, 2]], 'ISO weekdays'],
    'a day that is not a number' => [['days' => ['tue']], 'ISO weekdays'],
    'a time that is not HH:MM' => [['time' => '25:00:00'], 'Unreadable time'],
]);
