<?php

use App\Actions\Marketing\ListCampaignOccurrences;
use App\Livewire\Marketing\CampaignPage;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * MK-7 acceptance criteria: one page per campaign at `/p/{project}/marketing/campaigns/{slug}` (create mode at
 * `/campaigns/new`) with its header, status line, the next 8 dates, the criteria form and its past posts. The clock
 * is frozen at Mon 12 Oct 2026 11:00 UTC, so a Tue 19:00 UTC campaign's next dates are Tue 13, 20, 27 Oct, 3, 10,
 * 17, 24 Nov, 1 Dec. The 375 px criterion and the live "Unsaved changes" marker are in
 * tests/Browser/Marketing/CampaignPageTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->other = Project::factory()->create(['name' => 'other', ...$fresh]);
    MarketingBrand::factory()->for($this->coins)->create(['idea_menu' => [
        ['category' => 'Toned & TrueView', 'ideas' => [['title' => 'TrueView Tuesday', 'text' => 'Show us your best toner.'], ['title' => 'Toning 101', 'text' => 'Toning is chemistry.']]],
        ['category' => 'Sets', 'ideas' => [['title' => 'Slot filled', 'text' => 'Another slot filled.']]],
    ]]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create([
        'topics' => ['ideas' => ['Toned & TrueView|TrueView Tuesday'], 'own' => []],
        'brief' => 'Show off the toning.',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The campaign page component for `$slug` of coins, or create mode when `$slug` is null.
 */
function campaignPage(?string $slug = 'trueview-tuesday'): mixed
{
    $params = ['project' => Project::where('name', 'coins')->firstOrFail()];
    if ($slug !== null) {
        $params['campaign'] = $slug;
    }

    return Livewire::test(CampaignPage::class, $params);
}

/**
 * The page's text with tags dropped and whitespace collapsed.
 */
function campaignPageText(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
}

it('Given a campaign, when I open its page, then header, status line, 8 future occurrences and criteria render', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create(['caption' => 'Rainbow toned Morgan.']);
    MarketingPost::factory()->count(2)->forCampaign($this->tvt, '2026-10-27 19:00')->create();

    $html = $this->get('/p/coins/marketing/campaigns/trueview-tuesday')->assertOk()->getContent();
    $text = campaignPageText($html);

    expect($html)->toContain('data-campaign-page="trueview-tuesday"')
        ->and($text)->toContain('TrueView Tuesday')
        ->and($text)->toContain('Tue 19:00 weekly')
        ->and($text)->toContain('Every Tuesday at 7pm, post about TrueView Tuesday, with a photo you add')
        ->and($text)->toContain('Approved through Tue 20 Oct · 1 date needs approval · 3 open')
        ->and(substr_count($html, 'data-occurrence="'))->toBe(8)
        ->and($html)->toContain('data-occurrence="2026-10-20" data-occurrence-state="approved"')
        ->and($html)->toContain('data-occurrence="2026-10-27" data-occurrence-state="review"')
        ->and($html)->toContain('data-occurrence="2026-11-03" data-occurrence-state="open"')
        ->and($text)->toContain('Rainbow toned Morgan.')
        ->and($text)->toContain('2 drafts')
        ->and($html)->toContain('data-criteria')
        ->and($html)->toContain('role="switch"')
        ->and($html)->toContain('data-project-tab="marketing"');
});

it('Given I add own topics "Album toning" (detail "the rainbow came from the paper") and "Rate the rainbow", when I save, then both are stored with their detail and listed in order', function () {
    campaignPage()
        ->set('newTopic', 'Album toning')->call('addOwnTopic')
        ->set('own.0.detail', 'the rainbow came from the paper')
        ->set('newTopic', 'Rate the rainbow')->call('addOwnTopic')
        ->assertSet('newTopic', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Album toning', 'Rate the rainbow']);

    expect($this->tvt->fresh()->topics['own'])->toBe([
        ['text' => 'Album toning', 'detail' => 'the rainbow came from the paper'],
        ['text' => 'Rate the rainbow', 'detail' => null],
    ]);
    // Each own topic is an inline-editable input, so its text is the input's value.
    expect($this->get('/p/coins/marketing/campaigns/trueview-tuesday')->getContent())
        ->toMatch('/value="Album toning".*the rainbow came from the paper.*value="Rate the rainbow"/s');
});

it('Given I change the time without saving, then "Unsaved changes" shows; Discard restores the stored values', function () {
    $page = campaignPage()->assertSet('time', '19:00');
    // The marker itself is Alpine (no round trip while typing): it compares the inputs with `stored`, which must hold
    // the stored values. Its live show/hide is pinned in the browser test of the same name.
    expect($page->get('stored')['time'])->toBe('19:00')
        ->and($page->html())->toContain('Unsaved changes');

    $page->set('time', '20:00')->set('name', 'Renamed')->set('newTopic', 'Draft topic')->call('addOwnTopic')
        ->call('discard')
        ->assertSet('time', '19:00')
        ->assertSet('name', 'TrueView Tuesday')
        ->assertSet('own', []);

    expect($this->tvt->fresh()->time)->toBe('19:00:00');
});

it('Given a name already used in this project, then validation fails; given no day selected, then validation fails', function () {
    MarketingCampaign::factory()->for($this->coins)->named('Set Saturday')->on([6], '10:00')->create();

    campaignPage()->set('name', 'Set Saturday')->call('save')->assertHasErrors(['name' => 'unique']);
    campaignPage()->set('name', 'set saturday')->call('save')->assertHasErrors(['name' => 'unique']);
    campaignPage()->set('days', [])->call('save')->assertHasErrors(['days' => 'required']);

    expect($this->tvt->fresh()->name)->toBe('TrueView Tuesday')->and($this->tvt->fresh()->days)->toBe([2]);
});

it('Given approved posts on Tue 20 and the campaign moves to Wednesday, then Tue 20 stays approved on Tue 20 and the next open occurrences fall on Wednesdays', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create();

    campaignPage()->set('days', ['3'])->call('save')->assertHasNoErrors();

    expect($post->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and($post->fresh()->scheduled_for->toDateTimeString())->toBe('2026-10-20 19:00:00');

    $rows = app(ListCampaignOccurrences::class)->handle($this->tvt->fresh(), CarbonImmutable::now());
    $tue20 = collect($rows)->first(fn (array $r) => $r['at']->toDateString() === '2026-10-20');
    $open = collect($rows)->where('state', 'open');

    expect($tue20['state'])->toBe('approved')
        ->and($tue20['on_schedule'])->toBeFalse()
        ->and($open)->toHaveCount(8)
        ->and($open->every(fn (array $r) => $r['at']->dayOfWeekIso === 3))->toBeTrue()
        ->and($open->first()['at']->toDateString())->toBe('2026-10-14');

    $html = $this->get('/p/coins/marketing/campaigns/trueview-tuesday')->getContent();
    expect($html)->toContain('data-occurrence="2026-10-20" data-occurrence-state="approved"')
        ->and($html)->toContain('data-occurrence="2026-10-14" data-occurrence-state="open"')
        ->and($html)->not->toContain('data-occurrence="2026-10-27"');
});

it('Given posted posts for this campaign and another, then Past posts lists only this campaign\'s', function () {
    $set = MarketingCampaign::factory()->for($this->coins)->named('Set Saturday')->on([6], '10:00')->create();
    MarketingPost::factory()->forCampaign($this->tvt, '2026-09-29 19:00')->posted()->create(['caption' => 'Older toner post.']);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-06 19:00')->posted()->create(['caption' => 'Newer toner post.']);
    MarketingPost::factory()->forCampaign($set, '2026-10-10 10:00')->posted()->create(['caption' => 'A set post.']);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-06 19:00')->discarded()->create(['caption' => 'A discarded draft.']);

    $html = $this->get('/p/coins/marketing/campaigns/trueview-tuesday')->getContent();
    preg_match('/<section[^>]*data-past-posts.*?<\/section>/s', $html, $m);
    $past = campaignPageText($m[0] ?? '');

    expect($past)->toMatch('/Newer toner post\..*Older toner post\./')
        ->and($past)->not->toContain('A set post.')
        ->and($past)->not->toContain('A discarded draft.')
        ->and(preg_match_all('/data-past-post\b(?!s)/', $m[0] ?? ''))->toBe(2);
});

it('Given create mode, when I save a valid campaign, then I land on its page and it appears on the campaigns list', function () {
    Log::spy();
    $this->get('/p/coins/marketing/campaigns/new')->assertOk()->assertSee('New campaign')->assertSee('Create campaign');

    campaignPage(null)
        ->set('name', 'Album Friday')
        ->set('days', ['5'])
        ->set('time', '18:30')
        ->set('ideas', ['Sets|Slot filled'])
        ->set('photoMode', MarketingCampaign::PHOTO_TEXT_ONLY)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.marketing.campaign', ['coins', 'album-friday']));

    $created = MarketingCampaign::where('slug', 'album-friday')->firstOrFail();
    expect($created->project_id)->toBe($this->coins->id)
        ->and($created->days)->toBe([5])
        ->and($created->time)->toBe('18:30:00')
        ->and($created->timezone)->toBe(config('board.timezone'))
        ->and($created->is_enabled)->toBeTrue()
        // toEqual: MySQL hands JSON object keys back in its own order.
        ->and($created->topics)->toEqual(['ideas' => ['Sets|Slot filled'], 'own' => []]);

    $list = $this->get('/p/coins/marketing')->getContent();
    expect($list)->toContain('href="'.route('projects.marketing.campaign', ['coins', 'album-friday']).'"')
        ->and($list)->toContain('href="'.route('projects.marketing.campaign.create', 'coins').'"');

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx) => $event === 'marketing.campaign.created'
        && $ctx['project'] === 'coins' && $ctx['campaign_id'] === $created->id && $ctx['slug'] === 'album-friday')->once();
});

it('Given a slug from another project, then 404', function () {
    Log::spy();
    MarketingCampaign::factory()->for($this->other)->named('Other Thing')->create();

    $this->get('/p/coins/marketing/campaigns/other-thing')->assertNotFound();
    $this->get('/p/other/marketing/campaigns/trueview-tuesday')->assertNotFound();
    $this->get('/p/coins/marketing/campaigns/no-such-campaign')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx) => $event === 'marketing.campaign.not_found'
        && $ctx['project'] === 'coins' && $ctx['slug'] === 'other-thing')->once();
});

