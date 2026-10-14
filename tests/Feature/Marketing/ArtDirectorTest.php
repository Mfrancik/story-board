<?php

use App\Actions\Marketing\DraftOccurrence;
use App\Actions\Marketing\ReviseDraft;
use App\Actions\Marketing\SeedMarketingKit;
use App\Actions\Marketing\SetPostPhoto;
use App\Actions\Marketing\UndoRevision;
use App\Exceptions\MarketingAiException;
use App\Jobs\RefreshProjectJob;
use App\Jobs\RenderPostAd;
use App\Livewire\Marketing\CampaignPage;
use App\Livewire\Marketing\PostStudio;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\MarketingPrompts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * MK-19 acceptance criteria: Claude art-directs each draft. The draft reply carries an `ad` spec per caption (layout,
 * on-image words, crop), NormaliseAdSpec cleans it, it is stored on the post and in generation_meta, and MK-5's
 * render runs from it; Revise returns a new spec. Claude is faked with Http::fake (fakeClaude()). The clock is frozen
 * at Mon 12 Oct 2026 11:00 UTC, so TrueView Tuesday (Tue 19:00 UTC) next falls on Tue 13, 20 and 27 Oct.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    Storage::fake('local');
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    MarketingBrand::factory()->for($this->coins)->create([
        'values' => SeedMarketingKit::BRAND, 'voice' => SeedMarketingKit::VOICE, 'copy_rules' => SeedMarketingKit::COPY_RULES,
    ]);
    // Favourites, so the model may pick a photo and photo layouts are drawable.
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create([
        'photo_mode' => MarketingCampaign::PHOTO_FAVOURITES, 'brief' => 'Show off the toning.',
    ]);
    $this->photo = MarketingAsset::factory()->for($this->coins)->create(['original_name' => 'morgan.jpg']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * One draft of a faked draft reply: `$caption` with `$ad` merged over a clean question spec.
 *
 * @param  array<string, mixed>  $ad
 * @return array<string, mixed>
 */
function artDraft(string $caption, array $ad = [], ?int $asset = null): array
{
    return ['caption' => $caption, 'asset_id' => $asset, 'ad' => [
        'layout' => 'question', 'headline' => 'Which toner wins?', 'subline' => null, 'cta' => null, 'crop' => 'center', 'badge' => null, ...$ad,
    ]];
}

/**
 * Draft TrueView Tuesday's 27 Oct date from fake Claude's `$drafts`; returns the new posts in candidate order.
 *
 * @param  list<array<string, mixed>>  $drafts
 * @return list<MarketingPost>
 */
function artFill(MarketingCampaign $campaign, array $drafts): array
{
    fakeClaude(['drafts' => $drafts]);

    return app(DraftOccurrence::class)->handle($campaign, CarbonImmutable::parse('2026-10-27 19:00'))->all();
}

it('Given a faked Claude reply with three drafts and ad specs, when a date is filled, then each draft post stores its spec and a render job is queued for each', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    Log::spy();

    $posts = artFill($this->tvt, [
        artDraft('Rainbow toning on a Morgan.', ['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan', 'cta' => 'Start your collection', 'crop' => 'top'], $this->photo->id),
        artDraft('Which toner wins this week?'),
        artDraft('4,812 toned Morgans graded.', ['layout' => 'stat', 'headline' => '4,812', 'subline' => 'toned Morgans graded']),
    ]);

    expect($posts[0]->fresh()->adSpec())->toBe(['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan', 'subline' => null,
        'cta' => 'Start your collection', 'asset_id' => $this->photo->id, 'crop' => 'top', 'badge' => null])
        ->and($posts[1]->fresh()->adSpec())->toMatchArray(['layout' => 'question', 'headline' => 'Which toner wins?', 'asset_id' => null])
        ->and($posts[2]->fresh()->adSpec())->toMatchArray(['layout' => 'stat', 'headline' => '4,812', 'subline' => 'toned Morgans graded'])
        // What Claude chose is recorded with the call's other metadata.
        ->and($posts[0]->fresh()->generation_meta['ad']['layout'])->toBe('photo_headline')
        ->and($posts[2]->fresh()->generation_meta['ad']['headline'])->toBe('4,812')
        ->and($posts[0]->fresh()->renderState())->toBe(MarketingPost::RENDER_RENDERING);
    Queue::assertPushed(RenderPostAd::class, 3);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.draft.succeeded'
        && $context['layouts'] === ['photo_headline', 'question', 'stat'])->once();
});

