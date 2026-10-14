<?php

use App\Actions\Marketing\ApprovePost;
use App\Actions\Marketing\BuildAdHtml;
use App\Actions\Marketing\BuildDefaultAdSpec;
use App\Actions\Marketing\CreateOneOffPost;
use App\Actions\Marketing\DraftOccurrence;
use App\Actions\Marketing\EditPostCaption;
use App\Actions\Marketing\QueuePostRender;
use App\Actions\Marketing\ReviseDraft;
use App\Actions\Marketing\SeedMarketingKit;
use App\Actions\Marketing\SetPostPhoto;
use App\Actions\Marketing\StoreMarketingAsset;
use App\Actions\Marketing\UndoRevision;
use App\Jobs\RefreshProjectJob;
use App\Jobs\RenderPostAd;
use App\Livewire\Marketing\CampaignPage;
use App\Livewire\Marketing\Library;
use App\Livewire\Marketing\PostStudio;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\AdRendererClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeAdRenderer;

/**
 * MK-5 acceptance criteria: every draft and approved post becomes an on-brand ad image. The renderer is the bound
 * FakeAdRenderer (Tests\TestCase), so nothing here starts a browser; the one real render, the shrink-to-fit check
 * and the 375 px studio are in tests/Browser/Marketing/AdRendererTest.php. Clock frozen at Mon 12 Oct 2026 11:00
 * UTC, so TrueView Tuesday (Tue 19:00 UTC) falls on Tue 13 Oct.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    Storage::fake('local');
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->brand = MarketingBrand::factory()->for($this->coins)->create([
        'values' => SeedMarketingKit::BRAND, 'copy_rules' => SeedMarketingKit::COPY_RULES,
        'logo_svg' => '<svg viewBox="0 0 10 10" fill="currentColor"><path d="M1 1h8v8H1z" data-logo-mark="1"/></svg>',
    ]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create();
    $this->renderer = app(AdRendererClient::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A real image stored as one of `$project`'s favourite photos, so a render can embed its bytes.
 */
function adPhoto(Project $project): MarketingAsset
{
    return app(StoreMarketingAsset::class)->handle($project, UploadedFile::fake()->image('morgan.jpg', 600, 600));
}

/**
 * Run `$post`'s render job now, as a worker would.
 */
function runRender(MarketingPost $post): void
{
    app()->call([new RenderPostAd($post->id), 'handle']);
}

/**
 * The render assets that exist, oldest first.
 *
 * @return Collection<int, MarketingAsset>
 */
function renderAssets(): Collection
{
    return MarketingAsset::where('purpose', MarketingAsset::PURPOSE_RENDER)->orderBy('id')->get();
}

it('Given a draft with a photo, when it is written, then a RenderPostAd job is queued, and running it (renderer faked) stores two render assets (1080×1080, 1080×1350) linked to the post', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $photo = adPhoto($this->coins);
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_FAVOURITES]);
    fakeClaude(['drafts' => [
        ['caption' => "Nine down. The 1889-CC is the hard one.\nCarson City Morgans.", 'asset_id' => $photo->id],
        ['caption' => 'Album toning, straight from the paper.', 'asset_id' => null],
        ['caption' => 'Rate this rainbow from one to ten.', 'asset_id' => null],
    ]]);

    $posts = app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-13 19:00'));

    Queue::assertPushed(RenderPostAd::class, 3);
    $post = $posts->first()->fresh();
    Queue::assertPushed(RenderPostAd::class, fn (RenderPostAd $job) => $job->postId === $post->id);
    expect($post->renderState())->toBe(MarketingPost::RENDER_RENDERING)
        ->and($post->adSpec())->toMatchArray(['layout' => 'photo_headline', 'headline' => 'Nine down. The 1889-CC is the hard one.', 'subline' => 'Carson City Morgans.', 'asset_id' => $photo->id]);

    runRender($post);

    $post->refresh();
    $renders = renderAssets();
    expect($renders)->toHaveCount(2)
        ->and($post->renderState())->toBe(MarketingPost::RENDER_RENDERED)
        ->and($post->renderId('square'))->toBe($renders[0]->id)
        ->and($post->renderId('portrait'))->toBe($renders[1]->id)
        ->and([$renders[0]->width, $renders[0]->height])->toBe([1080, 1080])
        ->and([$renders[1]->width, $renders[1]->height])->toBe([1080, 1350])
        ->and($renders->pluck('mime')->unique()->all())->toBe(['image/png'])
        ->and($renders->every(fn (MarketingAsset $a) => $a->project_id === $this->coins->id && ! $a->is_favourite && Storage::disk('local')->exists($a->path)))->toBeTrue();
    // The photo went into the page as bytes: nothing in it may reach the network.
    expect($this->renderer->calls[0]['square']['html'])->toContain('data:image/jpeg;base64,');
});

