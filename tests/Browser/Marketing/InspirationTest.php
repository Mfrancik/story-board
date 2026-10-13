<?php

use App\Models\MarketingAsset;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * MK-18 in a real browser: the Inspiration wall, filled by the seed from the fixture kit (its 2 post images, notes
 * from their captions) plus a few more read images. At 1280 px the wall has several columns, the heart toggles and
 * delete goes through the confirm modal; at 375 px it is one or two columns with no sideways scroll. Images are not
 * dropped through the input: Pest's browser server does not forward multipart files (see SettingsTest), so uploads
 * are covered in tests/Feature/Marketing/InspirationTest.php.
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => base_path('tests/Fixtures/marketing-kit')])->assertSuccessful();
    $png = (string) file_get_contents(base_path('tests/Fixtures/marketing-kit/posts/10-post-tv-tuesday.png'));
    foreach (range(1, 4) as $i) {
        $asset = MarketingAsset::factory()->for($coins)->inspiration(['headline' => "Read ad {$i}", 'style' => ['one bold number'], 'topic' => 'sets'])
            ->create(['mime' => 'image/png']);
        Storage::disk('local')->put($asset->path, $png);
    }
    $this->reading = MarketingAsset::factory()->for($coins)->inspiration(['status' => MarketingAsset::READ_READING, 'headline' => null])->create();
});

it('hearts an image and deletes another through the confirm modal at 1280px', function () {
    $target = MarketingAsset::where('ai_notes->headline', 'Read ad 1')->sole();
    $doomed = MarketingAsset::where('ai_notes->headline', 'Read ad 2')->sole();

    visit('/p/coins/marketing/inspiration')->resize(1280, 900)
        ->assertSee('Layout matching arrives with the designer later.')
        ->assertSeeIn('[data-inspiration="'.$this->reading->id.'"]', 'Reading…')
        ->assertSee('From the kit')
        ->assertScript('Number(getComputedStyle(document.querySelector("[data-inspiration-wall]")).columnCount) >= 3', true)
        ->assertAttribute('[data-favourite-inspiration="'.$target->id.'"]', 'aria-pressed', 'false')
        ->click('[data-favourite-inspiration="'.$target->id.'"]')
        ->assertAttribute('[data-favourite-inspiration="'.$target->id.'"]', 'aria-pressed', 'true')
        ->click('[data-delete-inspiration="'.$doomed->id.'"]')
        ->assertVisible('[data-delete-inspiration-modal]')
        ->click('[data-delete-inspiration-modal] [data-confirm-ok]')
        ->waitForText('Removed from Inspiration')
        ->assertMissing('[data-inspiration="'.$doomed->id.'"]')
        ->assertNoJavaScriptErrors();

    expect($target->fresh()->is_favourite)->toBeTrue()->and($doomed->fresh())->toBeNull();
});

it('Given a 375px viewport, then the wall is one or two columns with no sideways scroll', function () {
    visit('/p/coins/marketing/inspiration')->resize(375, 812)
        ->assertVisible('[data-inspiration-wall]')
        ->assertScript('[1, 2].includes(Number(getComputedStyle(document.querySelector("[data-inspiration-wall]")).columnCount))', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