it('refuses the campaign page of a disabled project', function () {
    $this->coins->update(['is_enabled' => false]);

    $this->get('/p/coins/marketing/campaigns/trueview-tuesday')->assertNotFound();
    $this->get('/p/coins/marketing/campaigns/new')->assertNotFound();
});

it('switches the campaign off from the header, keeps its posts and logs marketing.campaign.toggled', function () {
    Log::spy();
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create();

    campaignPage()->call('toggle')->assertSee('Paused');

    expect($this->tvt->fresh()->is_enabled)->toBeFalse()
        ->and($post->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and(MarketingPost::where('campaign_id', $this->tvt->id)->count())->toBe(1);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx) => $event === 'marketing.campaign.toggled'
        && $ctx['project'] === 'coins' && $ctx['campaign_id'] === $this->tvt->id && $ctx['is_enabled'] === false)->once();

    campaignPage()->call('toggle');
    expect($this->tvt->fresh()->is_enabled)->toBeTrue();
});

it('logs marketing.campaign.updated with the keys that changed and keeps the slug on a rename', function () {
    Log::spy();

    campaignPage()->set('name', 'TrueView Tues')->set('time', '20:00')->call('save')->assertHasNoErrors();

    $fresh = $this->tvt->fresh();
    expect($fresh->name)->toBe('TrueView Tues')->and($fresh->slug)->toBe('trueview-tuesday')->and($fresh->time)->toBe('20:00:00');
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx) => $event === 'marketing.campaign.updated'
        && $ctx['project'] === 'coins' && $ctx['campaign_id'] === $this->tvt->id && $ctx['changed'] === ['name', 'time'])->once();
});

