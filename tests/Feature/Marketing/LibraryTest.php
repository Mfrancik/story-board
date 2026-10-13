<?php

use App\Livewire\Marketing\Library;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * MK-10 acceptance criteria, library half: `/p/{project}/marketing/library` is a calm archive in two sections —
 * Coming up (approved, by week) and Posted (by month, newest first) — with a campaign filter and caption search.
 * Drafts never appear. Clock frozen at Mon 12 Oct 2026 11:00 UTC; the owner is in New York, so "This week" is Mon 12 –
 * Sun 18 Oct. The 375 px criterion and a card opening the studio are in tests/Browser/Marketing/LibraryTest.php.
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
 * Coming up: three campaign posts over three weeks and a one-off; Posted: one in October, one in September. Plus what
 * must never show: a draft, a discarded post, a skipped placeholder, and a missed post (approved, its time passed).
 *
 * @return array<string, MarketingPost>
 */
function libraryPosts(Project $coins, MarketingCampaign $tvt, MarketingCampaign $set): array
{
    $at = fn (MarketingCampaign $c, string $when) => MarketingPost::factory()->forCampaign($c, $when);

    return [
        'rainbow' => $at($tvt, '2026-10-13 19:00')->approved()->create(['caption' => "Rainbow toning on a Morgan.\nShow us yours."]),
        'show' => MarketingPost::factory()->for($coins)->approved()->create(['scheduled_for' => Carbon::parse('2026-10-16 16:00'), 'caption' => 'Coin show this weekend']),
        'album' => $at($tvt, '2026-10-20 19:00')->approved()->create(['caption' => 'Album toning, straight from the paper.']),
        'proof' => $at($set, '2026-10-31 10:00')->approved()->create(['caption' => 'A proof Peace dollar set.']),
        'walker' => $at($tvt, '2026-10-06 19:00')->posted()->create(['caption' => 'Toning on a Walker half.']),
        'eagle' => $at($set, '2026-09-19 10:00')->posted()->create(['caption' => 'A silver eagle set.']),
        'draft' => $at($tvt, '2026-10-27 19:00')->create(['caption' => 'Draft toning idea.']),
        'discarded' => $at($tvt, '2026-10-27 19:00')->discarded()->create(['caption' => 'Discarded toning idea.']),
        'skipped' => $at($set, '2026-10-24 10:00')->skipped()->create(['caption' => '']),
        'missed' => $at($set, '2026-10-10 10:00')->approved()->create(['caption' => 'Missed toning post.']),
    ];
}

/**
 * The group labels of library section `$section` (coming or posted), in order.
 *
 * @return list<string>
 */
function libraryGroups(string $html, string $section): array
{
    return array_map(fn ($el) => trim($el->textContent), htmlQuery($html, '[data-library-section="'.$section.'"] [data-library-group-label]'));
}

/**
 * The post ids of the cards in library section `$section`, in order.
 *
 * @return list<int>
 */
function libraryCards(string $html, string $section): array
{
    return array_map(fn ($el) => (int) $el->getAttribute('data-library-card'), htmlQuery($html, '[data-library-section="'.$section.'"] [data-library-card]'));
}

/**
 * Section `$section`'s count, as its header shows it.
 */
function libraryCount(string $html, string $section): string
{
    return trim(htmlQuery($html, '[data-library-section="'.$section.'"] [data-library-count]')[0]->textContent);
}

it('Given drafts, approved and posted posts, then the library shows only approved (Coming up, grouped by week) and posted (grouped by month) posts, with correct counts; drafts and discarded posts never appear', function () {
    $p = libraryPosts($this->coins, $this->tvt, $this->set);

    $html = $this->get('/p/coins/marketing/library')->assertOk()->getContent();

    expect(libraryGroups($html, 'coming'))->toBe(['This week', 'Next week', 'Week of 26 Oct'])
        ->and(libraryCards($html, 'coming'))->toBe([$p['rainbow']->id, $p['show']->id, $p['album']->id, $p['proof']->id])
        ->and(libraryCount($html, 'coming'))->toBe('4')
        ->and(libraryGroups($html, 'posted'))->toBe(['October 2026', 'September 2026'])
        ->and(libraryCards($html, 'posted'))->toBe([$p['walker']->id, $p['eagle']->id])
        ->and(libraryCount($html, 'posted'))->toBe('2');
    foreach (['draft', 'discarded', 'skipped', 'missed'] as $never) {
        expect(htmlQuery($html, '[data-library-card="'.$p[$never]->id.'"]'))->toBeEmpty();
    }
    // Months collapse; the current one is open.
    $months = htmlQuery($html, '[data-library-section="posted"] details');
    expect($months[0]->hasAttribute('open'))->toBeTrue()->and($months[1]->hasAttribute('open'))->toBeFalse();
});