it('Given a text-only post, when rendered, then it uses the text-card layout and Download offers both PNGs', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create([
        'caption' => "Toning is chemistry, not damage.\nNatural color can add value.", 'is_text_only' => true,
    ]);

    app(QueuePostRender::class)->handle($post);

    $post->refresh();
    expect($post->adSpec()['layout'])->toBe('text_card')
        ->and($post->renderState())->toBe(MarketingPost::RENDER_RENDERED);

    $square = route('marketing.render', ['coins', $post->id, 'square']);
    $portrait = route('marketing.render', ['coins', $post->id, 'portrait']);
    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-13')
        ->assertSeeHtml('data-ad-render="'.$post->id.'"')
        ->assertSeeHtml('href="'.$square.'"')
        ->assertSeeHtml('href="'.$portrait.'"')
        ->assertSee('Download 1:1')
        ->assertSee('Download 4:5');

    foreach (['square' => [1080, 1080], 'portrait' => [1080, 1350]] as $size => $dims) {
        $response = $this->get(route('marketing.render', ['coins', $post->id, $size]))->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('.png')
            ->and(array_slice(getimagesizefromstring($response->streamedContent()) ?: [], 0, 2))->toBe($dims);
    }
});

dataset('ad templates', function () {
    foreach (MarketingCampaign::AD_STYLES as $style) {
        foreach (MarketingPost::AD_LAYOUTS as $layout) {
            foreach (array_keys(MarketingPost::RENDER_SIZES) as $size) {
                yield "{$style} {$layout} {$size}" => [$style, $layout, $size];
            }
        }
    }
});

it('Given each layout and brand values, when its HTML is built, then it contains the brand\'s colours, font and logo and the spec\'s headline, and no external URL (rendered offline)', function (string $style, string $layout, string $size) {
    $this->tvt->update(['ad_style' => $style]);
    $photo = adPhoto($this->coins);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['asset_id' => $photo->id]);
    $spec = ['layout' => $layout, 'headline' => 'Nine & one', 'subline' => 'Carson City Morgans.', 'cta' => 'See the set',
        'asset_id' => $photo->id, 'crop' => 'top', 'badge' => 'Key date'];

    $html = app(BuildAdHtml::class)->handle($post, $spec, $size);

    expect($html)->toContain('--accent:#B79F82')->toContain('--ink:#222222')->toContain('--surface:#FAFAFA')
        ->toContain("--font:'Instrument Sans'")
        ->toContain('data-logo-mark="1"')
        ->toContain('Nine &amp; one')
        ->toContain('data-ad-style="'.$style.'"')
        ->toContain('data-ad-layout="'.$layout.'"')
        ->toContain($size === 'square' ? 'class="ad sq ' : 'class="ad tall ')
        ->not->toMatch('#\b(https?|file)://#i')
        ->not->toContain('<script')
        ->not->toContain('<link');
    if (in_array($layout, MarketingPost::PHOTO_LAYOUTS, true)) {
        expect($html)->toContain('data:image/jpeg;base64,')->toContain('object-position:top');
    }
    if ($layout === 'spotlight') {
        expect($html)->toContain('Key date');
    }
})->with('ad templates');

it('prints the headline\'s length, width in em and widest word for the CSS shrink-to-fit, capitals measuring wider', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();

    $html = app(BuildAdHtml::class)->handle($post, ['layout' => 'text_card', 'headline' => 'Mint MINT'], 'square');

    // Mint 2.2 + space .2 + MINT 2.56 em; the widest word is MINT, plus the space a wrapped line can waste.
    expect($html)->toContain('--chars:9;--ems:4.96;--word-ems:2.76')
        ->toContain('font-size:clamp(');
});