it('stores ticked ideas as "Category|Title" and the brief and photo choice', function () {
    campaignPage()
        ->set('ideas', ['Toned & TrueView|TrueView Tuesday', 'Toned & TrueView|Toning 101'])
        ->set('brief', 'One line, then ask for theirs.')
        ->set('photoMode', MarketingCampaign::PHOTO_FAVOURITES)
        ->call('save')->assertHasNoErrors();

    $fresh = $this->tvt->fresh();
    expect($fresh->topics['ideas'])->toBe(['Toned & TrueView|TrueView Tuesday', 'Toned & TrueView|Toning 101'])
        ->and($fresh->brief)->toBe('One line, then ask for theirs.')
        ->and($fresh->photo_mode)->toBe(MarketingCampaign::PHOTO_FAVOURITES);
});

it('removes an own topic and refuses an empty one', function () {
    $this->tvt->update(['topics' => ['ideas' => [], 'own' => [['text' => 'Keep me', 'detail' => null], ['text' => 'Drop me', 'detail' => 'x']]]]);

    campaignPage()
        ->set('newTopic', '   ')->call('addOwnTopic')
        ->assertHasErrors(['newTopic' => 'required'])
        ->assertSet('own', [['text' => 'Keep me', 'detail' => ''], ['text' => 'Drop me', 'detail' => 'x']])
        ->call('removeOwnTopic', 1)
        ->call('save')->assertHasNoErrors();

    expect($this->tvt->fresh()->topics['own'])->toBe([['text' => 'Keep me', 'detail' => null]]);
});