it('Given a reply where all three drafts use the same layout, then the reply is rejected as `invalid_reply`, no posts are written, and the date shows MK-8\'s existing failure state with Retry', function () {
    fakeClaude(['drafts' => [artDraft('One.'), artDraft('Two.'), artDraft('Three.')]]);
    Log::spy();

    $page = Livewire::test(CampaignPage::class, ['project' => $this->coins, 'campaign' => 'trueview-tuesday'])->call('fillDate', '2026-10-27');

    expect($this->tvt->posts()->count())->toBe(0);
    $page->assertSeeHtml('data-fill-failed="2026-10-27"')->assertSee('Drafting failed')->assertSee('Retry');
    Log::shouldHaveReceived('error')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.draft.failed' && $context['reason'] === 'invalid_reply')->once();
});

it('Given an unknown layout, then that draft gets the default spec and `marketing.ai.ad_spec_fixed` is logged', function () {
    Log::spy();

    $posts = artFill($this->tvt, [artDraft('Rainbow toning on a Morgan.', ['layout' => 'carousel', 'headline' => 'Spin it']), artDraft('Two?'), artDraft('Three.', ['layout' => 'text_card'])]);

    $post = $posts[0]->fresh();
    expect($post->adSpec())->toMatchArray(['layout' => 'text_card', 'headline' => 'Rainbow toning on a Morgan.', 'cta' => null, 'badge' => null]);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.ad_spec_fixed'
        && $context['post_id'] === $post->id && $context['field'] === 'layout' && $context['reason'] === 'unknown_layout')->once();
});

it('Given a 90-character headline, then it is trimmed to ≤ 60 at a word boundary', function () {
    Log::spy();
    $long = 'Rainbow toning on a Morgan dollar that sat in an old paper album for decades of old sulfur';
    expect(mb_strlen($long))->toBe(90);

    $posts = artFill($this->tvt, [artDraft('Rainbow.', ['layout' => 'text_card', 'headline' => $long]), artDraft('Two?'), artDraft('Three.', ['layout' => 'text_card'])]);

    $headline = $posts[0]->fresh()->adSpec()['headline'];
    expect(mb_strlen($headline))->toBeLessThanOrEqual(60)
        ->and($headline)->toBe('Rainbow toning on a Morgan dollar that sat in an old paper')
        ->and(str_starts_with($long, $headline.' '))->toBeTrue();
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.ad_spec_fixed'
        && $context['field'] === 'headline' && $context['reason'] === 'too_long')->once();
});

it('Given a photo layout and `asset_id` null, then the draft uses the text-card layout', function () {
    Log::spy();

    $posts = artFill($this->tvt, [artDraft('Rainbow.', ['layout' => 'spotlight', 'badge' => 'Key date']), artDraft('Two?'), artDraft('Three.', ['layout' => 'stat', 'headline' => '12'])]);

    $post = $posts[0]->fresh();
    expect($post->asset_id)->toBeNull()
        ->and($post->adSpec()['layout'])->toBe('text_card')
        // What Claude asked for is still the record, so a photo added later can bring the spotlight back.
        ->and($post->generation_meta['ad']['layout'])->toBe('spotlight');
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.ad_spec_fixed'
        && $context['post_id'] === $post->id && $context['field'] === 'layout' && $context['reason'] === 'no_photo')->once();
});

it('Given a draft prompt is built, then it lists every MK-5 layout with its "use when" line and the copy rules, and the taste and inspiration blocks are unchanged', function () {
    $liked = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create(['caption' => "A liked caption.\nLine two."]);
    MarketingFeedback::create(['project_id' => $this->coins->id, 'campaign_id' => $this->tvt->id, 'post_id' => $liked->id, 'vote' => MarketingFeedback::LIKED]);
    MarketingAsset::factory()->for($this->coins)->inspiration(['headline' => 'Rainbow album toning', 'style' => ['dark full-bleed coin'], 'topic' => 'toning'])->create(['is_favourite' => true]);

    $prompt = app(MarketingPrompts::class)->draft($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));
    $text = $prompt['system']."\n".$prompt['user'];

    foreach (MarketingPost::AD_LAYOUTS as $layout) {
        expect($text)->toContain("- {$layout} (".MarketingPost::AD_LAYOUT_LABELS[$layout].'): '.MarketingPrompts::LAYOUT_USE_WHEN[$layout]);
    }
    expect($text)->toContain('No hashtags.')
        ->toContain('headline: at most 60 characters')
        ->toContain('at least two different layouts')
        // The campaign's ad style, for context; the model never picks it.
        ->toContain('Gallery')
        // The brand's look itself never reaches the model.
        ->not->toMatch('/colou?r|font|typeface|#[0-9A-F]{6}/i');
    // MK-8's taste block and MK-18's inspiration block, exactly as they were, and the ad block after both.
    $taste = "Captions the owner liked (match this taste):\n- \"A liked caption. / Line two.\"";
    $inspiration = "Ads the owner likes (follow their tone and wording; never copy them):\n- \"Rainbow album toning\" (dark full-bleed coin; toning)";
    expect($prompt['user'])->toContain($taste)->toContain($inspiration)
        ->and(strpos($prompt['user'], 'at least two different layouts'))->toBeGreaterThan(strpos($prompt['user'], $inspiration));
});

