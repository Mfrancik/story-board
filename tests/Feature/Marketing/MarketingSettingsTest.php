<?php

use App\Livewire\Marketing\Settings;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * MK-2 acceptance criteria for `/p/{project}/marketing/settings`: the brand (one form, create = edit), the logo read
 * from the project's repo and sanitised, favourite photos (upload, serve, delete) and copy rules. The seed command's
 * criteria are in SeedMarketingKitTest. Files go to a faked `local` disk.
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->other = Project::factory()->create(['name' => 'other', ...$fresh]);
});

/**
 * A favourite photo of `$project` stored on the faked disk, as an upload would leave it.
 */
function settingsPhoto(Project $project, string $name = 'morgan.jpg'): MarketingAsset
{
    $path = "marketing/{$project->name}/photos/".Str::random(8).'.jpg';
    Storage::disk('local')->put($path, UploadedFile::fake()->image($name, 8, 8)->getContent());

    return MarketingAsset::factory()->for($project)->create(['path' => $path, 'original_name' => $name, 'mime' => 'image/jpeg']);
}

/**
 * Commit `$files` to the fixture's origin and fetch them into the registered clone, so origin/main has them.
 *
 * @param  array<string, string>  $files
 */
function pushLogo(GitFixture $repo, array $files): void
{
    foreach ($files as $path => $contents) {
        $repo->write($path, $contents);
    }
    $repo->commitAndPush('feat: logo');
    $repo->git($repo->project, 'fetch', '--quiet');
}

it('shows defaults for a project with no brand and creates the brand row on save', function () {
    $this->get('/p/coins/marketing/settings')->assertOk()
        ->assertSee('data-brand-form', false)
        ->assertSee('data-brand-defaults', false)
        ->assertSeeInOrder(['data-swatch="accent"', MarketingBrand::DEFAULTS['accent']], false)
        ->assertSee('Real coin photos will come from the coins app later.');
    expect(MarketingBrand::count())->toBe(0);

    Log::spy();
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->assertSet('accent', MarketingBrand::DEFAULTS['accent'])
        ->call('saveBrand')
        ->assertHasNoErrors();

    $brand = MarketingBrand::sole();
    expect($brand->project_id)->toBe($this->coins->id)
        ->and($brand->values['accent'])->toBe(MarketingBrand::DEFAULTS['accent'])
        ->and($brand->values['name'])->toBe('coins');
    Log::shouldHaveReceived('info')->with('marketing.brand.saved', Mockery::on(fn ($c) => $c['project'] === 'coins' && $c['created'] === true));
});

it('edits the brand in the same form and keeps one row per project', function () {
    MarketingBrand::factory()->for($this->coins)->create();

    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('name', 'StackerMint')->set('accent', '#b79f82')->set('radius', 5)->set('shadow', 'halo')
        ->call('saveBrand')->assertHasNoErrors();

    $brand = MarketingBrand::sole();
    expect($brand->values['name'])->toBe('StackerMint')
        ->and($brand->values['accent'])->toBe('#B79F82')
        ->and($brand->values['radius'])->toBe(5)
        ->and($brand->values['shadow'])->toBe('halo');
});

it('rejects an invalid accent hex and saves nothing', function () {
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('accent', 'tan')
        ->call('saveBrand')
        ->assertHasErrors(['accent']);
    expect(MarketingBrand::count())->toBe(0);

    $brand = MarketingBrand::factory()->for($this->coins)->create();
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('name', 'Changed')->set('accent', '#12345')
        ->call('saveBrand')
        ->assertHasErrors(['accent']);
    expect($brand->fresh()->values)->toEqual($brand->values);
});

it('rejects a font name that could break out of the preview\'s style, and saves nothing', function () {
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('font', 'Inter; background: url(https://evil.test)')
        ->call('saveBrand')
        ->assertHasErrors(['font']);
    expect(MarketingBrand::count())->toBe(0);
});

it('saves the voice and the filler and banned lists, one per line', function () {
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('voice', 'Short and human.')
        ->set('filler', "tell us below\n\n  we read every one  \n")
        ->set('banned', 'cert lookup')
        ->call('saveCopyRules')->assertHasNoErrors();

    $brand = MarketingBrand::sole();
    expect($brand->voice)->toBe('Short and human.')
        ->and($brand->copy_rules)->toEqual(['filler' => ['tell us below', 'we read every one'], 'banned' => ['cert lookup']]);
});

