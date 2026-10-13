<?php

use App\Actions\Marketing\DraftOccurrence;
use App\Actions\Marketing\QueueAssetRead;
use App\Actions\Marketing\SeedMarketingKit;
use App\Jobs\ReadInspiration;
use App\Livewire\Marketing\Inspiration;
use App\Livewire\Marketing\Library;
use App\Livewire\Marketing\PostStudio;
use App\Livewire\Marketing\Settings;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * MK-18 acceptance criteria: the Inspiration wall (`/p/{project}/marketing/inspiration`), the one-time vision read
 * of each inspiration image and favourite photo (ReadInspiration, Claude faked over Http::fake), the examples Fill
 * adds to its prompt (PickInspirationExamples) and their "Inspired by" row in the studio, the kit seed's inspiration,
 * and Save to Inspiration from the studio and the library. The clock is frozen at Mon 12 Oct 2026 11:00 UTC; the
 * queue is faked, so read jobs are run by hand with runRead().
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake();
    Storage::fake('local');
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->other = Project::factory()->create(['name' => 'other', ...$fresh]);
    MarketingBrand::factory()->for($this->coins)->create(['values' => SeedMarketingKit::BRAND, 'copy_rules' => SeedMarketingKit::COPY_RULES]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create([
        'brief' => 'Show off the toning.',
        'topics' => ['ideas' => [], 'own' => [['text' => 'Album toning', 'detail' => 'rainbow colours from old albums']]],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The Inspiration page component for coins.
 */
function inspirationPage(): mixed
{
    return Livewire::test(Inspiration::class, ['project' => test()->coins]);
}

/**
 * Run the read job for `$asset` as the worker would, releasing its unique lock afterwards as the worker does (the
 * faked queue took the lock on dispatch and never runs the job).
 */
function runRead(MarketingAsset $asset): void
{
    $job = new ReadInspiration($asset->id);
    app()->call([$job, 'handle']);
    (new UniqueLock(Cache::driver()))->release($job);
}

/**
 * An inspiration image of `$project` with its file on the faked disk and `$notes` as what the AI read.
 *
 * @param  array<string, mixed>  $notes
 */
function inspirationOf(Project $project, array $notes = [], bool $favourite = false): MarketingAsset
{
    $asset = MarketingAsset::factory()->for($project)->inspiration($notes)->create(['is_favourite' => $favourite]);
    Storage::disk('local')->put($asset->path, UploadedFile::fake()->image('ad.jpg', 8, 8)->getContent());

    return $asset;
}

/**
 * The user part of the last prompt sent to (fake) Claude.
 */
function lastUserPrompt(): string
{
    $content = Http::recorded()->last()[0]['messages'][0]['content'];

    return is_string($content) ? $content : (string) json_encode($content);
}

it('Given I drop 12 images, then 12 inspiration assets exist and a read job is queued for each', function () {
    Log::spy();
    $files = collect(range(1, 12))->map(fn (int $i) => UploadedFile::fake()->image("ad-{$i}.png", 20, 20))->all();

    inspirationPage()->set('images', $files)->assertHasNoErrors()->assertSet('images', []);

    $assets = MarketingAsset::orderBy('id')->get();
    expect($assets)->toHaveCount(12)
        ->and($assets->every(fn (MarketingAsset $a) => $a->purpose === MarketingAsset::PURPOSE_INSPIRATION && ! $a->is_favourite
            && $a->project_id === $this->coins->id && $a->readState() === MarketingAsset::READ_READING))->toBeTrue()
        ->and($assets[0]->path)->toStartWith('marketing/coins/inspirations/');
    Queue::assertPushed(ReadInspiration::class, 12);
    foreach ($assets as $asset) {
        Queue::assertPushed(ReadInspiration::class, fn (ReadInspiration $job) => $job->assetId === $asset->id);
    }
    Log::shouldHaveReceived('info')->with('marketing.inspiration.stored', Mockery::any())->times(12);
});

it('Given a faked Claude read, then the image shows its headline and style notes', function () {
    Log::spy();
    fakeClaude(['headline' => 'TrueView Tuesday', 'style' => ['dark full-bleed coin', 'big headline'], 'topic' => 'toned coins']);
    inspirationPage()->set('images', [UploadedFile::fake()->image('tv.png', 1600, 1200)])->assertSee('Reading…');
    $asset = MarketingAsset::sole();

    runRead($asset);

    expect($asset->fresh()->ai_notes)->toMatchArray([
        'status' => MarketingAsset::READ_DONE, 'headline' => 'TrueView Tuesday', 'style' => ['dark full-bleed coin', 'big headline'], 'topic' => 'toned coins',
    ]);
    // One vision read of a downscaled JPEG: the long side is cut to the read limit, never the original 1600 px.
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        $image = $r['messages'][0]['content'][0];
        $size = getimagesizefromstring(base64_decode($image['source']['data']));

        return $image['type'] === 'image' && $image['source']['media_type'] === 'image/jpeg' && $size[0] === 1024 && $size[1] === 768;
    });
    inspirationPage()->assertSee('AI read it')->assertSee('TrueView Tuesday')->assertSee('dark full-bleed coin')->assertDontSee('Reading…');
    Log::shouldHaveReceived('info')->with('marketing.inspiration.read_succeeded', Mockery::on(fn (array $c) => $c['asset_id'] === $asset->id
        && $c['input_tokens'] === 1000 && $c['output_tokens'] === 200 && $c['cost_cents'] === 0.5 && $c['model'] === 'claude-sonnet-5-5'))->once();
});

it('Given Claude fails, then the tile shows Retry and `marketing.inspiration.read_failed` is logged; Retry re-queues', function () {
    Log::spy();
    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);
    inspirationPage()->set('images', [UploadedFile::fake()->image('ad.png', 20, 20)]);
    $asset = MarketingAsset::sole();

    runRead($asset);

    expect($asset->fresh()->readState())->toBe(MarketingAsset::READ_FAILED);
    Log::shouldHaveReceived('error')->with('marketing.inspiration.read_failed', Mockery::on(fn (array $c) => $c['asset_id'] === $asset->id
        && $c['reason'] === 'http_error' && $c['status'] === 529))->once();

    inspirationPage()->assertSee('Couldn’t read')->assertSeeHtml('data-retry-read="'.$asset->id.'"')
        ->call('retry', $asset->id);

    Queue::assertPushed(ReadInspiration::class, 2);
    expect($asset->fresh()->readState())->toBe(MarketingAsset::READ_READING);
});

