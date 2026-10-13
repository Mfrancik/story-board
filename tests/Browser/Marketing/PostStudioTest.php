<?php

use App\Models\MarketingAsset;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * MK-9 in a real browser: the studio's Alpine (flip with the arrows, the dots and the arrow keys; the compare toggle;
 * Escape closes) and the 375 px sheet. Claude is faked (fakeClaude) for Rebuild. The photo is given with "Use a
 * favourite", not attached through the upload input: Pest's browser server does not forward multipart files
 * (LaravelHttpServer: "@TODO files"), so uploads are covered in tests/Feature/Marketing/PostStudioTest.php. Clock
 * frozen at Mon 12 Oct 2026 11:00, as in that file.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    Storage::fake('local');
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create();
    $this->drafts = collect(['First draft line.', 'Second draft line.', 'Third draft line.'])
        ->map(fn (string $c) => MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['caption' => $c]));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Script: the id of the draft panel that is showing. */
const SHOWN_DRAFT = "[...document.querySelectorAll('[data-draft]')].find(e => e.offsetParent !== null)?.dataset.draft";

it('flips the drafts with the arrows, the dots and the keyboard, and Escape closes the studio', function () {
    [$one, $two, $three] = $this->drafts->all();

    visit('/p/coins/marketing/campaigns/trueview-tuesday')->resize(1280, 900)
        ->click('[data-open-studio="2026-10-13"]')
        ->assertVisible('[data-studio] [role="dialog"]')
        ->assertScript(SHOWN_DRAFT, (string) $one->id)
        ->click('[data-draft="'.$one->id.'"] [aria-label="Next draft"]')
        ->assertScript(SHOWN_DRAFT, (string) $two->id)
        ->click('[aria-label="Draft 3"]:visible')
        ->assertScript(SHOWN_DRAFT, (string) $three->id)
        ->keys('[data-studio-close]', 'ArrowRight')
        ->assertScript(SHOWN_DRAFT, (string) $one->id)
        ->keys('[data-studio-close]', 'ArrowLeft')
        ->assertScript(SHOWN_DRAFT, (string) $three->id)
        ->keys('[data-studio-close]', 'Escape')
        ->assertMissing('[data-studio] [role="dialog"]')
        ->assertNoJavaScriptErrors();
});

it('rebuilds a draft as v2, compares it with v1 without a round trip, and undoes it', function () {
    fakeClaude(['caption' => 'Shorter: rainbow toning.', 'asset_id' => null]);
    $one = $this->drafts->first();

    $page = visit('/p/coins/marketing/campaigns/trueview-tuesday')->resize(1280, 900)
        ->click('[data-open-studio="2026-10-13"]')
        ->fill('#fb-'.$one->id, 'Make it shorter')
        ->click('[data-rebuild="'.$one->id.'"]')
        ->assertSeeIn('[data-version="'.$one->id.'"]', 'v2')
        ->assertValue('#cap-'.$one->id, 'Shorter: rainbow toning.')
        ->assertMissing('[data-compare="'.$one->id.'"]')
        ->click('[data-version="'.$one->id.'"] button:has-text("Compare")')
        ->assertVisible('[data-compare="'.$one->id.'"]')
        ->assertSeeIn('[data-compare="'.$one->id.'"]', 'First draft line.')
        ->click('[data-version="'.$one->id.'"] button:has-text("Undo")')
        ->assertMissing('[data-version="'.$one->id.'"]')
        ->assertValue('#cap-'.$one->id, 'First draft line.')
        ->assertNoJavaScriptErrors();

    expect($one->fresh()->version)->toBe(1);
});

it('Given a 375px viewport, then the studio is a full-screen sheet with no sideways scroll and Approve stays visible', function () {
    $one = $this->drafts->first();
    $fav = MarketingAsset::factory()->for($this->coins)->create(['path' => UploadedFile::fake()->image('morgan.jpg', 400, 400)->store('marketing/coins/photos', 'local')]);

    visit('/p/coins/marketing/campaigns/trueview-tuesday')->resize(375, 812)
        ->click('[data-open-studio="2026-10-13"]')
        ->assertVisible('[data-studio] [role="dialog"]')
        // Full screen: the dialog is the viewport's size, flush with its edges.
        ->assertScript("(() => { const r = document.querySelector('[data-studio] [role=dialog]').getBoundingClientRect(); return r.left === 0 && r.top === 0 && r.width === window.innerWidth && r.height === window.innerHeight; })()", true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript("document.querySelector('[data-studio] [role=dialog]').scrollWidth <= window.innerWidth", true)
        // Approve is held for the photo, and on screen without scrolling.
        ->assertAttribute('[data-approve="'.$one->id.'"]', 'disabled', '')
        ->assertScript("(() => { const r = document.querySelector('[data-approve=\"{$one->id}\"]').getBoundingClientRect(); return r.bottom <= window.innerHeight && r.top >= 0 && r.right <= window.innerWidth; })()", true)
        ->click('[data-use-favourite="'.$one->id.'"]')
        ->click('[data-draft="'.$one->id.'"] [data-favourite="'.$fav->id.'"]')
        ->assertMissing('[data-needs-photo="'.$one->id.'"]')
        ->assertEnabled('[data-approve="'.$one->id.'"]')
        ->assertScript("(() => { const r = document.querySelector('[data-approve=\"{$one->id}\"]').getBoundingClientRect(); return r.bottom <= window.innerHeight && r.top >= 0; })()", true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
