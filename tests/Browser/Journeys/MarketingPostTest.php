<?php

use App\Actions\Marketing\StoreInspiration;
use App\Actions\Marketing\StoreMarketingAsset;
use App\Jobs\RefreshProjectJob;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Journey marketing-post (docs/journeys/marketing-post.md): from a recurring campaign to approved posts, ready to
 * publish. Created by MK-1 with step 1; each later MK story adds its step (MK-2: step 2, the brand in Settings; MK-7:
 * step 3, the campaign page; MK-8: step 4, Fill; MK-9: step 5, the post studio; MK-10: step 6, the calendar; MK-18:
 * step 7, inspiration; MK-5: step 8, rendered ads). Claude is faked with Http::fake (fakeClaude()) by one reply carrying `drafts` (what Fill
 * reads), `caption` (what Revise reads) and `headline`/`style`/`topic` (what an inspiration read reads), since the
 * first matching fake wins. Only RefreshProjectJob is faked on the queue, so Fill's DraftOccurrenceJob and the
 * inspiration read run inline on the sync queue, and so does each draft's RenderPostAd with the fake renderer
 * (Tests\Support\FakeAdRenderer, bound in Tests\TestCase; the real render is tests/Browser/Marketing/AdRendererTest.php). The local disk is faked from the start, because step 2's seed now
 * copies the kit's images into inspiration. `JOURNEY_SHOTS=1` saves each step to `storage/app/journey-shots/marketing-post/`.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    Storage::fake('local');
    fakeClaude(['drafts' => [
        ['caption' => "Rainbow toning on a Morgan.\nShow us your best toner.", 'asset_id' => null],
        ['caption' => 'Album toning, straight from the paper.', 'asset_id' => null],
        ['caption' => 'Rate this rainbow from one to ten.', 'asset_id' => null],
    ], 'caption' => 'Album toning, straight from the paper. Show us yours.',
        'headline' => 'Rainbow album toning', 'style' => ['dark full-bleed coin'], 'topic' => 'album toning']);
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

    // Step 4 (MK-8): Fill the first open TrueView Tuesday date; (fake) Claude drafts 3 posts and the date turns to
    // "Needs your approval · 3 drafts", each needing the photo the owner adds (photo per post).
    $page->assertAttribute('[data-occurrence="2026-11-03"]', 'data-occurrence-state', 'open')
        ->click('[data-fill="2026-11-03"]')
        ->assertAttribute('[data-occurrence="2026-11-03"]', 'data-occurrence-state', 'review');
    journeyStep('marketing-post', 'fill a date', '/p/coins/marketing/campaigns/trueview-tuesday', $page)
        ->assertSeeIn('[data-occurrence="2026-11-03"]', '3 drafts')
        ->assertSeeIn('[data-occurrence="2026-11-03"]', 'Needs a photo')
        ->assertNoJavaScriptErrors();
    $drafts = MarketingPost::whereDate('scheduled_for', '2026-11-03')->get();
    expect($drafts)->toHaveCount(3)
        ->and($drafts->pluck('source')->unique()->all())->toBe(['ai'])
        ->and($drafts->every(fn (MarketingPost $p) => $p->generation_meta['model'] === 'claude-sonnet-5-5'))->toBeTrue();

    // Step 5 (MK-9): open Nov 3 in the post studio, flip to the second draft and ask for a change (Rebuild → v2), give
    // it a photo, and Approve & next: the other drafts are discarded and the studio moves on to Oct 27. The photo is
    // a favourite picked in the studio, not an upload through the input: Pest's browser server does not forward
    // multipart files (see tests/Browser/Marketing/SettingsTest.php); the upload is covered in
    // tests/Feature/Marketing/PostStudioTest.php.
    $photo = app(StoreMarketingAsset::class)->handle(Project::where('name', 'coins')->firstOrFail(), UploadedFile::fake()->image('morgan.jpg', 400, 400));
    [, $second] = $drafts->all();
    $page->click('[data-open-studio="2026-11-03"]')
        ->assertVisible('[data-studio] [role="dialog"]')
        ->assertSeeIn('[data-studio-counter]', '2 of 2 to approve')
        ->click('[data-draft="'.$drafts[0]->id.'"] [aria-label="Next draft"]')
        ->fill('#fb-'.$second->id, 'Say it came from the album.')
        ->click('[data-rebuild="'.$second->id.'"]')
        ->assertSeeIn('[data-version="'.$second->id.'"]', 'v2')
        ->assertAttribute('[data-approve-next="'.$second->id.'"]', 'disabled', '')
        ->click('[data-use-favourite="'.$second->id.'"]')
        ->click('[data-draft="'.$second->id.'"] [data-favourite="'.$photo->id.'"]')
        ->assertEnabled('[data-approve-next="'.$second->id.'"]');
    journeyStep('marketing-post', 'post studio', '/p/coins/marketing/campaigns/trueview-tuesday', $page)
        ->click('[data-approve-next="'.$second->id.'"]')
        ->assertSeeIn('[data-studio] h2', 'TrueView Tuesday')
        ->assertSeeIn('[data-studio-when]', 'Tue 27 Oct · 19:00')
        ->assertSeeIn('[data-studio-counter]', '1 of 1 to approve')
        ->assertNoJavaScriptErrors();
    expect($second->fresh())->status->toBe(MarketingPost::STATUS_APPROVED)->version->toBe(2)->asset_id->toBe($photo->id)
        ->and($drafts[0]->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and($drafts[2]->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED);

    // Step 6 (MK-10): the calendar, from the marketing sub-nav, shows the post approved in step 5 on its date, Tue 3
    // Nov, a month ahead of the one it opens on.
    $page->click('[data-studio-close]')
        ->click('[data-marketing-page="calendar"]')
        ->assertPathIs('/p/coins/marketing/calendar')
        ->assertSeeIn('[data-calendar-range]', 'October 2026')
        ->click('[data-calendar-next]')
        ->assertSeeIn('[data-calendar-range]', 'November 2026');
    journeyStep('marketing-post', 'calendar', '/p/coins/marketing/calendar', $page)
        ->assertAttribute('[data-calendar-grid] [data-calendar-day="2026-11-03"] [data-chip="trueview-tuesday"]', 'data-chip-state', 'approved')
        ->assertNoJavaScriptErrors();

    // Step 7 (MK-18): one ad the owner likes goes on the Inspiration wall and is read (fake Claude), then Fill on the
    // next open date quotes it, and the studio shows it in that draft's "Inspired by" row. The image is stored by
    // StoreInspiration, not dropped through the input: Pest's browser server does not forward multipart files; the
    // drop is covered in tests/Feature/Marketing/InspirationTest.php.
    $ad = app(StoreInspiration::class)->handle(Project::where('name', 'coins')->firstOrFail(), UploadedFile::fake()->image('liked-ad.png', 300, 300));
    expect($ad->fresh()->ai_notes['headline'])->toBe('Rainbow album toning');
    $page->click('[data-marketing-page="inspiration"]')
        ->assertPathIs('/p/coins/marketing/inspiration')
        ->assertSeeIn('[data-inspiration="'.$ad->id.'"]', 'Rainbow album toning')
        ->click('[data-favourite-inspiration="'.$ad->id.'"]')
        ->assertAttribute('[data-favourite-inspiration="'.$ad->id.'"]', 'aria-pressed', 'true');
    journeyStep('marketing-post', 'inspiration', '/p/coins/marketing/inspiration', $page)->assertNoJavaScriptErrors();

    $page = visit('/p/coins/marketing/campaigns/trueview-tuesday')
        ->assertAttribute('[data-occurrence="2026-11-10"]', 'data-occurrence-state', 'open')
        ->click('[data-fill="2026-11-10"]')
        ->assertAttribute('[data-occurrence="2026-11-10"]', 'data-occurrence-state', 'review')
        ->click('[data-open-studio="2026-11-10"]')
        ->assertVisible('[data-studio] [role="dialog"]');
    $filled = MarketingPost::whereDate('scheduled_for', '2026-11-10')->orderBy('id')->firstOrFail();
    // The hearted ad comes first; the seeded kit's TrueView Tuesday post follows as the closest match.
    expect($filled->generation_meta['inspiration_ids'][0])->toBe($ad->id);
    journeyStep('marketing-post', 'inspired by', '/p/coins/marketing/campaigns/trueview-tuesday', $page)
        ->assertSeeIn('[data-inspired-by="'.$filled->id.'"]', 'Inspired by')
        ->assertVisible('[data-inspired-by="'.$filled->id.'"] [data-inspired-asset="'.$ad->id.'"]')
        ->assertNoJavaScriptErrors();

    // Step 8 (MK-5): after Fill, every draft is a rendered on-brand ad. Ask the community (seeded in step 2) lets the
    // AI decide on photos, so its drafts need none and render at once: the studio shows the render, with Download of
    // the 1:1 and the 4:5 PNG.
    $page = visit('/p/coins/marketing/campaigns/ask-the-community')
        ->assertAttribute('[data-occurrence="2026-10-18"]', 'data-occurrence-state', 'open')
        ->click('[data-fill="2026-10-18"]')
        ->assertAttribute('[data-occurrence="2026-10-18"]', 'data-occurrence-state', 'review')
        ->click('[data-open-studio="2026-10-18"]')
        ->assertVisible('[data-studio] [role="dialog"]');
    $rendered = MarketingPost::whereDate('scheduled_for', '2026-10-18')->orderBy('id')->get();
    expect($rendered)->toHaveCount(3)
        ->and($rendered->every(fn (MarketingPost $p) => $p->renderState() === MarketingPost::RENDER_RENDERED))->toBeTrue();
    $first = $rendered->first();
    journeyStep('marketing-post', 'rendered ad', '/p/coins/marketing/campaigns/ask-the-community', $page)
        ->assertVisible('[data-ad-render="'.$first->id.'"]')
        ->assertAttribute('[data-draft="'.$first->id.'"] [data-download-ad="square"]', 'href', route('marketing.render', ['coins', $first->id, 'square']))
        ->assertAttribute('[data-draft="'.$first->id.'"] [data-download-ad="portrait"]', 'href', route('marketing.render', ['coins', $first->id, 'portrait']))
        ->click('[data-draft="'.$first->id.'"] [data-ratio="portrait"]')
        ->assertNoJavaScriptErrors();
    foreach (['square' => [1080, 1080], 'portrait' => [1080, 1350]] as $size => $dims) {
        $png = $this->get(route('marketing.render', ['coins', $first->id, $size]))->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent();
        expect(array_slice(getimagesizefromstring($png) ?: [], 0, 2))->toBe($dims);
    }
});