it('fails a read whose reply has no headline as invalid_reply, and a read with no API key as not_configured', function () {
    Log::spy();
    fakeClaude(['style' => ['big coin']]);
    $asset = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);
    runRead($asset);
    expect($asset->fresh()->readState())->toBe(MarketingAsset::READ_FAILED);
    Log::shouldHaveReceived('error')->with('marketing.inspiration.read_failed', Mockery::on(fn (array $c) => $c['reason'] === 'invalid_reply'))->once();

    config(['services.anthropic.key' => null]);
    $second = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);
    runRead($second);
    expect($second->fresh()->readState())->toBe(MarketingAsset::READ_FAILED);
    Log::shouldHaveReceived('error')->with('marketing.inspiration.read_failed', Mockery::on(fn (array $c) => $c['reason'] === 'not_configured'))->once();
});

it('fails a read whose file is gone, and skips a job whose asset was deleted', function () {
    Log::spy();
    fakeClaude(['headline' => 'x', 'style' => [], 'topic' => 'y']);
    $asset = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);
    Storage::disk('local')->delete($asset->path);
    runRead($asset);
    expect($asset->fresh()->readState())->toBe(MarketingAsset::READ_FAILED);
    Log::shouldHaveReceived('error')->with('marketing.inspiration.read_failed', Mockery::on(fn (array $c) => $c['reason'] === 'missing_file'))->once();

    $gone = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);
    $id = $gone->id;
    $gone->delete();
    app()->call([new ReadInspiration($id), 'handle']);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('marketing.asset.read_skipped', Mockery::on(fn (array $c) => $c['asset_id'] === $id && $c['reason'] === 'gone'))->once();
});

