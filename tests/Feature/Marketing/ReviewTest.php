<?php

use App\Actions\Marketing\BuildTasteExamples;
use App\Actions\Marketing\RejectDateDrafts;
use App\Actions\Marketing\SeedMarketingKit;
use App\Exceptions\MarketingPostRefusedException;
use App\Jobs\DraftOccurrenceJob;
use App\Jobs\RefreshProjectJob;
use App\Jobs\RenderPostAd;
use App\Livewire\Marketing\Review;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\DraftingStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * MK-20 acceptance criteria: the review inbox at `/p/{project}/marketing/review`. Every date waiting for a pick across
 * the project's campaigns (and one-offs), each with its rendered ads; Keep (ApprovePost), 👍/👎 (RecordFeedback),
 * Reject all 3 (dislikes, discard, redraft through MK-8's Fill for that date). Claude is faked with Http::fake, the
 * renderer by Tests\Support\FakeAdRenderer. The clock is frozen at Mon 12 Oct 2026 11:00 UTC: TrueView Tuesday posts
 * Tue 19:00 (13, 20, 27 Oct), Set Saturday Sat 10:00 (17, 24, 31 Oct). Both are text only, so a draft never waits
 * for a photo unless a test says so.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    Storage::fake('local');
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    MarketingBrand::factory()->for($this->coins)->create(['values' => SeedMarketingKit::BRAND, 'copy_rules' => SeedMarketingKit::COPY_RULES]);
    $textOnly = ['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY];
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create($textOnly);
    $this->set = MarketingCampaign::factory()->for($this->coins)->named('Set Saturday')->on([6], '10:00')->create($textOnly);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Three drafts of `$campaign` at `$at` (campaign timezone, UTC here), in candidate order, each with a rendered ad.
 *
 * @return list<MarketingPost>
 */
function reviewDrafts(MarketingCampaign $campaign, string $at, array $state = []): array
{
    return collect(['First draft line.', 'Second draft line.', 'Third draft line.'])
        ->map(fn (string $caption, int $i) => MarketingPost::factory()->forCampaign($campaign, $at)->create([
            'caption' => $caption,
            'ad' => ['layout' => 'text_card', 'headline' => $caption, 'render' => ['status' => MarketingPost::RENDER_RENDERED, 'square' => 900 + $i, 'portrait' => 950 + $i]],
            ...$state,
        ]))
        ->all();
}

/**
 * The criteria's queue: drafts on Tue 13 Oct and Tue 27 Oct (TrueView Tuesday) and Sat 17 Oct (Set Saturday); Tue
 * 20 Oct is already approved (its other drafts discarded).
 *
 * @return array<string, list<MarketingPost>>
 */
function reviewQueue(MarketingCampaign $tvt, MarketingCampaign $set): array
{
    $approved = MarketingPost::factory()->forCampaign($tvt, '2026-10-20 19:00')->approved()->create(['caption' => 'Approved already.']);
    MarketingPost::factory()->forCampaign($tvt, '2026-10-20 19:00')->discarded()->count(2)->create();

    return [
        'tue13' => reviewDrafts($tvt, '2026-10-13 19:00'),
        'sat17' => reviewDrafts($set, '2026-10-17 10:00'),
        'tue27' => reviewDrafts($tvt, '2026-10-27 19:00'),
        'approved' => [$approved],
    ];
}

/**
 * The Review page for coins as a Livewire test.
 */
function reviewPage(): mixed
{
    return Livewire::test(Review::class, ['project' => Project::where('name', 'coins')->firstOrFail()]);
}

/**
 * The rows the page shows, in order, by their `data-review-row` key.
 *
 * @return list<string>
 */
function reviewRows(string $html): array
{
    return array_map(fn ($el) => $el->getAttribute('data-review-row'), htmlQuery($html, '[data-review-row]'));
}

it('Given two campaigns with drafts on three dates and one date already approved, when I open Review, then exactly the three undecided dates show, soonest first, each with its three ads, and the sub-nav badge reads 3', function () {
    Log::spy();
    $q = reviewQueue($this->tvt, $this->set);

    $html = $this->get('/p/coins/marketing/review')->assertOk()->getContent();

    expect(reviewRows($html))->toBe(['trueview-tuesday/2026-10-13', 'set-saturday/2026-10-17', 'trueview-tuesday/2026-10-27']);
    foreach (['trueview-tuesday/2026-10-13' => 'tue13', 'set-saturday/2026-10-17' => 'sat17', 'trueview-tuesday/2026-10-27' => 'tue27'] as $row => $key) {
        $ads = array_map(fn ($el) => (int) $el->getAttribute('data-review-ad'), htmlQuery($html, '[data-review-row="'.$row.'"] [data-review-ad]'));
        expect($ads)->toBe(array_map(fn (MarketingPost $p) => $p->id, $q[$key]));
    }
    // The rendered 1:1 ad and the caption under it.
    expect(htmlQuery($html, '[data-review-ad="'.$q['tue13'][0]->id.'"] img')[0]->getAttribute('src'))->toBe(route('marketing.asset', ['coins', 900]))
        ->and(htmlQuery($html, '[data-review-ad="'.$q['tue13'][0]->id.'"] [data-review-caption]')[0]->textContent)->toContain('First draft line.')
        ->and(trim(htmlQuery($html, '[data-marketing-page="review"] [data-review-count]')[0]->textContent))->toBe('3')
        ->and(htmlQuery($html, '[data-marketing-page="review"]')[0]->getAttribute('aria-current'))->toBe('page')
        ->and(trim(htmlQuery($html, '[data-review-waiting]')[0]->textContent))->toStartWith('3 dates waiting');
    Log::shouldHaveReceived('debug')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.viewed' && $context['dates'] === 3)->once();
});

it('Given I Keep ad 2 of a date, then that post is approved, the other two are discarded, and the date leaves the queue', function () {
    Log::spy();
    $q = reviewQueue($this->tvt, $this->set);
    [$one, $two, $three] = $q['sat17'];

    $page = reviewPage()->call('keep', $two->id);

    expect($two->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and($one->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and($three->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and(reviewRows($page->html()))->toBe(['trueview-tuesday/2026-10-13', 'trueview-tuesday/2026-10-27']);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.kept' && $context['post_id'] === $two->id && $context['date'] === '2026-10-17')->once();
});

it('Given I dislike an ad with "too wordy", then a feedback row with that reason exists and taste examples include it', function () {
    $q = reviewQueue($this->tvt, $this->set);
    $ad = $q['tue13'][1];

    reviewPage()->call('vote', $ad->id, MarketingFeedback::DISLIKED, ['too wordy']);

    $row = MarketingFeedback::where('post_id', $ad->id)->sole();
    expect($row->vote)->toBe(MarketingFeedback::DISLIKED)->and($row->reasons)->toBe(['too wordy'])
        ->and(app(BuildTasteExamples::class)->handle($this->tvt)['disliked'])->toContain(['caption' => 'Second draft line.', 'reasons' => ['too wordy']]);
});

it('Given I Reject all 3 with reason "boring", then three dislikes are recorded, the three drafts are discarded, a draft job is queued for that date only, and the date shows "Drafting…"', function () {
    Queue::fake([RefreshProjectJob::class, DraftOccurrenceJob::class]);
    fakeClaude();
    Log::spy();
    $q = reviewQueue($this->tvt, $this->set);

    $page = reviewPage()->call('rejectAll', $this->tvt->id, '2026-10-13', 'boring');

    $ids = array_map(fn (MarketingPost $p) => $p->id, $q['tue13']);
    expect(MarketingFeedback::whereIn('post_id', $ids)->get()->map(fn ($f) => [$f->vote, $f->reasons])->all())
        ->toBe(array_fill(0, 3, [MarketingFeedback::DISLIKED, ['boring']]))
        ->and(MarketingPost::whereKey($ids)->pluck('status')->unique()->all())->toBe([MarketingPost::STATUS_DISCARDED])
        // The other dates are untouched.
        ->and(MarketingPost::whereKey(array_map(fn (MarketingPost $p) => $p->id, $q['tue27']))->pluck('status')->unique()->all())->toBe([MarketingPost::STATUS_DRAFT]);
    Queue::assertPushed(DraftOccurrenceJob::class, 1);
    Queue::assertPushed(DraftOccurrenceJob::class, fn (DraftOccurrenceJob $job) => $job->campaign->is($this->tvt) && $job->date === '2026-10-13');

    $html = $page->html();
    expect(reviewRows($html))->toBe(['trueview-tuesday/2026-10-13', 'set-saturday/2026-10-17', 'trueview-tuesday/2026-10-27'])
        ->and(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"] [data-review-drafting]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"]')[0]->textContent)->toContain('Drafting…')
        ->and(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"] [data-review-ad]'))->toHaveCount(0)
        // While a date drafts, the page polls for it.
        ->and(htmlQuery($html, '[data-polling]'))->not->toBeEmpty()
        // A date being drafted is not one waiting for a pick: the badge counts the other two.
        ->and(trim(htmlQuery($html, '[data-marketing-page="review"] [data-review-count]')[0]->textContent))->toBe('2');
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.rejected_all'
        && $context['post_ids'] === $ids && $context['reason'] === 'boring' && $context['date'] === '2026-10-13')->once();
});

it('Given Claude fails on the redraft, then the date shows MK-8\'s failure state with Retry and stays in the queue', function () {
    config(['services.anthropic.key' => 'test-key-not-real']);
    // The redraft fails; the Retry after it gets three new drafts.
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'api_error']], 500)
        ->push(claudeReply(['drafts' => [
            ['caption' => 'New one.', 'asset_id' => null, 'ad' => ['layout' => 'text_card', 'headline' => 'New one']],
            ['caption' => 'New two.', 'asset_id' => null, 'ad' => ['layout' => 'question', 'headline' => 'New two?']],
            ['caption' => 'New three.', 'asset_id' => null, 'ad' => ['layout' => 'stat', 'headline' => 'New three']],
        ]]))]);
    $q = reviewQueue($this->tvt, $this->set);

    // The sync queue runs the draft job inline, so the failure is already recorded when the call returns.
    $page = reviewPage()->call('rejectAll', $this->tvt->id, '2026-10-13');

    $html = $page->html();
    expect(reviewRows($html))->toContain('trueview-tuesday/2026-10-13')
        ->and(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"] [data-review-draft-failed]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"] [data-retry-draft]'))->toHaveCount(1)
        ->and(app(DraftingStatus::class)->states($this->tvt->id, ['2026-10-13']))->toBe(['2026-10-13' => DraftingStatus::FAILED])
        // A reason is optional: the dislikes carry none.
        ->and(MarketingFeedback::whereIn('post_id', array_map(fn (MarketingPost $p) => $p->id, $q['tue13']))->pluck('reasons')->all())->toBe([null, null, null]);

    // Retry is MK-8's Fill for that one date; with Claude back, three new drafts take the date's place.
    $html = $page->call('retryDraft', $this->tvt->id, '2026-10-13')->html();
    expect(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"] [data-review-ad]'))->toHaveCount(3)
        ->and(htmlQuery($html, '[data-review-row="trueview-tuesday/2026-10-13"] [data-review-draft-failed]'))->toHaveCount(0);
});

it('Given the campaign filter, then only that campaign\'s dates show and the count follows', function () {
    reviewQueue($this->tvt, $this->set);
    // A campaign with nothing waiting gets no chip.
    MarketingCampaign::factory()->for($this->coins)->named('Quiet Friday')->on([5], '12:00')->create();

    $page = reviewPage();
    expect(array_map(fn ($el) => $el->getAttribute('data-review-filter'), htmlQuery($page->html(), '[data-review-filter]')))
        ->toBe(['all', (string) $this->set->id, (string) $this->tvt->id]);

    $html = $page->set('filter', (string) $this->set->id)->html();
    expect(reviewRows($html))->toBe(['set-saturday/2026-10-17'])
        ->and(trim(htmlQuery($html, '[data-review-waiting]')[0]->textContent))->toStartWith('1 date waiting')
        ->and(htmlQuery($html, '[data-review-filter="'.$this->set->id.'"]')[0]->getAttribute('aria-pressed'))->toBe('true')
        // The sub-nav badge is the whole queue, whatever the filter.
        ->and(trim(htmlQuery($html, '[data-marketing-page="review"] [data-review-count]')[0]->textContent))->toBe('3');

    $html = $page->set('filter', (string) $this->tvt->id)->html();
    expect(reviewRows($html))->toBe(['trueview-tuesday/2026-10-13', 'trueview-tuesday/2026-10-27'])
        ->and(trim(htmlQuery($html, '[data-review-waiting]')[0]->textContent))->toStartWith('2 dates waiting');
});

it('Given no undecided dates, then the empty state shows and the badge is hidden', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create();

    $html = $this->get('/p/coins/marketing/review')->assertOk()->getContent();

    expect(htmlQuery($html, '[data-review-empty]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-review-empty]')[0]->textContent)->toContain('Nothing to pick.')->toContain('Fill some dates on a campaign page.')
        ->and(htmlQuery($html, '[data-review-row]'))->toBeEmpty()
        ->and(htmlQuery($html, '[data-marketing-page="review"]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-review-count]'))->toBeEmpty();
});

