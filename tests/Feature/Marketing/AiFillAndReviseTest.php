<?php

use App\Actions\Marketing\DraftOccurrence;
use App\Actions\Marketing\LintCaption;
use App\Actions\Marketing\RegenerateOccurrence;
use App\Actions\Marketing\ReviseDraft;
use App\Actions\Marketing\SeedMarketingKit;
use App\Exceptions\MarketingAiException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Jobs\DraftOccurrenceJob;
use App\Jobs\RefreshProjectJob;
use App\Livewire\Marketing\CampaignPage;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\MarketingPrompts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * MK-8 acceptance criteria: Fill drafts three posts per open campaign date with Claude (faked here with Http::fake),
 * ReviseDraft rebuilds a draft from written feedback keeping the earlier version, and LintCaption warns on the copy
 * rules. The clock is frozen at Mon 12 Oct 2026 11:00 UTC, so TrueView Tuesday (Tue 19:00 UTC) next falls on Tue 13,
 * 20 and 27 Oct. Only RefreshProjectJob is faked: DraftOccurrenceJob runs on the sync queue unless a test fakes it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->brand = MarketingBrand::factory()->for($this->coins)->create([
        'values' => SeedMarketingKit::BRAND,
        'voice' => SeedMarketingKit::VOICE,
        'copy_rules' => SeedMarketingKit::COPY_RULES,
        'idea_menu' => [
            ['category' => 'Toned & TrueView', 'ideas' => [['title' => 'TrueView Tuesday', 'text' => 'Show us your best toner.']]],
            ['category' => 'Sets', 'ideas' => [['title' => 'Slot filled', 'text' => 'Another slot filled. [coin] joins the [set] set.']]],
        ],
    ]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create([
        'topics' => ['ideas' => ['Toned & TrueView|TrueView Tuesday', 'Sets|Slot filled'], 'own' => [['text' => 'Album toning', 'detail' => 'the rainbow came from the paper']]],
        'brief' => 'Show off the toning. One line, then ask people to share theirs.',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The campaign page component for TrueView Tuesday of coins.
 */
function fillPage(string $slug = 'trueview-tuesday'): mixed
{
    return Livewire::test(CampaignPage::class, ['project' => Project::where('name', 'coins')->firstOrFail(), 'campaign' => $slug]);
}

/**
 * The kept posts of `$campaign` on `$date` (UTC, as these campaigns are), oldest first.
 *
 * @return Collection<int, MarketingPost>
 */
function postsOn(MarketingCampaign $campaign, string $date): Collection
{
    return $campaign->posts()->whereDate('scheduled_for', $date)->where('status', '!=', MarketingPost::STATUS_DISCARDED)->orderBy('id')->get();
}

/**
 * The text of the last prompt sent to fake Claude: the system block and the user message, joined.
 */
function sentPrompt(): string
{
    $sent = Http::recorded()->last()[0];

    return $sent['system'][0]['text']."\n".$sent['messages'][0]['content'];
}

it('Given an open date and a faked Claude response, when I Fill it, then 3 draft posts exist for that date with captions, `source=ai` and `generation_meta`', function () {
    fakeClaude();

    fillPage()->call('fillDate', '2026-10-27')->assertHasNoErrors();

    $posts = postsOn($this->tvt, '2026-10-27');
    expect($posts)->toHaveCount(3)
        ->and($posts->pluck('caption')->all())->toBe(["Rainbow toning on a Morgan.\nShow us your best toner.", 'Album toning, straight from the paper.', 'Rate this rainbow from one to ten.'])
        ->and($posts->pluck('status')->unique()->all())->toBe([MarketingPost::STATUS_DRAFT])
        ->and($posts->pluck('source')->unique()->all())->toBe(['ai'])
        ->and($posts->pluck('version')->unique()->all())->toBe([1])
        ->and($posts->first()->scheduled_for->toDateTimeString())->toBe('2026-10-27 19:00:00');

    $meta = $posts->first()->generation_meta;
    expect($meta['model'])->toBe('claude-sonnet-5-5')
        ->and($meta['input_tokens'])->toBe(1000)
        ->and($meta['output_tokens'])->toBe(200)
        // 1000 × $2 + 200 × $10 + 400 cache-write × $2.50 per million tokens = 5000 micro-USD.
        ->and($meta['cost_micro_usd'])->toBe(5000)
        ->and($meta['prompt_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($meta['duration_ms'])->toBeInt()
        ->and($meta['drafts_in_call'])->toBe(3);

    // The fixed part (voice and copy rules) is the cached system block; the key goes in a header, never the body.
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
        && $r->header('x-api-key') === ['test-key-not-real']
        && $r['model'] === 'claude-sonnet-5-5'
        && $r['system'][0]['cache_control'] === ['type' => 'ephemeral']
        && str_contains($r['system'][0]['text'], 'Sets are the centrepiece.')
        && ! str_contains($r->body(), 'test-key-not-real'));
    Http::assertSentCount(1);
});

it('Given the prompt is built, then it contains the brand voice, copy rules, brief and own topics, and contains no instruction to change colours, fonts or layout (assert on the built prompt)', function () {
    $prompt = app(MarketingPrompts::class)->draft($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));
    $text = $prompt['system']."\n".$prompt['user'];

    expect($text)->toContain('Short and human, one or two lines.')
        ->toContain('Sets are the centrepiece.')
        ->toContain('tell us below')
        ->toContain('cert lookup')
        ->toContain('Show off the toning. One line, then ask people to share theirs.')
        ->toContain('Album toning')
        ->toContain('the rainbow came from the paper')
        ->toContain('Show us your best toner.')
        // The brand's look is fixed: nothing about it reaches the model, not even the values.
        ->not->toMatch('/colou?r|font|typeface|layout|design|#[0-9A-F]{6}/i')
        ->not->toContain('Instrument Sans')
        // An idea with unfilled placeholders waits for its data source (phase 3).
        ->not->toContain('[coin]');
    expect($prompt['skipped_ideas'])->toBe(['Sets|Slot filled']);
});

it('Given 12 likes and 7 dislikes, then the prompt includes exactly the latest 10 liked and 5 disliked examples', function () {
    foreach (range(1, 12) as $i) {
        $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-09-01 19:00')->approved()->create(['caption' => sprintf('Liked caption %02d', $i)]);
        MarketingFeedback::factory()->on($post)->liked()->create(['created_at' => now()->subDays(40 - $i)]);
    }
    foreach (range(1, 7) as $i) {
        $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-09-02 19:00')->discarded()->create(['caption' => sprintf('Disliked caption %02d', $i)]);
        MarketingFeedback::factory()->on($post)->disliked(['Too salesy'])->create(['created_at' => now()->subDays(20 - $i)]);
    }

    $prompt = app(MarketingPrompts::class)->draft($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'))['user'];

    foreach (range(3, 12) as $i) {
        expect($prompt)->toContain(sprintf('Liked caption %02d', $i));
    }
    foreach (range(3, 7) as $i) {
        expect($prompt)->toContain(sprintf('Disliked caption %02d', $i));
    }
    expect($prompt)->not->toContain('Liked caption 01')->not->toContain('Liked caption 02')
        ->not->toContain('Disliked caption 01')->not->toContain('Disliked caption 02')
        ->and(substr_count($prompt, 'Liked caption'))->toBe(10)
        ->and(substr_count($prompt, 'Disliked caption'))->toBe(5)
        ->and($prompt)->toContain('Too salesy');
});

it('Given photo mode `per_post`, then drafts have no asset; given `favourites` and a returned id from another project, then the id is ignored and `marketing.ai.invalid_asset` is logged', function () {
    $mine = MarketingAsset::factory()->for($this->coins)->create(['original_name' => 'rainbow-morgan.jpg']);
    $theirs = MarketingAsset::factory()->create();
    fakeClaude(['drafts' => [
        ['caption' => 'One.', 'asset_id' => $mine->id],
        ['caption' => 'Two.', 'asset_id' => $theirs->id],
        ['caption' => 'Three.', 'asset_id' => null],
    ]]);

    // per_post: the owner adds the photo, whatever the model says (and that is not an invalid pick: nothing was offered).
    Log::spy();
    app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-20 19:00'));
    expect(postsOn($this->tvt, '2026-10-20')->pluck('asset_id')->all())->toBe([null, null, null]);

    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_FAVOURITES]);
    app(DraftOccurrence::class)->handle($this->tvt->fresh(), CarbonImmutable::parse('2026-10-27 19:00'));

    expect(postsOn($this->tvt, '2026-10-27')->pluck('asset_id')->all())->toBe([$mine->id, null, null])
        ->and(sentPrompt())->toContain("id {$mine->id}: rainbow-morgan.jpg")
        ->not->toContain("id {$theirs->id}:");
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.invalid_asset'
        && $context['campaign_id'] === $this->tvt->id && $context['asset_id'] === $theirs->id)->once();
});

it('Given a draft and feedback "Shorter. Mention album toning", when revised, then a new version exists, v1 is kept, and the feedback row is stored', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['caption' => "A long first caption about toning.\nWith a second line."]);
    fakeClaude(['caption' => 'Album toning: the rainbow came from the paper.', 'asset_id' => null]);

    $revised = app(ReviseDraft::class)->handle($post, 'Shorter. Mention album toning', ['Shorter']);

    expect($revised->caption)->toBe('Album toning: the rainbow came from the paper.')
        ->and($revised->version)->toBe(2)
        ->and($revised->fresh()->version)->toBe(2)
        ->and($revised->generation_meta['call'])->toBe('revise');
    $v1 = $post->versions()->sole();
    expect($v1->version)->toBe(1)
        ->and($v1->caption)->toBe("A long first caption about toning.\nWith a second line.")
        ->and($v1->feedback)->toBe('Shorter. Mention album toning')
        ->and($v1->chips)->toBe(['Shorter']);
    $feedback = MarketingFeedback::sole();
    expect($feedback->post_id)->toBe($post->id)
        ->and($feedback->campaign_id)->toBe($this->tvt->id)
        ->and($feedback->project_id)->toBe($this->coins->id)
        ->and($feedback->vote)->toBeNull()
        ->and($feedback->note)->toBe('Shorter. Mention album toning')
        ->and($feedback->reasons)->toBe(['Shorter'])
        ->and(sentPrompt())->toContain('A long first caption about toning.')->toContain('Shorter. Mention album toning');
});