it('refuses a bad time, timezone, weekday or photo choice', function () {
    campaignPage()
        ->set('time', '25:00')->set('timezone', 'Mars/Olympus')->set('days', ['8'])->set('photoMode', 'polaroid')
        ->call('save')
        ->assertHasErrors(['time', 'timezone', 'days.0', 'photoMode']);

    expect($this->tvt->fresh()->time)->toBe('19:00:00');
});

it('gives a new campaign a free slug: never "new", never one the project already has', function () {
    campaignPage(null)->set('name', 'New')->set('days', ['1'])->call('save')->assertHasNoErrors();
    campaignPage(null)->set('name', 'TrueView Tuesday!')->set('days', ['1'])->call('save')->assertHasNoErrors();

    expect(MarketingCampaign::where('name', 'New')->value('slug'))->toBe('new-2')
        ->and(MarketingCampaign::where('name', 'TrueView Tuesday!')->value('slug'))->toBe('trueview-tuesday-2');
    $this->get('/p/coins/marketing/campaigns/new-2')->assertOk()->assertSee('data-campaign-page="new-2"', false);
});

it('shows a missed post and a skipped date in the timeline, and drafts with their count', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-06 19:00')->approved()->create();
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->skipped()->create();
    MarketingPost::factory()->count(3)->forCampaign($this->tvt, '2026-10-20 19:00')->create();

    $html = $this->get('/p/coins/marketing/campaigns/trueview-tuesday')->getContent();

    expect($html)->toContain('data-occurrence="2026-10-06" data-occurrence-state="missed"')
        ->and($html)->toContain('data-occurrence="2026-10-13" data-occurrence-state="skipped"')
        ->and($html)->toContain('data-occurrence="2026-10-20" data-occurrence-state="review"')
        ->and(campaignPageText($html))->toContain('3 drafts')
        ->and(campaignPageText($html))->toContain('missed Tue 6 Oct');
});

it('shows why a campaign\'s dates cannot be worked out and logs it, and the criteria still render', function () {
    Log::spy();
    $this->tvt->update(['timezone' => 'Mars/Olympus']);

    $html = $this->get('/p/coins/marketing/campaigns/trueview-tuesday')->assertOk()->getContent();

    expect($html)->toContain('data-timeline-error')->and($html)->toContain('data-criteria');
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $ctx) => $event === 'marketing.runway.failed'
        && $ctx['campaign_id'] === $this->tvt->id && $ctx['source'] === 'campaign_page')->atLeast()->once();
});

it('lists ideas from the brand\'s menu by category, and a pick no longer on the menu so it can be unticked', function () {
    $this->tvt->update(['topics' => ['ideas' => ['Toned & TrueView|TrueView Tuesday', 'Gone|Old idea'], 'own' => []]]);

    $text = campaignPageText($this->get('/p/coins/marketing/campaigns/trueview-tuesday')->getContent());

    expect($text)->toMatch('/Toned & TrueView.*TrueView Tuesday.*Toning 101.*Sets.*Slot filled/')
        ->and($text)->toContain('Old idea');
});

it('offers "+ New campaign" on an empty campaigns list and links every row to its page', function () {
    $this->get('/p/other/marketing')->assertOk()
        ->assertSee('data-campaigns-empty', false)
        ->assertSee('href="'.route('projects.marketing.campaign.create', 'other').'"', false);

    preg_match('/<li\b[^>]*data-campaign-row="trueview-tuesday".*?<\/li>/s', $this->get('/p/coins/marketing')->getContent(), $row);
    expect($row[0] ?? '')->toContain('href="'.route('projects.marketing.campaign', ['coins', 'trueview-tuesday']).'"');
});