it('queues nothing more for an image that is already being read', function () {
    Log::spy();
    $asset = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);

    expect(app(QueueAssetRead::class)->handle($asset))->toBeFalse();

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->with('marketing.asset.read_skipped', Mockery::on(fn (array $c) => $c['asset_id'] === $asset->id && $c['reason'] === 'already_reading'))->once();
});

it('treats a read stuck for more than 10 minutes as failed, so it can be retried', function () {
    $asset = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);
    expect($asset->readState())->toBe(MarketingAsset::READ_READING);

    Carbon::setTestNow(now()->addMinutes(11));

    expect($asset->fresh()->readState())->toBe(MarketingAsset::READ_FAILED)
        ->and(app(QueueAssetRead::class)->handle($asset->fresh()))->toBeTrue();
    Queue::assertPushed(ReadInspiration::class, 1);
});

it('Given 3 favourites and 10 others, when a draft\'s prompt is built, then it includes the 3 favourites plus at most 2 closest matches, and the draft records their ids', function () {
    fakeClaude();
    $favourites = collect(range(1, 3))->map(fn (int $i) => inspirationOf($this->coins, ['headline' => "Favourite ad {$i}", 'style' => ['bold number'], 'topic' => 'gold bullion'], favourite: true));
    // Closest to TrueView Tuesday's brief and topic ("Show off the toning", "Album toning: rainbow colours from old albums").
    $closest = collect([
        inspirationOf($this->coins, ['headline' => 'Rainbow album toning on a Morgan', 'style' => ['dark full-bleed coin'], 'topic' => 'album toning colours']),
        inspirationOf($this->coins, ['headline' => 'Toning is chemistry', 'style' => ['rainbow colours'], 'topic' => 'album toning']),
    ]);
    $weaker = inspirationOf($this->coins, ['headline' => 'Show us your toner', 'style' => [], 'topic' => 'community']);
    $unrelated = collect(range(1, 7))->map(fn (int $i) => inspirationOf($this->coins, ['headline' => "Stack day {$i}", 'style' => ['grid'], 'topic' => 'silver stacking']));

    $posts = app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));

    $prompt = lastUserPrompt();
    $favourites->each(fn (MarketingAsset $a) => expect($prompt)->toContain($a->ai_notes['headline']));
    $closest->each(fn (MarketingAsset $a) => expect($prompt)->toContain($a->ai_notes['headline']));
    expect($prompt)->not->toContain('Show us your toner')->not->toContain('Stack day');
    $expected = [...$favourites->pluck('id')->all(), ...$closest->pluck('id')->all()];
    $posts->each(fn (MarketingPost $p) => expect($p->generation_meta['inspiration_ids'])->toEqualCanonicalizing($expected));
    expect($weaker->id)->not->toBeIn($expected)->and($unrelated->pluck('id')->intersect($expected))->toBeEmpty();
});

it('adds no inspiration to the prompt from another project, an unread image or a failed read', function () {
    fakeClaude();
    inspirationOf($this->other, ['headline' => 'Their album toning ad', 'style' => [], 'topic' => 'album toning'], favourite: true);
    inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING, 'headline' => null], favourite: true);
    inspirationOf($this->coins, ['status' => MarketingAsset::READ_FAILED, 'headline' => null]);

    $posts = app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));

    expect(lastUserPrompt())->not->toContain('Their album toning ad')->not->toContain('Ads the owner likes')
        ->and($posts->first()->generation_meta['inspiration_ids'])->toBe([]);
});

it('Given a draft with recorded inspiration, then the studio shows its "Inspired by" thumbnails', function () {
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    $image = inspirationOf($this->coins, ['headline' => 'Rainbow album toning']);
    $card = MarketingAsset::factory()->for($this->coins)->inspiration(['headline' => 'From a text post', 'source' => 'post'])
        ->create(['path' => '', 'mime' => MarketingAsset::TEXT_CARD_MIME, 'is_favourite' => true]);
    $inspired = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['generation_meta' => ['inspiration_ids' => [$image->id, $card->id]]]);
    $plain = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['generation_meta' => ['model' => 'x']]);

    $html = Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-27')->html();

    expect(htmlQuery($html, '[data-inspired-by="'.$inspired->id.'"]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-inspired-by="'.$inspired->id.'"] [data-inspired-asset]'))->toHaveCount(2)
        ->and(htmlQuery($html, '[data-inspired-by="'.$inspired->id.'"] img[src="'.route('marketing.asset', ['coins', $image->id]).'"]'))->toHaveCount(1)
        ->and(htmlQuery($html, '[data-inspired-by="'.$plain->id.'"]'))->toHaveCount(0)
        ->and($html)->toContain('Inspired by');
});