describe('logo from the repo', function () {
    beforeEach(function () {
        $this->repo = new GitFixture;
        $this->coins->update(['path' => $this->repo->project]);
    });

    afterEach(function () {
        $this->repo->destroy();
    });

    it('stores the sanitised SVG with an xmlns and shows the source line path @ ref', function () {
        // The coins mark is a Blade component: a comment, Blade echoes, a bare attribute, and no xmlns.
        pushLogo($this->repo, ['resources/views/components/app-mark.blade.php' => "{{-- the mark --}}\n"
            .'<svg aria-hidden="true" data-brand-mark viewBox="0 0 23 23" fill="currentColor" {{ $attributes->class([\'shrink-0\']) }}><path d="M0 0 L 10 10 Z"/></svg>']);

        Livewire::test(Settings::class, ['project' => $this->coins])
            ->set('logoPath', 'resources/views/components/app-mark.blade.php')
            ->set('logoRef', 'origin/main')
            ->call('readLogo')
            ->assertHasNoErrors()
            ->assertSee('resources/views/components/app-mark.blade.php @ origin/main');

        $brand = MarketingBrand::sole();
        expect($brand->logo_svg)->toStartWith('<svg')
            ->toContain('xmlns="http://www.w3.org/2000/svg"')
            ->toContain('d="M0 0 L 10 10 Z"')
            ->not->toContain('{{')
            ->and($brand->logo_source)->toContain('resources/views/components/app-mark.blade.php @ origin/main');
        expect(simplexml_load_string($brand->logo_svg))->not->toBeFalse();
    });

    it('strips a script and event handlers from the logo and logs marketing.brand.logo_sanitised', function () {
        pushLogo($this->repo, ['brand/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)">'
            .'<script>alert(2)</script><a href="https://evil.test"><circle r="4"/></a><use href="#c"/>'
            .'<foreignObject><div>x</div></foreignObject></svg>']);

        Log::spy();
        Livewire::test(Settings::class, ['project' => $this->coins])
            ->set('logoPath', 'brand/logo.svg')
            ->call('readLogo')
            ->assertHasNoErrors();

        $svg = MarketingBrand::sole()->logo_svg;
        expect($svg)->not->toContain('script')->not->toContain('onload')->not->toContain('evil.test')
            ->not->toContain('foreignObject')
            ->toContain('<circle r="4"/>')->toContain('href="#c"');
        Log::shouldHaveReceived('info')->with('marketing.brand.logo_sanitised', Mockery::on(fn ($c) => $c['project'] === 'coins' && $c['removed'] >= 4));
    });

    it('refuses a logo path missing at the ref, and a file with no SVG, with a message and a log line', function () {
        pushLogo($this->repo, ['brand/readme.txt' => 'not a logo']);

        Log::spy();
        Livewire::test(Settings::class, ['project' => $this->coins])
            ->set('logoPath', 'brand/missing.svg')->call('readLogo')->assertHasErrors(['logoPath'])
            ->set('logoPath', 'brand/readme.txt')->call('readLogo')->assertHasErrors(['logoPath'])
            ->set('logoPath', 'brand/readme.txt')->set('logoRef', '--output=/tmp/x')->call('readLogo')->assertHasErrors(['logoRef']);

        expect(MarketingBrand::count())->toBe(0);
        foreach (['not_found', 'not_svg', 'bad_ref'] as $reason) {
            Log::shouldHaveReceived('warning')->with('marketing.brand.logo_refused', Mockery::on(fn ($c) => $c['project'] === 'coins' && $c['reason'] === $reason))->once();
        }
        // The ref is refused before git sees it, so GitReader's own refusal never fires.
        Log::shouldNotHaveReceived('warning', ['board.git_refused', Mockery::any()]);
    });
});

it('refuses a logo path outside the repo before running git', function () {
    Log::spy();
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('logoPath', '../coins-seller/logo.svg')->call('readLogo')->assertHasErrors(['logoPath'])
        ->set('logoPath', '/etc/passwd')->call('readLogo')->assertHasErrors(['logoPath']);

    Log::shouldHaveReceived('warning')->with('marketing.brand.logo_refused', Mockery::on(fn ($c) => $c['reason'] === 'bad_path'))->twice();
});

it('stores an uploaded SVG logo sanitised, and rejects a logo that is not an SVG', function () {
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('logoFile', UploadedFile::fake()->createWithContent('mark.svg', '<svg viewBox="0 0 2 2"><script>x()</script><path d="M0 0"/></svg>'))
        ->assertHasNoErrors()
        ->assertSee('Logo source: uploaded mark.svg');

    expect(MarketingBrand::sole()->logo_svg)->toBe('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><path d="M0 0"/></svg>');

    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('logoFile', UploadedFile::fake()->image('mark.png', 4, 4))
        ->assertHasErrors(['logoFile' => 'The logo must be an SVG file.']);
    expect(MarketingBrand::sole()->logo_source)->toBe('uploaded mark.svg');
});

it('stores three uploaded photos as favourites and renders them', function () {
    Log::spy();
    $component = Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('photos', [
            UploadedFile::fake()->image('morgan.jpg', 40, 30),
            UploadedFile::fake()->image('peace.png', 20, 20),
            UploadedFile::fake()->image('mercury.webp', 10, 10),
        ])
        ->assertHasNoErrors();

    $assets = MarketingAsset::orderBy('id')->get();
    expect($assets)->toHaveCount(3)
        ->and($assets->every(fn ($a) => $a->project_id === $this->coins->id && $a->purpose === MarketingAsset::PURPOSE_PHOTO && $a->is_favourite))->toBeTrue()
        ->and($assets[0]->original_name)->toBe('morgan.jpg')
        ->and([$assets[0]->width, $assets[0]->height])->toBe([40, 30])
        ->and($assets[0]->path)->toStartWith('marketing/coins/photos/');
    $assets->each(fn ($a) => Storage::disk('local')->assertExists($a->path));
    foreach ($assets as $a) {
        $component->assertSee(route('marketing.asset', ['project' => 'coins', 'asset' => $a->id]), false);
    }
    Log::shouldHaveReceived('info')->with('marketing.asset.stored', Mockery::any())->times(3);
});