it('cuts a headline longer than its layout can hold at a word, with an ellipsis, so it is never clipped mid-line', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();
    $long = str_repeat('Toned Morgans everywhere ', 12);

    $html = app(BuildAdHtml::class)->handle($post, ['layout' => 'spotlight', 'headline' => $long], 'square');
    $card = app(BuildAdHtml::class)->handle($post, ['layout' => 'question', 'headline' => $long], 'square');

    expect($html)->toContain('Toned Morgans everywhere Toned Morgans…')
        ->and($card)->toMatch('/--chars:(\\d+);/')
        ->and((int) preg_replace('/.*--chars:(\\d+);.*/s', '$1', $card))->toBeLessThanOrEqual(BuildAdHtml::MAX_HEADLINE['question'] + 1);
});

it('uses the campaign\'s ad style, gallery when none is set and for one-off posts', function () {
    $oneOff = MarketingPost::factory()->create(['project_id' => $this->coins->id, 'caption' => 'At the show this weekend.']);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();
    $build = app(BuildAdHtml::class);

    expect($build->handle($post, ['layout' => 'question', 'headline' => 'Q?'], 'square'))->toContain('data-ad-style="gallery"');
    $this->tvt->update(['ad_style' => MarketingCampaign::AD_STYLE_POSTER]);
    expect($build->handle($post->fresh(), ['layout' => 'question', 'headline' => 'Q?'], 'square'))->toContain('data-ad-style="poster"')
        ->and($build->handle($oneOff, ['layout' => 'question', 'headline' => 'Q?'], 'square'))->toContain('data-ad-style="gallery"');
});

it('draws a photo layout whose photo is gone as the text card', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();

    $html = app(BuildAdHtml::class)->handle($post, ['layout' => 'photo_headline', 'headline' => 'Hello', 'asset_id' => 999999], 'square');

    expect($html)->toContain('data-ad-layout="text_card"')->not->toContain('data:image');
});

it('builds the default ad spec from the caption: photo post → photo + headline, text-only → text card, headline the first line, subline the rest', function () {
    $photo = adPhoto($this->coins);
    $build = app(BuildDefaultAdSpec::class);

    expect($build->handle(new MarketingPost(['caption' => "First line.\n\nSecond line.\nThird.", 'asset_id' => $photo->id])))
        ->toBe(['layout' => 'photo_headline', 'headline' => 'First line.', 'subline' => 'Second line. Third.', 'cta' => null, 'asset_id' => $photo->id, 'crop' => 'center', 'badge' => null])
        ->and($build->handle(new MarketingPost(['caption' => 'Only one line.'])))
        ->toBe(['layout' => 'text_card', 'headline' => 'Only one line.', 'subline' => null, 'cta' => null, 'asset_id' => null, 'crop' => null, 'badge' => null]);
});

it('Given a re-render, then the old render assets and their files are removed and exactly two remain', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    app(QueuePostRender::class)->handle($post);
    $old = renderAssets();
    expect($old)->toHaveCount(2);

    app(QueuePostRender::class)->handle($post->fresh());

    $now = renderAssets();
    expect($now)->toHaveCount(2)
        ->and($now->modelKeys())->not->toContain($old[0]->id)->not->toContain($old[1]->id)
        ->and(Storage::disk('local')->exists($old[0]->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($old[1]->path))->toBeFalse()
        ->and($post->fresh()->renderId('square'))->toBe($now[0]->id);
});

dataset('render failures', ['timeout', 'process_failed']);

it('Given the renderer fails or times out, then the post keeps the HTML preview, the studio shows "Couldn\'t render · Retry", marketing.render.failed is logged, and Retry re-queues', function (string $reason) {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true, 'caption' => 'Toning is chemistry.']);
    $this->renderer->failWith($reason);
    Log::spy();

    app(QueuePostRender::class)->handle($post);

    expect($post->fresh()->renderState())->toBe(MarketingPost::RENDER_FAILED)
        ->and(renderAssets())->toHaveCount(0);
    Log::shouldHaveReceived('warning')->with('marketing.render.failed', Mockery::on(fn (array $c) => $c['post_id'] === $post->id
        && $c['layout'] === 'text_card' && $c['reason'] === $reason))->once();

    $studio = Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-13')
        ->assertSeeHtml('data-post-preview="'.$post->id.'"')
        ->assertSeeHtml('data-render-failed="'.$post->id.'"')
        ->assertSee('Couldn’t render')
        ->assertDontSeeHtml('data-ad-render="'.$post->id.'"');

    Queue::fake([RenderPostAd::class]);
    $studio->call('retryRender', $post->id);
    Queue::assertPushed(RenderPostAd::class, fn (RenderPostAd $job) => $job->postId === $post->id);
    expect($post->fresh()->renderState())->toBe(MarketingPost::RENDER_RENDERING);
})->with('render failures');