it('Given Revise with "make it a question", when the faked reply changes the layout, then the post\'s spec changes, a version keeps the old spec, and a re-render is queued', function () {
    Queue::fake([RefreshProjectJob::class, RenderPostAd::class]);
    $old = ['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan', 'subline' => null, 'cta' => 'Start your collection', 'asset_id' => $this->photo->id, 'crop' => 'center', 'badge' => null];
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create([
        'caption' => 'Rainbow toning on a Morgan.', 'asset_id' => $this->photo->id, 'ad' => $old,
        'generation_meta' => ['call' => 'draft', 'topic_key' => 'own:Toning', 'ad' => $old],
    ]);
    fakeClaude(['caption' => 'Rainbow toning on a Morgan. Which toner wins?', 'asset_id' => null,
        'ad' => ['layout' => 'question', 'headline' => 'Which toner wins?', 'subline' => 'Rainbow on a Morgan', 'cta' => null, 'crop' => 'center', 'badge' => null]]);

    $revised = app(ReviseDraft::class)->handle($post, 'make it a question');

    expect($revised->fresh()->adSpec())->toMatchArray(['layout' => 'question', 'headline' => 'Which toner wins?', 'subline' => 'Rainbow on a Morgan', 'asset_id' => $this->photo->id])
        ->and($revised->fresh()->generation_meta['ad']['layout'])->toBe('question')
        ->and($post->versions()->sole()->generation_meta['ad'])->toEqual($old)
        ->and($revised->fresh()->renderState())->toBe(MarketingPost::RENDER_RENDERING);
    Queue::assertPushed(RenderPostAd::class, 1);
    // The revise prompt shows Claude the current ad, so the note can change its words or its layout.
    expect(Http::recorded()->last()[0]['messages'][0]['content'])->toContain('Photo + headline')->toContain('Rainbow on a Morgan');
});

it('Given the studio shows a draft with a spec, then the layout name and CTA line are shown under the image', function () {
    $spec = ['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan', 'subline' => null, 'cta' => 'Start your collection', 'asset_id' => $this->photo->id, 'crop' => 'center', 'badge' => null];
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['asset_id' => $this->photo->id, 'ad' => $spec]);
    $plain = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['ad' => [...$spec, 'layout' => 'text_card', 'cta' => null, 'asset_id' => null]]);

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-27')
        ->assertSeeHtml('data-ad-spec="'.$post->id.'"')
        ->assertSee('Layout: Photo + headline · CTA: Start your collection')
        // No CTA, no CTA part.
        ->assertSeeHtml('data-ad-spec="'.$plain->id.'"')
        ->assertSee('Layout: Bold text card')
        ->assertDontSee('Layout: Bold text card · CTA');
});

it('fills a reply with no ad spec (an MK-8 reply) with the default spec and logs that it was missing', function () {
    Log::spy();

    $posts = artFill($this->tvt, [['caption' => 'One.', 'asset_id' => null], ['caption' => 'Two.', 'asset_id' => null], ['caption' => 'Three.', 'asset_id' => null]]);

    expect($posts[0]->fresh()->adSpec())->toMatchArray(['layout' => 'text_card', 'headline' => 'One.'])
        ->and($posts[0]->fresh()->generation_meta['ad'])->toBeNull();
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.ad_spec_fixed'
        && $context['field'] === 'ad' && $context['reason'] === 'missing')->times(3);
});

