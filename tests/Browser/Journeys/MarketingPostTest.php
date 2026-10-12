<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Journey marketing-post (docs/journeys/marketing-post.md): from a recurring campaign to approved posts, ready to
 * publish. Created by MK-1 with step 1; each later MK story adds its step (MK-2: step 2, the brand in Settings; MK-7:
 * step 3, the campaign page). Claude is faked once AI steps arrive
 * (MK-8). `JOURNEY_SHOTS=1` saves each step to `storage/app/journey-shots/marketing-post/`.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $tvt = MarketingCampaign::factory()->for($coins)->named('TrueView Tuesday')->on([2], '19:00')->create();
    MarketingPost::factory()->forCampaign($tvt, '2026-10-13 19:00')->approved()->create();
    MarketingPost::factory()->forCampaign($tvt, '2026-10-20 19:00')->approved()->create();
    MarketingPost::factory()->forCampaign($tvt, '2026-10-27 19:00')->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('walks the marketing-post journey', function () {
    // Step 1 (MK-1): the project's Marketing tab opens on its campaigns, each with how far out it is approved.
    journeyStep('marketing-post', 'campaigns list', '/p/coins/marketing')
        ->assertAttribute('[data-project-tab="marketing"]', 'aria-current', 'page')
        ->assertSeeIn('[data-campaign-row="trueview-tuesday"] [data-approved-through]', 'Approved through Tue 20 Oct')
        ->assertSeeIn('[data-marketing-alerts]', '1 post needs your approval')
        ->assertNoJavaScriptErrors();

    // Step 2 (MK-2): StackerMint is seeded from its kit (here the trimmed fixture kit); Settings shows its brand,
    // and the seed kept the existing TrueView Tuesday rather than adding a second one.
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => base_path('tests/Fixtures/marketing-kit')])->assertSuccessful();
    expect(MarketingCampaign::where('slug', 'trueview-tuesday')->count())->toBe(1);

    journeyStep('marketing-post', 'brand kit and copy rules', '/p/coins/marketing/settings')
        ->assertAttribute('[data-marketing-page="settings"]', 'aria-current', 'page')
        ->assertSeeIn('[data-brand-sample]', 'StackerMint')
        ->assertSeeIn('[data-swatch="accent"]', '#B79F82')
        ->assertSeeIn('[data-facebook-card] h3', 'Phase 2')
        ->assertNoJavaScriptErrors();

    // Step 3 (MK-7): open TrueView Tuesday from the list, see its future posts and how far out it is approved, add a
    // topic in the owner's own words and save it.
    $page = visit('/p/coins/marketing')->click('[data-campaign-row="trueview-tuesday"] [data-campaign-link]')
        ->assertPathIs('/p/coins/marketing/campaigns/trueview-tuesday');
    journeyStep('marketing-post', 'campaign page', '/p/coins/marketing/campaigns/trueview-tuesday', $page)
        ->assertSeeIn('[data-status-line]', 'Approved through Tue 20 Oct')
        ->assertScript("document.querySelectorAll('[data-occurrence]').length", 8)
        ->assertAttribute('[data-occurrence="2026-10-27"]', 'data-occurrence-state', 'review')
        ->fill('[data-new-topic]', 'Album toning')
        ->keys('[data-new-topic]', 'Enter')
        ->assertScript("document.querySelectorAll('[data-own-topic]').length", 1)
        ->assertVisible('[data-unsaved]')
        ->click('[data-save]')
        ->assertMissing('[data-unsaved]')
        ->assertNoJavaScriptErrors();
    expect(MarketingCampaign::where('slug', 'trueview-tuesday')->firstOrFail()->topics['own'])->toBe([['text' => 'Album toning', 'detail' => null]]);
});