it('shows "Rendering…" while a render is queued and polls until it lands', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    app(QueuePostRender::class)->handle($post);

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-13')
        ->assertSeeHtml('data-rendering="'.$post->id.'"')
        ->assertSeeHtml('wire:poll.2s')
        ->assertSeeHtml('data-post-preview="'.$post->id.'"');
});

it('logs an unexpected render error as failed, marks the post and rethrows', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    app()->instance(AdRendererClient::class, new class implements AdRendererClient
    {
        public function render(array $pages): array
        {
            throw new RuntimeException('disk full');
        }
    });
    Log::spy();

    expect(fn () => runRender($post))->toThrow(RuntimeException::class, 'disk full');
    expect($post->fresh()->renderState())->toBe(MarketingPost::RENDER_FAILED);
    Log::shouldHaveReceived('warning')->with('marketing.render.failed', Mockery::on(fn (array $c) => $c['post_id'] === $post->id && $c['reason'] === 'exception'))->once();
});

it('logs marketing.render.queued and marketing.render.succeeded with the layout, time and bytes', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    Log::spy();

    app(QueuePostRender::class)->handle($post);

    Log::shouldHaveReceived('info')->with('marketing.render.queued', Mockery::on(fn (array $c) => $c['post_id'] === $post->id && $c['layout'] === 'text_card'))->once();
    Log::shouldHaveReceived('info')->with('marketing.render.succeeded', Mockery::on(fn (array $c) => $c['post_id'] === $post->id
        && $c['layout'] === 'text_card' && is_int($c['ms']) && $c['bytes'] > 0))->once();
});

it('does not render a draft still waiting for its photo, and says why', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();
    Log::spy();

    expect(app(QueuePostRender::class)->handle($post))->toBeFalse();

    Queue::assertNotPushed(RenderPostAd::class);
    Log::shouldHaveReceived('info')->with('marketing.render.skipped', Mockery::on(fn (array $c) => $c['post_id'] === $post->id && $c['reason'] === 'needs_photo'))->once();
});

it('does not render a discarded, skipped or posted post, and says why', function (string $state) {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->{$state}()->create(['is_text_only' => true]);
    Log::spy();

    expect(app(QueuePostRender::class)->handle($post))->toBeFalse();

    Queue::assertNotPushed(RenderPostAd::class);
    Log::shouldHaveReceived('info')->with('marketing.render.skipped', Mockery::on(fn (array $c) => $c['reason'] === 'not_renderable'))->once();
})->with(['discarded', 'skipped', 'posted']);

it('skips a render job whose post was deleted before the worker reached it', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    $id = $post->id;
    $post->delete();
    Log::spy();

    app()->call([new RenderPostAd($id), 'handle']);

    expect($this->renderer->calls)->toBe([]);
    Log::shouldHaveReceived('info')->with('marketing.render.skipped', Mockery::on(fn (array $c) => $c['post_id'] === $id && $c['reason'] === 'gone'))->once();
});

it('renders again after Revise, Undo, a caption edit and a photo change, each with a fresh spec', function () {
    fakeClaude(['caption' => "Album toning, straight from the paper.\nShow us yours."]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['caption' => 'First line.', 'is_text_only' => true]);
    $photo = adPhoto($this->coins);

    app(ReviseDraft::class)->handle($post, 'Make it about the album.');
    expect($post->fresh()->adSpec()['headline'])->toBe('Album toning, straight from the paper.')
        ->and(count($this->renderer->calls))->toBe(1);

    app(UndoRevision::class)->handle($post->fresh());
    expect($post->fresh()->adSpec()['headline'])->toBe('First line.')
        ->and(count($this->renderer->calls))->toBe(2);

    app(EditPostCaption::class)->handle($post->fresh(), "Edited by hand.\nSecond.");
    expect($post->fresh()->adSpec())->toMatchArray(['headline' => 'Edited by hand.', 'subline' => 'Second.'])
        ->and(count($this->renderer->calls))->toBe(3);

    app(SetPostPhoto::class)->handle($post->fresh(), $photo);
    expect($post->fresh()->adSpec())->toMatchArray(['layout' => 'photo_headline', 'asset_id' => $photo->id])
        ->and(count($this->renderer->calls))->toBe(4)
        ->and(renderAssets())->toHaveCount(2);
});