it('Given a PDF or a 20 MB file, then it is rejected', function () {
    inspirationPage()
        ->set('images', [UploadedFile::fake()->create('rate-card.pdf', 50, 'application/pdf')])
        ->assertHasErrors(['images.0' => 'Ads must be JPG, PNG or WebP images.'])
        ->assertSet('images', [])
        // Over Livewire's own 12 MB upload limit: its "12288 kilobytes" message is replaced with this page's.
        ->set('images', [UploadedFile::fake()->image('huge.jpg', 10, 10)->size(20 * 1024)])
        ->assertHasErrors(['images.0' => 'Ads can be 10 MB at most.'])
        // Under Livewire's limit, over this page's.
        ->set('images', [UploadedFile::fake()->image('big.jpg', 10, 10)->size(11 * 1024)])
        ->assertHasErrors(['images.0' => 'Ads can be 10 MB at most.'])
        ->assertSeeHtml('data-inspiration-errors');

    expect(MarketingAsset::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('marketing'))->toBe([]);
    Queue::assertNothingPushed();
});

it('Given the seed runs twice, then exactly 18 kit inspiration assets exist', function () {
    Log::spy();
    // A kit like the real one: the fixture's captions file, 15 posts and 3 covers (the fixture itself has 2 posts).
    $kit = storage_path('framework/testing/kit-'.Str::random(8));
    File::ensureDirectoryExists($kit.'/posts');
    File::ensureDirectoryExists($kit.'/covers');
    File::ensureDirectoryExists($kit.'/profile-photos');
    File::copy(base_path('tests/Fixtures/marketing-kit/captions-and-bios.txt'), $kit.'/captions-and-bios.txt');
    $png = (string) file_get_contents(base_path('tests/Fixtures/marketing-kit/posts/01-post-welcome.png'));
    $names = ['01-post-welcome', '02-post-cotw', '03-ad-value', '04-post-set-building', '05-post-set-cc', '06-post-set-last', '07-post-set-done',
        '08-post-typeset', '09-post-pick', '10-post-tv-tuesday', '11-post-tv-sides', '12-post-toned', '13-post-share', '14-ad-invite', '15-ad-stack'];
    foreach ($names as $name) {
        File::put("{$kit}/posts/{$name}.png", $png);
    }
    foreach (['cover-1-welcome-home', 'cover-2-every-coin-in-its-place', 'cover-3-organize-track-share'] as $name) {
        File::put("{$kit}/covers/{$name}.png", $png);
    }
    File::put("{$kit}/profile-photos/profile.png", $png);

    try {
        $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $kit])->assertSuccessful();
        $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $kit])->assertSuccessful();
    } finally {
        File::deleteDirectory($kit);
    }

    $inspiration = MarketingAsset::where('purpose', MarketingAsset::PURPOSE_INSPIRATION)->orderBy('id')->get();
    expect($inspiration)->toHaveCount(18)
        ->and(MarketingAsset::count())->toBe(18)
        ->and(MarketingPost::count())->toBe(0)
        ->and($inspiration->every(fn (MarketingAsset $a) => $a->ai_notes['source'] === 'kit' && $a->readState() === MarketingAsset::READ_DONE))->toBeTrue();
    $welcome = $inspiration->first(fn (MarketingAsset $a) => $a->ai_notes['kit_file'] === 'posts/01-post-welcome.png');
    $tv = $inspiration->first(fn (MarketingAsset $a) => $a->ai_notes['kit_file'] === 'posts/10-post-tv-tuesday.png');
    $cover = $inspiration->first(fn (MarketingAsset $a) => $a->ai_notes['kit_file'] === 'covers/cover-1-welcome-home.png');
    expect($welcome->ai_notes['headline'])->toBe("Hi, we're StackerMint 👋")
        ->and($welcome->ai_notes['caption'])->toContain('A home for your coins, notes, and bullion.')
        ->and($tv->ai_notes['topic'])->toBe('toned')
        ->and($cover->ai_notes['headline'])->toBe('Welcome home');
    Storage::disk('local')->assertExists($welcome->path);
    // The kit's captions are its notes: no image is sent to Claude.
    Queue::assertNotPushed(ReadInspiration::class);
    Log::shouldHaveReceived('info')->with('marketing.kit_seed.succeeded', Mockery::on(fn (array $c) => $c['inspiration_created'] === 18))->once();
    Log::shouldHaveReceived('info')->with('marketing.kit_seed.succeeded', Mockery::on(fn (array $c) => $c['inspiration_created'] === 0 && $c['inspiration_kept'] === 18))->once();
});