it('cleans the rest of a spec: an empty headline, over-long cta, subline and badge, a bad crop, and a layout\'s own smaller headline cap', function () {
    Log::spy();
    $posts = artFill($this->tvt, [
        artDraft('Caption first line.', ['layout' => 'text_card', 'headline' => '  ', 'cta' => 'Tell us which toner you think wins', 'subline' => str_repeat('word ', 30), 'crop' => 'middle', 'badge' => 'A badge that is far too long']),
        artDraft('Two?'),
        artDraft('Three.', ['layout' => 'stat', 'headline' => '4,812 toned Morgans']),
    ]);

    $spec = $posts[0]->fresh()->adSpec();
    expect($spec['headline'])->toBe('Caption first line.')
        ->and($spec['cta'])->toBe('Tell us which toner you')
        ->and(mb_strlen((string) $spec['subline']))->toBeLessThanOrEqual(120)
        ->and($spec['badge'])->toBe('A badge that is far too')
        ->and($spec['crop'])->toBe('center')
        // Stat's cap (BuildAdHtml::MAX_HEADLINE, 14) is below the 60 every headline gets.
        ->and($posts[2]->fresh()->adSpec()['headline'])->toBe('4,812 toned');
    foreach ([['headline', 'empty'], ['cta', 'too_long'], ['subline', 'too_long'], ['badge', 'too_long'], ['crop', 'invalid']] as [$field, $reason]) {
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.ad_spec_fixed'
            && $context['post_id'] === $posts[0]->id && $context['field'] === $field && $context['reason'] === $reason)->once();
    }
});

it('keeps a photo layout for a draft still waiting for the owner\'s photo, and draws it with that photo once added', function () {
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_PER_POST]);

    $posts = artFill($this->tvt->fresh(), [artDraft('Rainbow.', ['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan']), artDraft('Two?'), artDraft('Three.', ['layout' => 'text_card'])]);

    $post = $posts[0]->fresh();
    expect($post->needsPhoto())->toBeTrue()
        ->and($post->adSpec()['layout'])->toBe('photo_headline')
        ->and($post->renderState())->toBe(MarketingPost::RENDER_NONE);

    app(SetPostPhoto::class)->handle($post, $this->photo);

    // Claude's layout and words survive the photo change; MK-5's default would have used the caption's first line.
    expect($post->fresh()->adSpec())->toMatchArray(['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan', 'asset_id' => $this->photo->id]);
});

it('Undo puts the previous version\'s spec back', function () {
    $old = ['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan', 'subline' => null, 'cta' => null, 'asset_id' => $this->photo->id, 'crop' => 'center', 'badge' => null];
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create([
        'caption' => 'Rainbow toning.', 'asset_id' => $this->photo->id, 'ad' => $old, 'generation_meta' => ['call' => 'draft', 'ad' => $old],
    ]);
    fakeClaude(['caption' => 'Which toner wins?', 'asset_id' => null, 'ad' => ['layout' => 'question', 'headline' => 'Which toner wins?', 'subline' => null, 'cta' => null, 'crop' => 'center', 'badge' => null]]);
    app(ReviseDraft::class)->handle($post, 'make it a question');
    expect($post->fresh()->adSpec()['layout'])->toBe('question');

    app(UndoRevision::class)->handle($post->fresh());

    expect($post->fresh()->adSpec())->toMatchArray(['layout' => 'photo_headline', 'headline' => 'Rainbow on a Morgan']);
});

it('keeps a studio rebuild that changes only the ad, rather than undoing it as unchanged', function () {
    $old = ['layout' => 'text_card', 'headline' => 'Rainbow toning.', 'subline' => null, 'cta' => null, 'asset_id' => null, 'crop' => 'center', 'badge' => null];
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['caption' => 'Rainbow toning.', 'ad' => $old, 'generation_meta' => ['call' => 'draft', 'ad' => $old]]);
    fakeClaude(['caption' => 'Rainbow toning.', 'asset_id' => null, 'ad' => ['layout' => 'question', 'headline' => 'Which toner wins?', 'subline' => null, 'cta' => null, 'crop' => 'center', 'badge' => null]]);

    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open', $this->tvt->id, '2026-10-27')
        ->call('revise', $post->id, 'make it a question')->assertReturned(true);

    expect($post->fresh())->version->toBe(2)
        ->and($post->fresh()->adSpec()['layout'])->toBe('question');
});

it('rejects a reply with three identical layouts from DraftOccurrence itself', function () {
    expect(fn () => artFill($this->tvt, [artDraft('One.'), artDraft('Two.'), artDraft('Three.')]))
        ->toThrow(fn (MarketingAiException $e) => expect($e->reason)->toBe('invalid_reply'));
    expect($this->tvt->posts()->count())->toBe(0);
});