it('renders on approve when the post has no render yet, and not again when it has one', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $bare = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);

    app(ApprovePost::class)->handle($bare);
    Queue::assertPushed(RenderPostAd::class, 1);

    $rendered = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->create(['is_text_only' => true]);
    runRender($rendered);
    app(ApprovePost::class)->handle($rendered->fresh());
    Queue::assertPushed(RenderPostAd::class, 1);
});

it('renders a new one-off post as a text card', function () {
    $post = app(CreateOneOffPost::class)->handle($this->coins, CarbonImmutable::parse('2026-10-15 12:00'), 'At the Baltimore show.', CarbonImmutable::now());

    expect($post->fresh()->renderState())->toBe(MarketingPost::RENDER_RENDERED)
        ->and($post->fresh()->adSpec()['layout'])->toBe('text_card');
});

it('Given the backfill runs twice, then each draft/approved post without a render gets exactly one job', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $draft = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    $approved = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create(['is_text_only' => true]);
    $failed = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->approved()->create(['is_text_only' => true,
        'ad' => ['layout' => 'question', 'headline' => 'Kept spec?', 'render' => ['status' => MarketingPost::RENDER_FAILED, 'reason' => 'timeout']]]);
    $done = MarketingPost::factory()->forCampaign($this->tvt, '2026-11-03 19:00')->approved()->create(['is_text_only' => true]);
    app()->call([new RenderPostAd($done->id), 'handle']);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-11-10 19:00')->posted()->create(['is_text_only' => true]);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-11-17 19:00')->discarded()->create(['is_text_only' => true]);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-11-24 19:00')->create(); // still needs its photo
    MarketingPost::factory()->create(['is_text_only' => true]); // another project's

    $this->artisan('marketing:render', ['project' => 'coins'])->expectsOutputToContain('Queued 3 posts to render.')->assertSuccessful();
    $this->artisan('marketing:render', ['project' => 'coins'])->expectsOutputToContain('Queued 0 posts to render.')->assertSuccessful();

    Queue::assertPushed(RenderPostAd::class, 3);
    foreach ([$draft, $approved, $failed] as $post) {
        Queue::assertPushed(RenderPostAd::class, fn (RenderPostAd $job) => $job->postId === $post->id);
    }
    // A spec already on the post (MK-19's, later) is kept; only a missing one is built.
    expect($failed->fresh()->adSpec()['layout'])->toBe('question');
});

it('refuses the backfill for an unknown project, and logs it', function () {
    Log::spy();

    $this->artisan('marketing:render', ['project' => 'nope'])->expectsOutputToContain('No project named "nope"')->assertFailed();

    Log::shouldHaveReceived('warning')->with('marketing.render.backfill_refused', Mockery::on(fn (array $c) => $c['reason'] === 'unknown_project'))->once();
});

it('Given another project\'s post id, when Download or Retry is requested, then it is refused (404) and logged', function () {
    $other = Project::factory()->create(['name' => 'other', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $theirs = MarketingPost::factory()->create(['project_id' => $other->id, 'is_text_only' => true]);
    app(QueuePostRender::class)->handle($theirs);
    $mine = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    app(QueuePostRender::class)->handle($mine);
    Log::spy();

    $this->get(route('marketing.render', ['coins', $theirs->id, 'square']))->assertNotFound();
    Log::shouldHaveReceived('warning')->with('marketing.render.refused', Mockery::on(fn (array $c) => $c['post_id'] === $theirs->id && $c['reason'] === 'unknown_post'))->once();

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-13')
        ->call('retryRender', $theirs->id)->assertNotFound();
    Log::shouldHaveReceived('warning')->with('marketing.studio.refused', Mockery::on(fn (array $c) => $c['post_id'] === $theirs->id && $c['reason'] === 'not_shown'))->once();
});

it('refuses a Download of an unknown size, or of a post with no render yet, and logs it', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    Log::spy();

    $this->get('/p/coins/marketing/posts/'.$post->id.'/ad/huge')->assertNotFound();
    $this->get(route('marketing.render', ['coins', $post->id, 'square']))->assertNotFound();

    Log::shouldHaveReceived('warning')->with('marketing.render.refused', Mockery::on(fn (array $c) => $c['reason'] === 'unknown_size'))->once();
    Log::shouldHaveReceived('warning')->with('marketing.render.refused', Mockery::on(fn (array $c) => $c['reason'] === 'not_rendered'))->once();
});

it('shows the 1:1 render on library cards when there is one', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create(['is_text_only' => true]);
    $bare = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-20 19:00')->approved()->create(['is_text_only' => true, 'caption' => 'Not rendered.']);
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    runRender($post);

    Livewire::test(Library::class, ['project' => $this->coins])
        ->assertSeeHtml('data-library-render="'.$post->id.'"')
        ->assertSeeHtml('src="'.route('marketing.asset', ['coins', $post->fresh()->renderId('square')]).'"')
        ->assertSeeHtml('data-post-preview="'.$bare->id.'"');
});