it('Given a post I like, when I Save to Inspiration, then a favourite inspiration asset exists with the post\'s caption and source post id; saving it again creates nothing new; Undo deletes it', function () {
    Log::spy();
    $photoPath = 'marketing/coins/photos/morgan.jpg';
    Storage::disk('local')->put($photoPath, UploadedFile::fake()->image('morgan.jpg', 30, 20)->getContent());
    $photo = MarketingAsset::factory()->for($this->coins)->create(['path' => $photoPath, 'is_favourite' => false, 'width' => 30, 'height' => 20]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()
        ->create(['caption' => "Album toning on a Morgan.\nShow us yours.", 'asset_id' => $photo->id, 'generation_meta' => ['topic' => 'Album toning']]);

    $studio = Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-20')
        ->assertSeeHtml('data-save-inspiration="'.$post->id.'"')
        ->call('saveToInspiration', $post->id)
        ->assertSeeHtml('data-saved-inspiration="'.$post->id.'"');

    $saved = MarketingAsset::where('purpose', MarketingAsset::PURPOSE_INSPIRATION)->sole();
    expect($saved->is_favourite)->toBeTrue()
        ->and($saved->ai_notes)->toMatchArray(['source' => 'post', 'post_id' => $post->id, 'caption' => "Album toning on a Morgan.\nShow us yours.",
            'headline' => 'Album toning on a Morgan.', 'note' => 'From your post', 'topic' => 'Album toning', 'status' => MarketingAsset::READ_DONE])
        ->and($saved->path)->not->toBe($photoPath)->toStartWith('marketing/coins/inspirations/')
        ->and([$saved->mime, $saved->width, $saved->height])->toBe(['image/jpeg', 30, 20]);
    Storage::disk('local')->assertExists($saved->path);

    $studio->call('saveToInspiration', $post->id);
    expect(MarketingAsset::where('purpose', MarketingAsset::PURPOSE_INSPIRATION)->count())->toBe(1);
    Log::shouldHaveReceived('info')->with('marketing.inspiration.saved_from_post', Mockery::on(fn (array $c) => $c['post_id'] === $post->id))->once();
    Log::shouldHaveReceived('info')->with('marketing.inspiration.save_skipped', Mockery::on(fn (array $c) => $c['post_id'] === $post->id && $c['reason'] === 'already_saved'))->once();

    $studio->call('undoInspiration', $post->id)->assertSeeHtml('data-save-inspiration="'.$post->id.'"');
    expect(MarketingAsset::where('purpose', MarketingAsset::PURPOSE_INSPIRATION)->count())->toBe(0)
        ->and($photo->fresh())->not->toBeNull()
        ->and($post->fresh()->asset_id)->toBe($photo->id);
    Storage::disk('local')->assertMissing($saved->path);
    Storage::disk('local')->assertExists($photoPath);
});

it('saves a text-only post from a library card as a text card, and Undo removes it', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()
        ->create(['caption' => 'One plain fact, no jargon.', 'is_text_only' => true]);

    $library = Livewire::test(Library::class, ['project' => $this->coins])
        ->assertSeeHtml('data-save-inspiration="'.$post->id.'"')
        ->call('saveToInspiration', $post->id)
        ->assertSeeHtml('data-saved-inspiration="'.$post->id.'"');

    $saved = MarketingAsset::sole();
    expect($saved->isTextCard())->toBeTrue()
        ->and($saved->path)->toBe('')
        ->and($saved->is_favourite)->toBeTrue()
        ->and($saved->ai_notes['post_id'])->toBe($post->id);

    $library->call('saveToInspiration', $post->id);
    expect(MarketingAsset::count())->toBe(1);

    $library->call('undoInspiration', $post->id);
    expect(MarketingAsset::count())->toBe(0);
});