it('Given a caption "Tell us below, we read every one" then the lint warns `filler`; "type a cert and we pull the coin" warns `banned_claim`; three lines warns `too_long`; a clean two-liner has no warnings', function () {
    $lint = fn (string $caption) => collect((new LintCaption)->handle($caption, SeedMarketingKit::COPY_RULES))->pluck('code')->unique()->values()->all();

    expect($lint('Tell us below, we read every one'))->toBe(['filler'])
        ->and($lint('type a cert and we pull the coin'))->toBe(['banned_claim'])
        ->and($lint("One.\nTwo.\nThree."))->toBe(['too_long'])
        ->and($lint("Rainbow toning on a Morgan.\nShow us your best toner."))->toBe([]);
});

it('Given Claude returns an error or times out, then no drafts are created, the date shows "Drafting failed · Retry", and `marketing.ai.draft.failed` is logged with status and duration (never the API key or full prompt)', function (string $failure, ?int $status) {
    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fake(['api.anthropic.com/*' => $failure === 'timeout'
        ? Http::failedConnection()
        : Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);
    Log::spy();

    $page = fillPage()->call('fillDate', '2026-10-27');

    expect(postsOn($this->tvt, '2026-10-27'))->toHaveCount(0);
    $page->assertSeeHtml('data-fill-failed="2026-10-27"')->assertSee('Drafting failed')->assertSee('Retry');
    Log::shouldHaveReceived('error')->withArgs(function ($event, $context = []) use ($status) {
        $flat = json_encode($context);

        return $event === 'marketing.ai.draft.failed'
            && $context['campaign_id'] === $this->tvt->id
            && $context['status'] === $status
            && is_int($context['duration_ms'])
            && ! str_contains($flat, 'test-key-not-real')
            && ! str_contains($flat, 'Show off the toning');
    })->once();
})->with([
    'an error' => ['error', 529],
    'a timeout' => ['timeout', null],
]);

it('Given no `ANTHROPIC_API_KEY`, then Fill is disabled with "Add ANTHROPIC_API_KEY to .env"', function () {
    config(['services.anthropic.key' => null]);
    Http::fake();
    Log::spy();

    $page = fillPage()->assertSee('Add ANTHROPIC_API_KEY to .env');
    expect($page->html())->toMatch('/<button[^>]*data-fill="2026-10-27"[^>]*disabled/');

    // The guard holds even if the button is clicked anyway.
    $page->call('fillDate', '2026-10-27');
    expect(postsOn($this->tvt, '2026-10-27'))->toHaveCount(0);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('marketing.ai.fill.refused', ['campaign_id' => $this->tvt->id, 'reason' => 'not_configured', 'date' => '2026-10-27'])->once();
});

it('Given "Fill 3 open dates", then 3 jobs are queued and each date fills independently', function () {
    $both = MarketingCampaign::factory()->for($this->coins)->named('Twice a week')->on([2, 4], '19:00')->create(['brief' => 'Twice.']);
    // Within two weeks of Mon 12 Oct: Tue 13 (approved), Thu 15, Tue 20, Thu 22 open.
    MarketingPost::factory()->forCampaign($both, '2026-10-13 19:00')->approved()->create();
    config(['services.anthropic.key' => 'test-key-not-real']);
    Queue::fake();

    fillPage('twice-a-week')->assertSee('Fill 3 open dates')->call('fillOpen')->assertSee('Drafting…');

    Queue::assertPushed(DraftOccurrenceJob::class, 3);
    $jobs = Queue::pushed(DraftOccurrenceJob::class)->sortBy('at')->values();
    expect($jobs->pluck('at')->all())->toBe(['2026-10-15T19:00:00+00:00', '2026-10-20T19:00:00+00:00', '2026-10-22T19:00:00+00:00']);

    // The middle date fails; the others still fill.
    Http::fakeSequence('api.anthropic.com/*')
        ->push(claudeReply(['drafts' => [['caption' => 'A.', 'asset_id' => null], ['caption' => 'B.', 'asset_id' => null], ['caption' => 'C.', 'asset_id' => null]]]))
        ->push(['type' => 'error', 'error' => ['type' => 'api_error']], 500)
        ->push(claudeReply(['drafts' => [['caption' => 'D.', 'asset_id' => null], ['caption' => 'E.', 'asset_id' => null], ['caption' => 'F.', 'asset_id' => null]]]));
    $jobs->each(fn (DraftOccurrenceJob $job) => app()->call([$job, 'handle']));

    expect(postsOn($both, '2026-10-15'))->toHaveCount(3)
        ->and(postsOn($both, '2026-10-20'))->toHaveCount(0)
        ->and(postsOn($both, '2026-10-22'))->toHaveCount(3);
    fillPage('twice-a-week')->assertSeeHtml('data-fill-failed="2026-10-20"')->assertDontSeeHtml('data-fill-failed="2026-10-22"');
});

// ---- Guards (codebase-standards §Testing: every guard gets a test and a log line) ----

it('refuses to fill a date that already has posts, or one not on the campaign page, and logs why', function () {
    fakeClaude();
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create();
    Log::spy();

    fillPage()->call('fillDate', '2026-10-27')->call('fillDate', '2027-06-01')->call('fillDate', 'not-a-date');

    expect(postsOn($this->tvt, '2026-10-27'))->toHaveCount(1);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('marketing.ai.fill.refused', ['campaign_id' => $this->tvt->id, 'reason' => 'not_open', 'date' => '2026-10-27'])->once();
    Log::shouldHaveReceived('info')->with('marketing.ai.fill.refused', ['campaign_id' => $this->tvt->id, 'reason' => 'not_open', 'date' => '2027-06-01'])->once();
    Log::shouldHaveReceived('info')->with('marketing.ai.fill.refused', ['campaign_id' => $this->tvt->id, 'reason' => 'not_open', 'date' => 'not-a-date'])->once();
});

it('refuses to fill a paused campaign and hides its Fill buttons', function () {
    fakeClaude();
    $this->tvt->update(['is_enabled' => false]);
    Log::spy();

    $page = fillPage()->assertDontSeeHtml('data-fill-open')->assertDontSeeHtml('data-fill="2026-10-27"');
    $page->call('fillOpen');

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('marketing.ai.fill.refused', ['campaign_id' => $this->tvt->id, 'reason' => 'paused', 'date' => null])->once();
});

it('treats a reply with fewer than 3 captions, or a refusal, as a failed draft and creates nothing', function (array $reply, string $reason) {
    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fake(['api.anthropic.com/*' => Http::response($reply)]);
    Log::spy();

    expect(fn () => app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00')))
        ->toThrow(MarketingAiException::class);
    expect(postsOn($this->tvt, '2026-10-27'))->toHaveCount(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.draft.failed' && $context['reason'] === $reason)->once();
})->with([
    'two captions' => [claudeReply(['drafts' => [['caption' => 'A.', 'asset_id' => null], ['caption' => ' ', 'asset_id' => null], ['caption' => 'B.', 'asset_id' => null]]]), 'invalid_reply'],
    'not JSON' => [['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'Sure! Here you go']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 'invalid_reply'],
    'a refusal' => [claudeReply(['drafts' => [['caption' => 'A.', 'asset_id' => null], ['caption' => 'B.', 'asset_id' => null], ['caption' => 'C.', 'asset_id' => null]]], 'refusal'), 'refusal'],
    'cut off' => [claudeReply(['drafts' => []], 'max_tokens'), 'truncated'],
]);

it('rotates topics so the ones used most recently are not repeated', function () {
    $this->tvt->update(['topics' => ['ideas' => [], 'own' => [
        ['text' => 'Topic A', 'detail' => null], ['text' => 'Topic B', 'detail' => null],
        ['text' => 'Topic C', 'detail' => null], ['text' => 'Topic D', 'detail' => null],
    ]]]);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['generation_meta' => ['topic_key' => 'own:Topic A']]);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['generation_meta' => ['topic_key' => 'own:Topic B']]);

    $topics = app(MarketingPrompts::class)->draft($this->tvt->fresh(), CarbonImmutable::parse('2026-10-27 19:00'))['topics'];

    // C and D were never used; then A, the least recently used of the two that were.
    expect(array_column($topics, 'key'))->toBe(['own:Topic C', 'own:Topic D', 'own:Topic A']);
});

it('takes taste examples from the campaign first, then the rest of the project', function () {
    $other = MarketingCampaign::factory()->for($this->coins)->create();
    foreach (range(1, 8) as $i) {
        $post = MarketingPost::factory()->forCampaign($other, '2026-09-01 19:00')->create(['caption' => "Other campaign {$i}"]);
        MarketingFeedback::factory()->on($post)->liked()->create(['created_at' => now()->subDays(1)]);
    }
    foreach (range(1, 4) as $i) {
        $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-09-01 19:00')->create(['caption' => "This campaign {$i}"]);
        MarketingFeedback::factory()->on($post)->liked()->create(['created_at' => now()->subDays(30)]);
    }
    MarketingFeedback::factory()->on(MarketingPost::factory()->forCampaign($this->tvt)->create())->create(['note' => 'More about the set please']);

    $prompt = app(MarketingPrompts::class)->draft($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'))['user'];

    // All 4 of this campaign's, although older, then the project's 6 latest.
    expect(substr_count($prompt, 'This campaign'))->toBe(4)
        ->and(substr_count($prompt, 'Other campaign'))->toBe(6)
        ->and($prompt)->toContain('More about the set please');
});

it('regenerates a date: discards its drafts for 3 new ones, and keeps them when drafting fails', function () {
    $old = MarketingPost::factory()->count(3)->forCampaign($this->tvt, '2026-10-27 19:00')->create();
    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fakeSequence('api.anthropic.com/*')
        ->push(['type' => 'error', 'error' => ['type' => 'api_error']], 500)
        ->push(claudeReply(['drafts' => [['caption' => 'New A.', 'asset_id' => null], ['caption' => 'New B.', 'asset_id' => null], ['caption' => 'New C.', 'asset_id' => null]]]));

    expect(fn () => app(RegenerateOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00')))->toThrow(MarketingAiException::class);
    expect(postsOn($this->tvt, '2026-10-27')->pluck('id')->all())->toBe($old->pluck('id')->all());

    app(RegenerateOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));

    expect(postsOn($this->tvt, '2026-10-27'))->toHaveCount(3)
        ->and(postsOn($this->tvt, '2026-10-27')->pluck('id')->intersect($old->pluck('id'))->all())->toBe([])
        ->and($old->first()->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED);
});

it('refuses to regenerate a date that has an approved post', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->approved()->create();
    fakeClaude();
    Log::spy();

    expect(fn () => app(RegenerateOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00')))
        ->toThrow(MarketingDraftRefusedException::class);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('marketing.ai.draft.refused', ['campaign_id' => $this->tvt->id, 'reason' => 'not_open', 'date' => '2026-10-27'])->once();
});

it('refuses to revise a post that is not a draft, with no feedback, or with an unknown chip, and logs why', function (string $status, string $feedback, array $chips, string $reason) {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['status' => $status, 'caption' => 'Original.']);
    fakeClaude(['caption' => 'New.', 'asset_id' => null]);
    Log::spy();

    expect(fn () => app(ReviseDraft::class)->handle($post, $feedback, $chips))->toThrow(MarketingDraftRefusedException::class);
    expect($post->fresh()->caption)->toBe('Original.')->and(MarketingFeedback::count())->toBe(0);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('marketing.ai.revise.refused', ['post_id' => $post->id, 'reason' => $reason])->once();
})->with([
    'approved' => [MarketingPost::STATUS_APPROVED, 'Shorter', [], 'not_draft'],
    'no feedback' => [MarketingPost::STATUS_DRAFT, '  ', [], 'empty_feedback'],
    'unknown chip' => [MarketingPost::STATUS_DRAFT, '', ['Louder'], 'unknown_chip'],
]);

it('logs a failed revision and leaves the draft and its history untouched', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['caption' => 'Original.']);
    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fake(['api.anthropic.com/*' => Http::failedConnection()]);
    Log::spy();

    expect(fn () => app(ReviseDraft::class)->handle($post, 'Warmer', ['Warmer']))->toThrow(MarketingAiException::class);
    expect($post->fresh()->caption)->toBe('Original.')->and($post->versions()->count())->toBe(0)->and(MarketingFeedback::count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.revise.failed' && $context['post_id'] === $post->id && $context['reason'] === 'timeout')->once();
});

it('saves lint warnings with each draft and logs them at debug', function () {
    fakeClaude(['drafts' => [
        ['caption' => 'Tell us below!', 'asset_id' => null],
        ['caption' => 'Clean.', 'asset_id' => null],
        ['caption' => 'Also clean.', 'asset_id' => null],
    ]]);
    Log::spy();

    app(DraftOccurrence::class)->handle($this->tvt, CarbonImmutable::parse('2026-10-27 19:00'));

    $posts = postsOn($this->tvt, '2026-10-27');
    expect($posts[0]->lint)->toBe([['code' => 'filler', 'match' => 'tell us below']])
        ->and($posts[1]->lint)->toBe([]);
    Log::shouldHaveReceived('debug')->with('marketing.lint.warned', ['campaign_id' => $this->tvt->id, 'post_id' => $posts[0]->id, 'codes' => ['filler']])->once();
});

it('warns on a comparison that gives no price source', function () {
    $lint = fn (string $caption) => collect((new LintCaption)->handle($caption, SeedMarketingKit::COPY_RULES))->pluck('code')->all();

    expect($lint('This Morgan is worth more than that Peace dollar.'))->toBe(['no_price_source'])
        ->and($lint('This Morgan sold for more than that Peace dollar (Greysheet, Oct).'))->toBe([]);
});

it('on "Different photo" offers the other favourites, keeps a valid pick and drops (and logs) any other id', function () {
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_FAVOURITES]);
    [$current, $other] = MarketingAsset::factory()->count(2)->for($this->coins)->create();
    $theirs = MarketingAsset::factory()->create();
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-27 19:00')->create(['asset_id' => $current->id]);

    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fakeSequence('api.anthropic.com/*')
        ->push(claudeReply(['caption' => 'New photo, same words.', 'asset_id' => $other->id]))
        ->push(claudeReply(['caption' => 'Again.', 'asset_id' => $theirs->id]));

    app(ReviseDraft::class)->handle($post, '', ['Different photo']);
    expect($post->fresh()->asset_id)->toBe($other->id)
        ->and(sentPrompt())->toContain("id {$other->id}:")->not->toContain("id {$current->id}:")->not->toContain("id {$theirs->id}:");

    // An id from another project is dropped and the post keeps its photo.
    Log::spy();
    app(ReviseDraft::class)->handle($post->fresh(), '', ['Different photo']);
    expect($post->fresh()->asset_id)->toBe($other->id)->and($post->fresh()->version)->toBe(3);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'marketing.ai.invalid_asset' && $context['asset_id'] === $theirs->id)->once();
});

it('marks the date failed and rethrows when a draft job crashes unexpectedly', function () {
    config(['services.anthropic.key' => 'test-key-not-real']);
    Http::fake(fn () => throw new RuntimeException('database went away'));
    Log::spy();
    $job = new DraftOccurrenceJob($this->tvt, '2026-10-27T19:00:00+00:00', '2026-10-27');

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'database went away');
    fillPage()->assertSeeHtml('data-fill-failed="2026-10-27"');
    Log::shouldHaveReceived('error')->with('marketing.ai.draft.failed', ['campaign_id' => $this->tvt->id, 'date' => '2026-10-27', 'reason' => 'exception', 'exception' => 'database went away'])->once();
});