it('shows the 1:1 render in the calendar\'s quick peek when there is one', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create(['is_text_only' => true]);
    runRender($post);

    $html = $this->get('/p/coins/marketing/calendar')->assertOk()->getContent();

    // The peek's data goes through Js::from, which escapes every slash in the URL.
    expect($html)->toContain('data-peek-image')
        ->and(str_replace('\\', '', $html))->toContain(route('marketing.asset', ['coins', $post->fresh()->renderId('square')]));
});

it('saves the campaign\'s ad style from the campaign form and refuses one it does not know', function () {
    Livewire::test(CampaignPage::class, ['project' => $this->coins, 'campaign' => 'trueview-tuesday'])
        ->assertSet('adStyle', MarketingCampaign::AD_STYLE_GALLERY)
        ->assertSeeHtml('data-ad-style-select')
        ->set('adStyle', 'neon')->call('save')->assertHasErrors('adStyle')
        ->set('adStyle', MarketingCampaign::AD_STYLE_VAULT)->call('save')->assertHasNoErrors();

    expect($this->tvt->fresh()->ad_style)->toBe(MarketingCampaign::AD_STYLE_VAULT)
        ->and($this->tvt->fresh()->adStyle())->toBe(MarketingCampaign::AD_STYLE_VAULT);
});

it('stores the gallery style as null, so saving an untouched form changes nothing', function () {
    $this->tvt->update(['ad_style' => MarketingCampaign::AD_STYLE_POSTER]);
    Log::spy();

    Livewire::test(CampaignPage::class, ['project' => $this->coins, 'campaign' => 'trueview-tuesday'])
        ->set('adStyle', MarketingCampaign::AD_STYLE_GALLERY)->call('save')->assertHasNoErrors()
        ->call('save');

    expect($this->tvt->fresh()->ad_style)->toBeNull()
        ->and($this->tvt->fresh()->adStyle())->toBe(MarketingCampaign::AD_STYLE_GALLERY);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx) => $event === 'marketing.campaign.updated' && $ctx['changed'] === ['ad_style'])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx) => $event === 'marketing.campaign.updated' && $ctx['changed'] === [])->once();
});

it('refuses a Download whose render file is gone, and logs it', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true]);
    app(QueuePostRender::class)->handle($post);
    Storage::disk('local')->delete(MarketingAsset::findOrFail($post->fresh()->renderId('square'))->path);
    Log::spy();

    $this->get(route('marketing.render', ['coins', $post->id, 'square']))->assertNotFound();

    Log::shouldHaveReceived('warning')->with('marketing.render.refused', Mockery::on(fn (array $c) => $c['reason'] === 'missing_file'))->once();
});

it('draws a photo layout whose photo belongs to another project as the text card, without its bytes', function () {
    $other = Project::factory()->create(['name' => 'other']);
    $theirs = adPhoto($other);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();

    $html = app(BuildAdHtml::class)->handle($post, ['layout' => 'spotlight', 'headline' => 'Hello', 'asset_id' => $theirs->id], 'square');

    expect($html)->toContain('data-ad-layout="text_card"')->not->toContain('data:image');
});

it('Retry on a post still waiting for its photo queues nothing and says so', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create();

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-13')
        ->assertDontSeeHtml('data-render-bar="'.$post->id.'"')
        ->call('retryRender', $post->id);

    Queue::assertNotPushed(RenderPostAd::class);
    expect($post->fresh()->renderState())->toBe(MarketingPost::RENDER_NONE);
});

it('never deletes a photo when replacing a render whose stored id points at one', function () {
    $photo = adPhoto($this->coins);
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['is_text_only' => true,
        'ad' => ['layout' => 'text_card', 'headline' => 'Hi', 'render' => ['status' => MarketingPost::RENDER_RENDERED, 'square' => $photo->id, 'portrait' => $photo->id]]]);

    runRender($post);

    expect(MarketingAsset::find($photo->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($photo->path))->toBeTrue()
        ->and(renderAssets())->toHaveCount(2);
});