it('refuses to save or undo another project\'s post from the library or a post not shown in the studio', function () {
    Log::spy();
    $theirCampaign = MarketingCampaign::factory()->for($this->other)->create();
    $theirs = MarketingPost::factory()->forCampaign($theirCampaign, '2026-10-20 19:00')->approved()->create();

    Livewire::test(Library::class, ['project' => $this->coins])->call('saveToInspiration', $theirs->id)->assertStatus(404);
    Livewire::test(Library::class, ['project' => $this->coins])->call('undoInspiration', $theirs->id)->assertStatus(404);
    Log::shouldHaveReceived('warning')->with('marketing.library.save_refused', Mockery::on(fn (array $c) => $c['post_id'] === $theirs->id))->twice();

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-20')
        ->call('saveToInspiration', $theirs->id)->assertStatus(404);
    expect(MarketingAsset::count())->toBe(0);
});

it('Given a favourite photo is uploaded, then a read job is queued and a faked read stores its description', function () {
    Log::spy();
    Livewire::test(Settings::class, ['project' => $this->coins])->set('photos', [UploadedFile::fake()->image('full (1).jpg', 40, 30)])->assertHasNoErrors();
    $photo = MarketingAsset::sole();
    Queue::assertPushed(ReadInspiration::class, fn (ReadInspiration $job) => $job->assetId === $photo->id);
    expect($photo->readState())->toBe(MarketingAsset::READ_READING);
    Livewire::test(Settings::class, ['project' => $this->coins])->assertSeeHtml('data-read-state="reading"');

    fakeClaude(['description' => 'A toned 1885 Morgan dollar in an album slab, rainbow colours across the obverse, '.str_repeat('with a lot more detail ', 6)]);
    runRead($photo);

    $description = $photo->fresh()->ai_notes['description'];
    expect(mb_strlen($description))->toBeLessThanOrEqual(120)
        ->and($description)->toStartWith('A toned 1885 Morgan dollar in an album slab')
        ->and(lastUserPrompt())->toContain('what is in the picture');
    // Not assertDontSee('Reading…'): Settings' "Read logo" button says that while it runs.
    Livewire::test(Settings::class, ['project' => $this->coins])->assertSee('A toned 1885 Morgan dollar in an album slab')->assertDontSeeHtml('data-read-state="reading"');
    Log::shouldHaveReceived('info')->with('marketing.photo.read_succeeded', Mockery::on(fn (array $c) => $c['asset_id'] === $photo->id && array_key_exists('cost_cents', $c)))->once();
});

it('shows a favourite photo\'s failed read with Retry in Settings, and Retry re-queues it', function () {
    Log::spy();
    $photo = MarketingAsset::factory()->for($this->coins)->create(['ai_notes' => ['status' => MarketingAsset::READ_FAILED]]);

    Livewire::test(Settings::class, ['project' => $this->coins])
        ->assertSee('Couldn’t read')
        ->assertSeeHtml('data-retry-read="'.$photo->id.'"')
        ->call('retryRead', $photo->id);

    Queue::assertPushed(ReadInspiration::class, 1);
    expect($photo->fresh()->readState())->toBe(MarketingAsset::READ_READING);

    // Another project's photo is refused, not read.
    $theirs = MarketingAsset::factory()->for($this->other)->create(['ai_notes' => ['status' => MarketingAsset::READ_FAILED]]);
    Livewire::test(Settings::class, ['project' => $this->coins])->call('retryRead', $theirs->id);
    Queue::assertPushed(ReadInspiration::class, 1);
    Log::shouldHaveReceived('warning')->with('marketing.asset.refused', Mockery::on(fn (array $c) => $c['asset_id'] === $theirs->id))->once();
});