it('Given another project\'s post id in Keep, then it is refused (404) and logged', function () {
    Log::spy();
    reviewQueue($this->tvt, $this->set);
    $other = Project::factory()->create(['name' => 'other', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $theirs = MarketingCampaign::factory()->for($other)->on([2], '19:00')->create(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    $foreign = reviewDrafts($theirs, '2026-10-13 19:00')[0];

    reviewPage()->call('keep', $foreign->id)->assertNotFound();

    expect($foreign->fresh()->status)->toBe(MarketingPost::STATUS_DRAFT);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.refused'
        && $context['reason'] === 'not_in_queue' && $context['post_id'] === $foreign->id)->once();
});

it('refuses a vote, a render retry or a Keep on a post that is not waiting (approved, discarded) as a logged 404', function (string $method, array $args) {
    Log::spy();
    $q = reviewQueue($this->tvt, $this->set);
    $approved = $q['approved'][0];

    reviewPage()->call($method, $approved->id, ...$args)->assertNotFound();

    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.refused' && $context['post_id'] === $approved->id)->once();
})->with([
    'keep' => ['keep', []],
    'vote' => ['vote', [MarketingFeedback::LIKED]],
    'retry render' => ['retryRender', []],
]);

it('refuses Reject all on another project\'s campaign, a malformed date or a date with nothing to reject, and logs each', function (string $case) {
    Log::spy();
    $q = reviewQueue($this->tvt, $this->set);
    $other = Project::factory()->create(['name' => 'other']);
    $theirs = MarketingCampaign::factory()->for($other)->create();
    [$campaign, $date, $reason] = match ($case) {
        'foreign campaign' => [$theirs->id, '2026-10-13', 'unknown_campaign'],
        'bad date' => [$this->tvt->id, '13 Oct', 'bad_date'],
        'decided date' => [$this->tvt->id, '2026-10-20', 'not_in_queue'],
    };

    reviewPage()->call('rejectAll', $campaign, $date)->assertNotFound();

    expect(MarketingFeedback::count())->toBe(0)
        ->and($q['approved'][0]->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.refused' && $context['reason'] === $reason)->once();
})->with(['foreign campaign', 'bad date', 'decided date']);

it('keeps the drafts when the redraft cannot start (no API key), and says why', function () {
    config(['services.anthropic.key' => null]);
    $q = reviewQueue($this->tvt, $this->set);

    reviewPage()->call('rejectAll', $this->tvt->id, '2026-10-13', 'boring');

    // Fill refused before any write, so nothing was discarded and no dislike recorded.
    expect(MarketingPost::whereKey(array_map(fn (MarketingPost $p) => $p->id, $q['tue13']))->pluck('status')->unique()->all())->toBe([MarketingPost::STATUS_DRAFT])
        ->and(MarketingFeedback::count())->toBe(0);
});

it('refuses a reason that is not one of the four, and Reject all on a date already redrafting, writing nothing', function () {
    Queue::fake([RefreshProjectJob::class, DraftOccurrenceJob::class]);
    fakeClaude();
    Log::spy();
    $q = reviewQueue($this->tvt, $this->set);

    $page = reviewPage()->call('rejectAll', $this->tvt->id, '2026-10-13', 'meh');
    expect(MarketingFeedback::count())->toBe(0)
        ->and(MarketingPost::whereKey(array_map(fn (MarketingPost $p) => $p->id, $q['tue13']))->pluck('status')->unique()->all())->toBe([MarketingPost::STATUS_DRAFT]);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.post.refused' && $context['action'] === 'feedback' && $context['reason'] === 'unknown_reason')->once();

    // Once rejected, the date is drafting with no drafts: a second Reject all (a double key press) is refused.
    $page->call('rejectAll', $this->tvt->id, '2026-10-13')->call('rejectAll', $this->tvt->id, '2026-10-13')->assertNotFound();
    Queue::assertPushed(DraftOccurrenceJob::class, 1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.refused' && $context['reason'] === 'nothing_to_reject')->once();
});

it('refuses, in the Action itself, to reject a date with nothing to reject', function () {
    $q = reviewQueue($this->tvt, $this->set);
    fakeClaude();

    expect(fn () => app(RejectDateDrafts::class)->handle($this->tvt, '2026-10-20', null, now()))
        ->toThrow(MarketingPostRefusedException::class, 'This date has no drafts to reject.');
    expect($q['approved'][0]->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)->and(MarketingFeedback::count())->toBe(0);
});

it('falls back to All for a filter that is not one of the chips, and logs it', function () {
    Log::spy();
    reviewQueue($this->tvt, $this->set);

    $page = reviewPage()->set('filter', '99999');

    expect($page->get('filter'))->toBe('all')->and(reviewRows($page->html()))->toHaveCount(3);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.review.refused' && $context['reason'] === 'bad_filter')->once();
});

it('shows a one-off draft as its own date with its one ad, and Keep approves it', function () {
    $oneOff = MarketingPost::factory()->for($this->coins)->create(['scheduled_for' => Carbon::parse('2026-10-15 16:00'), 'caption' => 'Coin show this weekend', 'is_text_only' => true]);
    reviewDrafts($this->tvt, '2026-10-13 19:00');

    $page = reviewPage();
    $html = $page->html();
    expect(reviewRows($html))->toBe(['trueview-tuesday/2026-10-13', 'one-off/'.$oneOff->id])
        ->and(htmlQuery($html, '[data-review-row="one-off/'.$oneOff->id.'"] [data-review-ad]'))->toHaveCount(1)
        // A one-off is the owner's own post: there is no campaign to redraft it from.
        ->and(htmlQuery($html, '[data-review-row="one-off/'.$oneOff->id.'"] [data-reject-all]'))->toBeEmpty()
        ->and(array_map(fn ($el) => $el->getAttribute('data-review-filter'), htmlQuery($html, '[data-review-filter]')))->toBe(['all', (string) $this->tvt->id, 'one-off']);

    $page->call('keep', $oneOff->id);
    expect($oneOff->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and(reviewRows($page->html()))->toBe(['trueview-tuesday/2026-10-13']);
});

it('leaves out paused campaigns, past dates and skipped dates, the same rule as the campaigns list', function () {
    $paused = MarketingCampaign::factory()->for($this->coins)->named('Paused Monday')->on([1], '09:00')->paused()->create();
    reviewDrafts($paused, '2026-10-19 09:00');
    reviewDrafts($this->tvt, '2026-10-06 19:00');
    reviewDrafts($this->tvt, '2026-10-13 19:00', ['status' => MarketingPost::STATUS_SKIPPED]);
    reviewDrafts($this->tvt, '2026-10-27 19:00');

    $html = $this->get('/p/coins/marketing/review')->assertOk()->getContent();

    expect(reviewRows($html))->toBe(['trueview-tuesday/2026-10-27'])
        ->and(trim(htmlQuery($html, '[data-review-count]')[0]->textContent))->toBe('1')
        ->and(trim(htmlQuery($html, '[data-marketing-count]')[0]->textContent))->toBe('1');
});

it('shows "Rendering…" for an ad still rendering, and MK-5\'s HTML fallback with Retry for a failed one, which can still be kept', function () {
    [$rendering, $failed, $done] = reviewDrafts($this->tvt, '2026-10-13 19:00');
    $rendering->update(['ad' => ['layout' => 'text_card', 'headline' => 'x', 'render' => ['status' => MarketingPost::RENDER_RENDERING, 'at' => now()->toIso8601String()]]]);
    $failed->update(['ad' => ['layout' => 'text_card', 'headline' => 'x', 'render' => ['status' => MarketingPost::RENDER_FAILED, 'reason' => 'timeout']]]);

    $page = reviewPage();
    $html = $page->html();
    expect(htmlQuery($html, '[data-review-ad="'.$rendering->id.'"] [data-rendering]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-review-ad="'.$rendering->id.'"]')[0]->textContent)->toContain('Rendering…')
        ->and(htmlQuery($html, '[data-review-ad="'.$failed->id.'"] [data-post-preview]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-review-ad="'.$failed->id.'"] [data-retry-render="'.$failed->id.'"]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-keep="'.$failed->id.'"]')[0]->hasAttribute('disabled'))->toBeFalse()
        ->and(htmlQuery($html, '[data-polling]'))->not->toBeEmpty();

    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $page->call('retryRender', $failed->id);
    expect($failed->fresh()->renderState())->toBe(MarketingPost::RENDER_RENDERING);
    Queue::assertPushed(RenderPostAd::class, 1);

    $page->call('keep', $failed->id);
    expect($failed->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)->and($done->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED);
});

it('holds Keep on a draft that still needs its photo, and the studio is one click away', function () {
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_PER_POST]);
    [$needs] = reviewDrafts($this->tvt, '2026-10-13 19:00', ['ad' => null]);

    $page = reviewPage();
    $html = $page->html();
    expect(htmlQuery($html, '[data-keep="'.$needs->id.'"]')[0]->hasAttribute('disabled'))->toBeTrue()
        ->and(htmlQuery($html, '[data-review-ad="'.$needs->id.'"]')[0]->textContent)->toContain('Needs a photo')
        ->and(htmlQuery($html, '[data-open-in-studio="'.$needs->id.'"]')[0]->getAttribute('wire:click'))->toContain("'open-studio'")->toContain((string) $needs->id);

    // The key or a forged click is refused by ApprovePost and said in a toast; the post stays a draft.
    $page->call('keep', $needs->id);
    expect($needs->fresh()->status)->toBe(MarketingPost::STATUS_DRAFT);
});

it('shows each ad\'s vote, and a like replaces a dislike', function () {
    [$ad] = reviewDrafts($this->tvt, '2026-10-13 19:00');

    $page = reviewPage()->call('vote', $ad->id, MarketingFeedback::DISLIKED, ['boring']);
    expect(htmlQuery($page->html(), '[data-vote-down="'.$ad->id.'"]')[0]->getAttribute('aria-pressed'))->toBe('true');

    $html = $page->call('vote', $ad->id, MarketingFeedback::LIKED)->html();
    expect(MarketingFeedback::where('post_id', $ad->id)->sole()->vote)->toBe(MarketingFeedback::LIKED)
        ->and(htmlQuery($html, '[data-vote-up="'.$ad->id.'"]')[0]->getAttribute('aria-pressed'))->toBe('true');
});

it('reads the queue in a fixed number of queries, however many dates and campaigns wait', function () {
    reviewQueue($this->tvt, $this->set);
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/p/coins/marketing/review')->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();
    reviewDrafts($this->tvt, '2026-11-03 19:00');
    reviewDrafts($this->set, '2026-10-24 10:00');
    MarketingPost::factory()->for($this->coins)->create(['scheduled_for' => Carbon::parse('2026-10-15 16:00'), 'is_text_only' => true]);
    MarketingPost::factory()->for($this->coins)->create(['scheduled_for' => Carbon::parse('2026-10-16 16:00'), 'is_text_only' => true]);

    expect($count())->toBe($before);
});

it('lists Review in the marketing sub-nav of the other marketing pages, with its count', function () {
    reviewQueue($this->tvt, $this->set);

    $html = $this->get('/p/coins/marketing/library')->assertOk()->getContent();

    $link = htmlQuery($html, '[data-marketing-page="review"]')[0];
    expect($link->getAttribute('href'))->toBe(route('projects.marketing.review', 'coins'))
        ->and(trim(htmlQuery($html, '[data-marketing-page="review"] [data-review-count]')[0]->textContent))->toBe('3');
});

it('leaves out a campaign whose schedule cannot be read, logs why, and still shows the others', function () {
    Log::spy();
    reviewDrafts($this->tvt, '2026-10-13 19:00');
    $bad = MarketingCampaign::factory()->for($this->coins)->named('Broken Clock')->create(['timezone' => 'Mars/Olympus']);

    $html = $this->get('/p/coins/marketing/review')->assertOk()->getContent();

    expect(reviewRows($html))->toBe(['trueview-tuesday/2026-10-13']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.runway.failed'
        && ($context['source'] ?? null) === 'review' && $context['campaign_id'] === $bad->id)->atLeast()->once();
});