it('Given a campaign filter, then both sections show only that campaign\'s posts; one-offs show a "One-off" chip', function () {
    $p = libraryPosts($this->coins, $this->tvt, $this->set);

    $all = $this->get('/p/coins/marketing/library')->assertOk()->getContent();
    expect(trim(htmlQuery($all, '[data-library-card="'.$p['show']->id.'"] [data-campaign-chip]')[0]->textContent))->toBe('One-off')
        ->and(trim(htmlQuery($all, '[data-library-card="'.$p['rainbow']->id.'"] [data-campaign-chip]')[0]->textContent))->toBe('TrueView Tuesday');

    $page = Livewire::test(Library::class, ['project' => $this->coins])->set('filter', (string) $this->set->id);
    expect(libraryCards($page->html(), 'coming'))->toBe([$p['proof']->id])
        ->and(libraryCards($page->html(), 'posted'))->toBe([$p['eagle']->id])
        ->and(libraryCount($page->html(), 'coming'))->toBe('1');

    $page->set('filter', 'one-off');
    expect(libraryCards($page->html(), 'coming'))->toBe([$p['show']->id])
        ->and(libraryCards($page->html(), 'posted'))->toBe([]);
    $page->assertSee('Nothing posted matches.');
});

it('Given search "toning", then only matching posts are shown and the sections keep their grouping', function () {
    $p = libraryPosts($this->coins, $this->tvt, $this->set);

    $page = Livewire::test(Library::class, ['project' => $this->coins])->set('search', 'toning');

    expect(libraryCards($page->html(), 'coming'))->toBe([$p['rainbow']->id, $p['album']->id])
        ->and(libraryGroups($page->html(), 'coming'))->toBe(['This week', 'Next week'])
        ->and(libraryCards($page->html(), 'posted'))->toBe([$p['walker']->id])
        ->and(libraryGroups($page->html(), 'posted'))->toBe(['October 2026']);
});

it('searches a literal % or _ as itself, not as a wildcard', function () {
    libraryPosts($this->coins, $this->tvt, $this->set);

    $page = Livewire::test(Library::class, ['project' => $this->coins])->set('search', '%');

    expect(libraryCards($page->html(), 'coming'))->toBe([])->and(libraryCards($page->html(), 'posted'))->toBe([]);
});

it('Given no approved posts, then Coming up shows "Nothing approved yet. Open a campaign to fill dates."', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();

    $this->get('/p/coins/marketing/library')->assertOk()
        ->assertSee('Nothing approved yet. Open a campaign to fill dates.')
        ->assertSee('Nothing posted yet.');
});

it('offers All, each campaign and One-off as filter chips', function () {
    $html = $this->get('/p/coins/marketing/library')->assertOk()->getContent();

    expect(array_map(fn ($el) => trim($el->textContent), htmlQuery($html, '[data-library-filter]')))
        ->toBe(['All', 'Set Saturday', 'TrueView Tuesday', 'One-off']);
});

it('refuses a filter that is not a campaign of this project, shows everything, and logs why', function () {
    $other = MarketingCampaign::factory()->for(Project::factory()->create(['name' => 'rent-track']))->create();
    Log::spy();

    Livewire::test(Library::class, ['project' => $this->coins])->set('filter', (string) $other->id)->assertSet('filter', 'all');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context) => $event === 'marketing.library.filter_refused'
        && $context['project'] === 'coins' && $context['filter'] === (string) $other->id)->once();
});

it('opens a card in the post studio', function () {
    $p = libraryPosts($this->coins, $this->tvt, $this->set);

    $html = $this->get('/p/coins/marketing/library')->assertOk()->getContent();

    expect(htmlQuery($html, '[data-library-card="'.$p['show']->id.'"]')[0]->getAttribute('wire:click'))
        ->toContain('open-studio')->toContain('post: '.$p['show']->id);
});

it('logs marketing.library.viewed at debug with the page\'s counts', function () {
    libraryPosts($this->coins, $this->tvt, $this->set);
    Log::spy();

    $this->get('/p/coins/marketing/library')->assertOk();

    Log::shouldHaveReceived('debug')->with('marketing.library.viewed', ['project' => 'coins', 'coming_up' => 4, 'posted' => 2])->once();
});

it('runs a fixed number of queries however many posts there are', function () {
    libraryPosts($this->coins, $this->tvt, $this->set);
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/p/coins/marketing/library')->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();
    MarketingPost::factory()->count(3)->forCampaign($this->set, '2026-11-07 10:00')->approved()->create();

    expect($count())->toBe($before);
});

it('lists Library in the marketing sub-nav, current on the library', function () {
    $html = $this->get('/p/coins/marketing/library')->assertOk()->getContent();

    expect(htmlQuery($html, '[data-marketing-page="library"]')[0]->getAttribute('aria-current'))->toBe('page');
});