it('does not read a photo uploaded for a single post in the studio', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create();

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-27')
        ->set('uploads.'.$post->id, UploadedFile::fake()->image('coin.jpg', 10, 10))->assertHasNoErrors();

    expect($post->fresh()->asset_id)->not->toBeNull();
    Queue::assertNotPushed(ReadInspiration::class);
});

it('Given favourites with descriptions, when a draft\'s prompt is built, then each photo is listed by id and description, not filename; given an unread favourite, then it is listed by filename', function () {
    fakeClaude();
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_FAVOURITES]);
    $read = MarketingAsset::factory()->for($this->coins)->create(['original_name' => 'full (1).jpg', 'ai_notes' => ['status' => MarketingAsset::READ_DONE, 'description' => 'A toned Morgan in an album slab']]);
    $unread = MarketingAsset::factory()->for($this->coins)->create(['original_name' => 'full (2).jpg', 'ai_notes' => null]);
    $failed = MarketingAsset::factory()->for($this->coins)->create(['original_name' => 'full (3).jpg', 'ai_notes' => ['status' => MarketingAsset::READ_FAILED]]);

    app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));

    expect(lastUserPrompt())->toContain("id {$read->id}: A toned Morgan in an album slab")
        ->not->toContain('full (1).jpg')
        ->toContain("id {$unread->id}: full (2).jpg")
        ->toContain("id {$failed->id}: full (3).jpg");
});

it('Given existing favourites with no description, when the backfill runs, then each gets exactly one read job', function () {
    $unread = MarketingAsset::factory()->for($this->coins)->count(3)->create(['ai_notes' => null]);
    $failed = MarketingAsset::factory()->for($this->coins)->create(['ai_notes' => ['status' => MarketingAsset::READ_FAILED]]);
    MarketingAsset::factory()->for($this->coins)->create(['ai_notes' => ['status' => MarketingAsset::READ_DONE, 'description' => 'Read already']]);
    MarketingAsset::factory()->for($this->coins)->create(['is_favourite' => false, 'ai_notes' => null]);
    inspirationOf($this->coins, ['status' => MarketingAsset::READ_FAILED]);
    MarketingAsset::factory()->for($this->other)->create(['ai_notes' => null]);

    $this->artisan('marketing:read-favourites', ['project' => 'coins'])->expectsOutputToContain('Queued 4')->assertSuccessful();
    // A second run finds them all reading and queues nothing more.
    $this->artisan('marketing:read-favourites', ['project' => 'coins'])->expectsOutputToContain('Queued 0')->assertSuccessful();

    $expected = [...$unread->pluck('id')->all(), $failed->id];
    Queue::assertPushed(ReadInspiration::class, 4);
    foreach ($expected as $id) {
        Queue::assertPushed(ReadInspiration::class, fn (ReadInspiration $job) => $job->assetId === $id);
    }
});

it('refuses the backfill for an unknown project', function () {
    Log::spy();
    $this->artisan('marketing:read-favourites', ['project' => 'nope'])->expectsOutputToContain('No project named "nope"')->assertFailed();
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->with('marketing.photo.backfill_refused', ['project' => 'nope', 'reason' => 'unknown_project'])->once();
});

it('hearts an inspiration image, and deletes it and its file through the confirm', function () {
    $asset = inspirationOf($this->coins, ['headline' => 'Rainbow album toning']);

    inspirationPage()->assertSeeHtml('data-favourite-inspiration="'.$asset->id.'"')
        ->call('toggleFavourite', $asset->id);
    expect($asset->fresh()->is_favourite)->toBeTrue();

    inspirationPage()->call('delete', $asset->id)->assertDontSee('Rainbow album toning');
    expect(MarketingAsset::count())->toBe(0);
    Storage::disk('local')->assertMissing($asset->path);
});

