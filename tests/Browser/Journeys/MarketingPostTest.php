<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Journey marketing-post (docs/journeys/marketing-post.md): from a recurring campaign to approved posts, ready to
 * publish. Created by MK-1 with step 1; each later MK story adds its step. Claude is faked once AI steps arrive
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
});
