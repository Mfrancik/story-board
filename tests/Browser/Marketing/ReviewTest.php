<?php

use App\Actions\Marketing\QueuePostRender;
use App\Actions\Marketing\SeedMarketingKit;
use App\Jobs\DraftOccurrenceJob;
use App\Jobs\RefreshProjectJob;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * MK-20 in a real browser: the review inbox's keys (←/→ move the ring between dates, 1/2/3 keep, R opens Reject all)
 * and the 375 px layout, where each date's ads scroll sideways inside their row and the page never does. Drafts are
 * rendered by the fake renderer (Tests\Support\FakeAdRenderer) on the sync queue; only the draft job and the project
 * refresh are faked. Clock frozen at Mon 12 Oct 2026 11:00, as in tests/Feature/Marketing/ReviewTest.php.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class, DraftOccurrenceJob::class]);
    Storage::fake('local');
    fakeClaude();
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    MarketingBrand::factory()->for($coins)->create(['values' => SeedMarketingKit::BRAND, 'copy_rules' => SeedMarketingKit::COPY_RULES]);
    $textOnly = ['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY];
    $this->tvt = MarketingCampaign::factory()->for($coins)->named('TrueView Tuesday')->on([2], '19:00')->create($textOnly);
    $this->set = MarketingCampaign::factory()->for($coins)->named('Set Saturday')->on([6], '10:00')->create($textOnly);
    $this->drafts = [];
    foreach ([[$this->tvt, '2026-10-13 19:00'], [$this->set, '2026-10-17 10:00'], [$this->tvt, '2026-10-20 19:00']] as [$campaign, $at]) {
        $this->drafts[$campaign->slug.'/'.substr($at, 0, 10)] = collect([
            ["One album. 61 Morgans.\n36 empty holes, and every one has a story.", 'text_card'],
            ['Which empty hole would you fill first?', 'question'],
            ["61 down, 36 to go.\nOur Morgan set, one date at a time.", 'stat'],
        ])->map(function (array $draft) use ($campaign, $at) {
            $post = MarketingPost::factory()->forCampaign($campaign, $at)->create(['caption' => $draft[0]]);
            $post->update(['generation_meta' => ['ad' => ['layout' => $draft[1], 'headline' => strtok($draft[0], "\n")]]]);
            app(QueuePostRender::class)->handle($post);

            return $post->fresh();
        })->all();
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

it('Given keyboard 3 on a focused date, then ad 3 is kept', function () {
    $page = visit('/p/coins/marketing/review')->resize(1280, 900)
        ->assertAttribute('[data-review-row="trueview-tuesday/2026-10-13"]', 'data-focused', 'true')
        ->keys('[data-marketing-review]', 'ArrowRight')
        ->assertAttribute('[data-review-row="set-saturday/2026-10-17"]', 'data-focused', 'true')
        ->assertAttribute('[data-review-row="trueview-tuesday/2026-10-13"]', 'data-focused', 'false')
        ->keys('[data-marketing-review]', '3')
        ->assertMissing('[data-review-row="set-saturday/2026-10-17"]')
        // The next date slid into the ring's place, so the keys go on working from there.
        ->assertAttribute('[data-review-row="trueview-tuesday/2026-10-20"]', 'data-focused', 'true')
        ->assertSeeIn('[data-marketing-page="review"] [data-review-count]', '2')
        ->assertNoJavaScriptErrors();

    [$one, $two, $three] = $this->drafts['set-saturday/2026-10-17'];
    expect($three->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and($one->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and($two->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED);

    // R on the focused date opens the confirm; a reason and "Reject all and redraft" redraft that date only.
    $page->keys('[data-marketing-review]', 'r')
        ->assertVisible('[data-reject-confirm] [role="alertdialog"]')
        ->click('[data-reject-reason="boring"]')
        ->click('[data-reject-confirm] [data-confirm-ok]')
        ->assertVisible('[data-review-row="trueview-tuesday/2026-10-20"] [data-review-drafting]')
        ->assertSeeIn('[data-review-row="trueview-tuesday/2026-10-20"] [data-review-drafting]', 'Drafting…')
        ->assertNoJavaScriptErrors();
    $rejected = collect($this->drafts['trueview-tuesday/2026-10-20']);
    expect(MarketingFeedback::whereIn('post_id', $rejected->pluck('id'))->pluck('reasons')->all())->toBe(array_fill(0, 3, ['boring']));
    Queue::assertPushed(DraftOccurrenceJob::class, fn (DraftOccurrenceJob $job) => $job->date === '2026-10-20');
});

it('Given a 375px viewport, then each date\'s ads stack or swipe with no sideways page scroll', function () {
    visit('/p/coins/marketing/review')->resize(375, 812)
        ->assertVisible('[data-review-ad="'.$this->drafts['trueview-tuesday/2026-10-13'][0]->id.'"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        // The ads swipe inside their row: the row's strip is wider than it shows.
        ->assertScript("(() => { const s = document.querySelector('[data-review-row=\"trueview-tuesday/2026-10-13\"] [data-review-ad]').parentElement; return s.scrollWidth > s.clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
});