it('refuses to heart, delete or retry another project\'s image or a favourite photo from the wall', function () {
    Log::spy();
    $theirs = inspirationOf($this->other);
    $photo = MarketingAsset::factory()->for($this->coins)->create();

    inspirationPage()->call('toggleFavourite', $theirs->id)->call('delete', $theirs->id)->call('retry', $theirs->id)
        ->call('delete', $photo->id);

    expect(MarketingAsset::count())->toBe(2)->and($theirs->fresh()->is_favourite)->toBeFalse();
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->with('marketing.asset.refused', Mockery::on(fn (array $c) => $c['asset_id'] === $theirs->id))->times(3);
    Log::shouldHaveReceived('warning')->with('marketing.inspiration.refused', Mockery::on(fn (array $c) => $c['asset_id'] === $photo->id && $c['reason'] === 'not_inspiration'))->once();
});

it('says the page reads tone and copy now and layout with the designer later, lists Inspiration in the sub-nav, and has an empty state', function () {
    $this->get('/p/coins/marketing/inspiration')->assertOk()
        ->assertSee('Layout matching arrives with the designer later.')
        ->assertSee('data-marketing-page="inspiration"', false)
        ->assertSee('data-inspiration-empty', false)
        ->assertSee('data-inspiration-drop', false);
    $this->get('/p/coins/marketing/library')->assertSee('data-marketing-page="inspiration"', false);

    Project::where('name', 'other')->update(['is_enabled' => false]);
    $this->get('/p/other/marketing/inspiration')->assertNotFound();
});

it('shows what each tile is: read by the AI, from the kit, or from a post', function () {
    inspirationOf($this->coins, ['headline' => 'Read by AI', 'style' => ['big coin'], 'topic' => 'toning']);
    inspirationOf($this->coins, ['headline' => 'Kit headline', 'source' => 'kit', 'kit_file' => 'posts/01.png', 'caption' => 'Kit caption']);
    MarketingAsset::factory()->for($this->coins)->inspiration(['headline' => 'Saved post headline', 'source' => 'post', 'post_id' => 1, 'note' => 'From your post'])
        ->create(['path' => '', 'mime' => MarketingAsset::TEXT_CARD_MIME, 'is_favourite' => true]);

    inspirationPage()->assertSee('AI read it')->assertSee('From the kit')->assertSee('From your post')->assertSee('Saved post headline')->assertSee('3 so far');
});

it('fails the read of a file that is not an image as unreadable_image', function () {
    Log::spy();
    fakeClaude(['headline' => 'x', 'style' => [], 'topic' => 'y']);
    $asset = inspirationOf($this->coins, ['status' => MarketingAsset::READ_READING]);
    Storage::disk('local')->put($asset->path, 'not an image at all');

    runRead($asset);

    expect($asset->fresh()->readState())->toBe(MarketingAsset::READ_FAILED);
    Http::assertNothingSent();
    Log::shouldHaveReceived('error')->with('marketing.inspiration.read_failed', Mockery::on(fn (array $c) => $c['reason'] === 'unreadable_image'))->once();
});

it('does nothing, and says so in the log, when Undo finds nothing saved', function () {
    Log::spy();
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create();

    Livewire::test(Library::class, ['project' => $this->coins])->call('undoInspiration', $post->id)->assertOk();

    Log::shouldHaveReceived('info')->with('marketing.inspiration.unsave_skipped', ['post_id' => $post->id, 'reason' => 'not_saved'])->once();
});

it('stores nothing from a drop that brought no files back, and refuses more than 30 at once', function () {
    Log::spy();
    inspirationPage()->set('images', [])->assertHasNoErrors();
    Log::shouldHaveReceived('info')->with('marketing.asset.upload_empty', ['project' => 'coins', 'purpose' => MarketingAsset::PURPOSE_INSPIRATION])->once();

    $files = collect(range(1, Inspiration::MAX_PER_DROP + 1))->map(fn (int $i) => UploadedFile::fake()->image("ad-{$i}.png", 4, 4))->all();
    inspirationPage()->set('images', $files)->assertHasErrors(['images' => 'Drop up to 30 images at a time.']);

    expect(MarketingAsset::count())->toBe(0);
    Queue::assertNothingPushed();
});
