<?php

use App\Actions\Marketing\BuildAdHtml;
use App\Actions\Marketing\QueuePostRender;
use App\Actions\Marketing\SeedMarketingKit;
use App\Actions\Marketing\StoreMarketingAsset;
use App\Exceptions\MarketingRenderException;
use App\Jobs\RefreshProjectJob;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\AdRenderer;
use App\Services\Marketing\AdRendererClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * MK-5 with a real browser: the one real render (Playwright's Chromium through bin/render-ad.mjs, unfaked), the
 * shrink-to-fit measured by that same browser on every template, and the studio's rendered preview at 375 px (that
 * one with the fake renderer, as everywhere else). Clock frozen at Mon 12 Oct 2026 11:00 UTC.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    Storage::fake('local');
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    MarketingBrand::factory()->for($this->coins)->create(['values' => SeedMarketingKit::BRAND,
        'logo_svg' => file_get_contents(base_path('docs/mockups/MK-kit/swirl.svg')) ?: null]);
    $this->campaign = MarketingCampaign::factory()->for($this->coins)->named('Set spotlight')->on([2], '19:00')->create();
    $this->photo = app(StoreMarketingAsset::class)->handle($this->coins, UploadedFile::fake()->image('morgan.jpg', 800, 800));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('Given the real renderer (one browser test), when a photo+headline ad renders, then a valid PNG of exactly 1080×1080 is written', function () {
    app()->instance(AdRendererClient::class, new AdRenderer);
    $post = MarketingPost::factory()->forCampaign($this->campaign, '2026-10-13 19:00')->create([
        'caption' => "Nine down. The 1889-CC is the hard one.\nCarson City Morgans.", 'asset_id' => $this->photo->id,
    ]);

    app(QueuePostRender::class)->handle($post);

    $post->refresh();
    expect($post->renderState())->toBe(MarketingPost::RENDER_RENDERED)
        ->and($post->adSpec()['layout'])->toBe('photo_headline');
    $square = MarketingAsset::findOrFail($post->renderId('square'));
    $bytes = Storage::disk('local')->get($square->path);
    expect(substr((string) $bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(array_slice(getimagesizefromstring((string) $bytes) ?: [], 0, 3))->toBe([1080, 1080, IMAGETYPE_PNG])
        ->and(array_slice(getimagesize(Storage::disk('local')->path(MarketingAsset::findOrFail($post->renderId('portrait'))->path)) ?: [], 0, 2))->toBe([1080, 1350]);
});

it('Given a headline too long for a layout, then the text shrinks to fit within the layout\'s bounds (no overflow)', function () {
    $post = MarketingPost::factory()->forCampaign($this->campaign, '2026-10-13 19:00')->create(['asset_id' => $this->photo->id]);
    // The worst cases per layout, each at or past its MAX_HEADLINE cap: long text, long words, all capitals (the widest
    // glyphs), and short multi-word headlines that wrap at a large size — "61 down, 36 to go." is the stat that broke.
    $worst = [
        'photo_headline' => ['That rainbow came from sitting in an old paper album for decades. The sulfur in the paper did it.',
            'Professionally authenticated, extraordinarily scarce, uncirculated Carson City Morgans, photographed.',
            'WHAT MAKES A MORGAN DOLLAR WORTH MORE THAN ITS MELT VALUE?', 'Nine down, four to go.', 'Toning'],
        'text_card' => ['A type set is one of each design, not every date. It is the best way to own every chapter of it.',
            'Professionally authenticated, extraordinarily scarce, uncirculated Carson City Morgans, photographed.',
            'WHAT MAKES A MORGAN DOLLAR WORTH MORE THAN ITS MELT VALUE?', 'Nine down, four to go.', 'Toning'],
        'question' => ['Albums, slabs, or a safe full of tubes? How do you store yours, and what would you change about it?',
            'Professionally authenticated, extraordinarily scarce, uncirculated Carson City Morgans, photographed?',
            'WHAT MAKES A MORGAN DOLLAR WORTH MORE THAN ITS MELT VALUE?', 'Slab or raw?', 'Why?'],
        'spotlight' => ['1889-CC Morgan dollar, the hard one in every set', 'Extraordinarily scarce, professionally graded dime',
            'THE 1893-S MORGAN DOLLAR, KEY OF THE WHOLE SET', 'Uncirculated authentication', '1893-S'],
        'stat' => ['61 down, 36 to go.', '$48,210,550.75', '12345678901234', '3 of 13 coins', 'Only 4 left now!', '100% COMPLETE!', 'Mmmm, wow 99%'],
    ];
    $pages = [];
    foreach (MarketingCampaign::AD_STYLES as $style) {
        $this->campaign->update(['ad_style' => $style]);
        $post->load('campaign');
        foreach ($worst as $layout => $headlines) {
            foreach ($headlines as $i => $headline) {
                $spec = ['layout' => $layout, 'headline' => $headline, 'subline' => 'Carson City Morgans. Thirteen coins, 1878 to 1893.',
                    'cta' => 'See the set', 'asset_id' => $this->photo->id, 'crop' => 'center', 'badge' => 'Key date'];
                foreach (MarketingPost::RENDER_SIZES as $size => [$width, $height]) {
                    $pages["{$style}-{$layout}-{$i}-{$size}"] = ['html' => app(BuildAdHtml::class)->handle($post, $spec, $size), 'width' => $width, 'height' => $height];
                }
            }
        }
    }

    $rendered = (new AdRenderer)->render($pages);

    // bin/render-ad.mjs flags a page when a headline, subline or cta overflows its own box or is cut by a clipping
    // ancestor (the ad's edge included).
    $overflowing = array_keys(array_filter($rendered, fn (array $page) => $page['overflow']));
    expect($rendered)->toHaveCount(count($pages))
        ->and($overflowing)->toBe([]);
});

it('Given a 375px viewport, then the studio\'s rendered preview fits with no sideways scroll', function () {
    $post = MarketingPost::factory()->forCampaign($this->campaign, '2026-10-13 19:00')->create(['caption' => 'Toning is chemistry, not damage.', 'is_text_only' => true]);
    app(QueuePostRender::class)->handle($post);
    expect($post->fresh()->renderState())->toBe(MarketingPost::RENDER_RENDERED);

    $fits = "(() => { const r = [...document.querySelectorAll('[data-ad-render] img')].find(i => i.offsetParent !== null).getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()";
    visit('/p/coins/marketing/campaigns/set-spotlight')->resize(375, 812)
        ->click('[data-open-studio="2026-10-13"]')
        ->assertVisible('[data-ad-render="'.$post->id.'"]')
        ->assertScript($fits, true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript("document.querySelector('[data-studio] [role=dialog]').scrollWidth <= window.innerWidth", true)
        ->click('[data-ratio="portrait"]')
        ->assertScript($fits, true)
        ->assertScript("document.querySelector('[data-studio] [role=dialog]').scrollWidth <= window.innerWidth", true)
        ->assertVisible('[data-download-ad="portrait"]')
        ->assertNoJavaScriptErrors();
});

it('turns a renderer run that exits with an error into a process_failed render failure, with the script\'s reason', function () {
    try {
        (new AdRenderer)->render(['square' => ['html' => '<p>x</p>', 'width' => 0, 'height' => 1080]]);
        $this->fail('The render should have failed.');
    } catch (MarketingRenderException $e) {
        expect($e->reason)->toBe('process_failed')->and($e->getMessage())->toContain('bad size 0x1080');
    }
});