it('stores nothing and says nothing when an upload brings back no files', function () {
    Log::spy();
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->set('photos', [])
        ->assertHasNoErrors()
        ->assertDontSee('Added 0');

    expect(MarketingAsset::count())->toBe(0);
    Log::shouldHaveReceived('info')->with('marketing.asset.upload_empty', ['project' => 'coins']);
});

it('rejects a 20 MB photo and a PDF with a message', function () {
    Livewire::test(Settings::class, ['project' => $this->coins])
        // Over Livewire's own 12 MB upload limit: its "12288 kilobytes" message is replaced with this page's.
        ->set('photos', [UploadedFile::fake()->image('huge.jpg', 10, 10)->size(20 * 1024)])
        ->assertHasErrors(['photos.0' => 'Photos can be 10 MB at most.'])
        // Under Livewire's limit, over this page's.
        ->set('photos', [UploadedFile::fake()->image('big.jpg', 10, 10)->size(11 * 1024)])
        ->assertHasErrors(['photos.0' => 'Photos can be 10 MB at most.'])
        ->assertSet('photos', [])
        ->set('photos', [UploadedFile::fake()->create('price-list.pdf', 50, 'application/pdf')])
        ->assertHasErrors(['photos.0' => 'Photos must be JPG, PNG or WebP images.'])
        ->assertSeeHtml('data-photo-errors');

    expect(MarketingAsset::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('marketing'))->toBe([]);
});

it('deletes a favourite photo, its file, and unlinks posts that used it', function () {
    $asset = settingsPhoto($this->coins);
    $post = MarketingPost::factory()->for($this->coins)->create(['asset_id' => $asset->id]);

    Log::spy();
    Livewire::test(Settings::class, ['project' => $this->coins])
        ->assertSee('data-delete-photo-modal', false)
        ->call('deletePhoto', $asset->id);

    expect(MarketingAsset::count())->toBe(0)
        ->and($post->fresh()->asset_id)->toBeNull();
    Storage::disk('local')->assertMissing($asset->path);
    Log::shouldHaveReceived('info')->with('marketing.asset.deleted', Mockery::on(fn ($c) => $c['asset_id'] === $asset->id));
});

it('refuses to delete another project\'s photo and logs why', function () {
    $asset = settingsPhoto($this->other);

    Log::spy();
    Livewire::test(Settings::class, ['project' => $this->coins])->call('deletePhoto', $asset->id);

    expect($asset->fresh())->not->toBeNull();
    Log::shouldHaveReceived('warning')->with('marketing.asset.refused', Mockery::on(fn ($c) => $c['asset_id'] === $asset->id && $c['reason'] === 'other_project'));
});

it('serves an asset under its own project', function () {
    $asset = settingsPhoto($this->coins);

    $this->get(route('marketing.asset', ['project' => 'coins', 'asset' => $asset->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('answers 404 for project A\'s asset under project B and logs marketing.asset.refused', function () {
    $asset = settingsPhoto($this->coins);

    Log::spy();
    $this->get(route('marketing.asset', ['project' => 'other', 'asset' => $asset->id]))->assertNotFound();
    Log::shouldHaveReceived('warning')->with('marketing.asset.refused', Mockery::on(fn ($c) => $c['project'] === 'other' && $c['asset_id'] === $asset->id && $c['reason'] === 'other_project'));
});

it('answers 404 for an asset whose file is gone, and for an unknown id, and logs why', function () {
    $asset = settingsPhoto($this->coins);
    Storage::disk('local')->delete($asset->path);

    Log::spy();
    $this->get(route('marketing.asset', ['project' => 'coins', 'asset' => $asset->id]))->assertNotFound();
    $this->get('/p/coins/marketing/assets/999999')->assertNotFound();
    $this->get('/p/coins/marketing/assets/abc')->assertNotFound();
    Log::shouldHaveReceived('warning')->with('marketing.asset.refused', Mockery::on(fn ($c) => $c['reason'] === 'missing_file'));
    Log::shouldHaveReceived('warning')->with('marketing.asset.refused', Mockery::on(fn ($c) => $c['reason'] === 'unknown'));
});

it('shows the Facebook card disabled as phase 2 and lists Settings in the marketing sub-nav', function () {
    $html = $this->get('/p/coins/marketing/settings')->assertOk()->getContent();

    expect($html)->toMatch('/data-facebook-card[^>]*>.*Phase 2.*<button[^>]*disabled/s')
        ->toMatch('/data-marketing-page="settings"[^>]*aria-current="page"/');
});

it('refuses settings for a disabled project', function () {
    $this->other->update(['is_enabled' => false]);

    $this->get('/p/other/marketing/settings')->assertNotFound();
});
