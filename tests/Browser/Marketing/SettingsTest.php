<?php

use App\Actions\Marketing\StoreMarketingAsset;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * MK-2 in a real browser: Marketing settings with the StackerMint brand seeded from the fixture kit. At 1280 px the
 * brand kit sits beside favourites, copy rules and Facebook; a favourite photo is served back and Delete goes through
 * the confirm modal. At 375 px everything is one column with no sideways scroll. The photo is stored by
 * StoreMarketingAsset, not attached through the input: Pest's browser server does not forward multipart files
 * (LaravelHttpServer: "@TODO files"), so uploads are covered in tests/Feature/Marketing/MarketingSettingsTest.php.
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => base_path('tests/Fixtures/marketing-kit')])->assertSuccessful();
    MarketingBrand::sole()->update(['logo_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2" fill="currentColor"><path d="M0 0h2v2z"/></svg>', 'logo_source' => 'mark.svg @ origin/main · 52e084e']);
});

it('shows the brand beside the favourites, serves a favourite and deletes it through the confirm modal at 1280px', function () {
    $file = base_path('tests/Fixtures/marketing-kit/posts/01-post-welcome.png');
    $asset = app(StoreMarketingAsset::class)->handle(Project::sole(), new UploadedFile($file, 'welcome.png', 'image/png', test: true));

    $page = visit('/p/coins/marketing/settings')->resize(1280, 900)
        ->assertSeeIn('[data-brand-sample]', 'StackerMint')
        ->assertSeeIn('[data-logo-source]', 'mark.svg @ origin/main')
        ->assertScript('document.querySelector("[data-brand-logo] svg") !== null', true)
        // Two columns: the favourites start to the right of the brand kit.
        ->assertScript('document.querySelector("[data-favourites]").getBoundingClientRect().left > document.querySelector("[data-brand-kit]").getBoundingClientRect().right', true)
        ->assertSee('Real coin photos will come from the coins app later.');

    $page->assertScript("document.querySelector('[data-favourite=\"{$asset->id}\"] img').naturalWidth", 4)
        ->click("[data-delete-photo=\"{$asset->id}\"]")
        ->assertVisible('[data-delete-photo-modal]')
        ->click('[data-delete-photo-modal] [data-confirm-ok]')
        ->waitForText('Photo deleted')
        ->assertSee('No favourites yet.')
        ->assertNoJavaScriptErrors();
    expect(MarketingAsset::where('purpose', MarketingAsset::PURPOSE_PHOTO)->count())->toBe(0); // the seed's kit inspiration (MK-18) stays
});

it('Given a 375px viewport, then settings is one column with no sideways scroll', function () {
    visit('/p/coins/marketing/settings')->resize(375, 812)
        ->assertSeeIn('[data-brand-sample]', 'StackerMint')
        ->assertScript('Math.round(document.querySelector("[data-favourites]").getBoundingClientRect().left) === Math.round(document.querySelector("[data-brand-kit]").getBoundingClientRect().left)', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
