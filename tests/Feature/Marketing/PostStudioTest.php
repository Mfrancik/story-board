<?php

use App\Actions\Marketing\ApprovePost;
use App\Actions\Marketing\BuildCampaignRunway;
use App\Actions\Marketing\CreateOneOffPost;
use App\Actions\Marketing\EditPostCaption;
use App\Actions\Marketing\MarkPostPosted;
use App\Actions\Marketing\RecordFeedback;
use App\Actions\Marketing\ReschedulePost;
use App\Actions\Marketing\RestoreOccurrence;
use App\Actions\Marketing\SeedMarketingKit;
use App\Actions\Marketing\SetPostPhoto;
use App\Actions\Marketing\SkipOccurrence;
use App\Actions\Marketing\UnapprovePost;
use App\Actions\Marketing\UndoRevision;
use App\Exceptions\MarketingPostRefusedException;
use App\Jobs\RefreshProjectJob;
use App\Livewire\Marketing\CampaignList;
use App\Livewire\Marketing\CampaignPage;
use App\Livewire\Marketing\PostStudio;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Models\MarketingPostNetwork;
use App\Models\MarketingPostVersion;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * MK-9 acceptance criteria: the post studio. One date's drafts big, flipped, revised (ReviseDraft, Claude faked),
 * liked or disliked, given a photo, approved (discarding the others) and the approved post copied, rescheduled,
 * unapproved or marked posted. The clock is frozen at Mon 12 Oct 2026 11:00 UTC, so TrueView Tuesday (Tue 19:00 UTC,
 * photo per post) falls on Tue 13, 20 and 27 Oct.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00');
    Queue::fake([RefreshProjectJob::class]);
    Storage::fake('local');
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    MarketingBrand::factory()->for($this->coins)->create(['values' => SeedMarketingKit::BRAND, 'copy_rules' => SeedMarketingKit::COPY_RULES]);
    $this->tvt = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([2], '19:00')->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Three drafts of `$campaign` on `$at` (UTC), in candidate order.
 *
 * @return list<MarketingPost>
 */
function threeDrafts(MarketingCampaign $campaign, string $at, array $state = []): array
{
    return collect(['First draft line.', 'Second draft line.', 'Third draft line.'])
        ->map(fn (string $caption) => MarketingPost::factory()->forCampaign($campaign, $at)->create(['caption' => $caption, ...$state]))
        ->all();
}

/**
 * The studio component for coins, opened on `$campaign`'s `$date`.
 */
function studio(?MarketingCampaign $campaign = null, ?string $date = null, ?int $post = null): mixed
{
    return Livewire::test(PostStudio::class, ['project' => 'coins'])
        ->call('open', $campaign?->id, $date, $post);
}

it('Given a date with 3 drafts, when I open the studio, then the first draft shows big and ←/→ flips to the others', function () {
    // Text only, so the big preview is the brand text card with the caption as its headline.
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    [$one, $two, $three] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')
        ->assertSet('isOpen', true)
        ->assertSeeHtml('data-studio-state="review"')
        ->assertSeeHtml('data-draft-count="3"')
        ->assertSeeHtmlInOrder(['data-draft="'.$one->id.'"', 'data-draft="'.$two->id.'"', 'data-draft="'.$three->id.'"'])
        // Flipping is Alpine (no round trip): the arrows, the dots and the arrow keys move `cur`, which starts on 0.
        ->assertSeeHtml('cur: 0,')
        ->assertSeeHtml('x-show="cur === 1"')
        ->assertSeeHtml('aria-label="Next draft"')
        ->assertSeeHtml('aria-label="Previous draft"')
        ->assertSeeHtml('data-post-preview="'.$one->id.'"')
        ->assertSee('First draft line.')
        ->assertSee('TrueView Tuesday')
        ->assertSee('Tue 13 Oct · 19:00');
});

it('Given I type a change and submit, then ReviseDraft runs (faked), the draft shows v2, and Undo restores v1', function () {
    fakeClaude(['caption' => 'Shorter: rainbow toning.', 'asset_id' => null]);
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    $page = studio($this->tvt, '2026-10-13')
        ->call('revise', $one->id, 'Make it shorter', ['Shorter'])
        ->assertHasNoErrors()
        ->assertSee('Shorter: rainbow toning.')
        ->assertSeeHtml('data-version="'.$one->id.'"')
        ->assertSee('v2');
    Http::assertSentCount(1);
    expect($one->fresh()->version)->toBe(2)->and($one->fresh()->caption)->toBe('Shorter: rainbow toning.');

    // The caption box (wire:model) shows the restored caption.
    $page->call('undo', $one->id)->assertSet('captions.'.$one->id, 'First draft line.')->assertDontSeeHtml('data-version="'.$one->id.'"');
    expect($one->fresh())->version->toBe(1)->caption->toBe('First draft line.')
        ->and(MarketingPostVersion::where('post_id', $one->id)->count())->toBe(0);
});

it('Given I dislike with reason "too wordy", then a feedback row with vote -1 and that reason exists', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')
        ->call('vote', $one->id, -1)
        ->call('vote', $one->id, -1, ['too wordy']);

    expect(MarketingFeedback::where('post_id', $one->id)->whereNotNull('vote')->get())
        ->toHaveCount(1)
        ->first()->vote->toBe(-1)
        ->first()->reasons->toBe(['too wordy']);
});

it('Given photo mode per_post and no photo, then Approve is disabled with "Needs a photo"; after an upload it enables', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    $page = studio($this->tvt, '2026-10-13')
        ->assertSee('Needs a photo')
        ->assertSeeHtml('data-needs-photo="'.$one->id.'"')
        ->assertSeeHtml('data-approve="'.$one->id.'" disabled');

    $page->set('uploads.'.$one->id, UploadedFile::fake()->image('new-coin.jpg', 40, 40))
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-needs-photo="'.$one->id.'"')
        ->assertDontSeeHtml('data-approve="'.$one->id.'" disabled');

    $asset = MarketingAsset::findOrFail($one->fresh()->asset_id);
    expect($asset->is_favourite)->toBeFalse()->and($asset->project_id)->toBe($this->coins->id);
    Storage::disk('local')->assertExists($asset->path);
});

it('Given I upload a 20 MB file, then it is rejected and nothing changes', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')
        ->set('uploads.'.$one->id, UploadedFile::fake()->image('huge.jpg', 10, 10)->size(20 * 1024))
        ->assertHasErrors(['uploads.'.$one->id => 'Photos can be 10 MB at most.'])
        ->assertSeeHtml('data-needs-photo="'.$one->id.'"');

    expect($one->fresh()->asset_id)->toBeNull()->and(MarketingAsset::count())->toBe(0);
});

it('rejects a PDF as the post photo', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')
        ->set('uploads.'.$one->id, UploadedFile::fake()->create('menu.pdf', 50, 'application/pdf'))
        ->assertHasErrors(['uploads.'.$one->id => 'Photos must be JPG, PNG or WebP images.']);

    expect(MarketingAsset::count())->toBe(0);
});

it('Given I approve draft 2, then it is approved on that date, drafts 1 and 3 are discarded, and the campaign\'s approved-through advances', function () {
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    [$one, $two, $three] = threeDrafts($this->tvt, '2026-10-13 19:00');
    $runway = fn () => app(BuildCampaignRunway::class)->handle($this->tvt->fresh(), now());
    expect($runway()['approved_through'])->toBeNull();

    studio($this->tvt, '2026-10-13')->call('approve', $two->id)->assertDispatched('marketing-changed');

    expect($two->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)
        ->and($two->fresh()->scheduled_for->toDateTimeString())->toBe('2026-10-13 19:00:00')
        ->and($one->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and($three->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and($runway()['approved_through']->toDateString())->toBe('2026-10-13');
});

it('Given 3 dates to approve, when I Approve & next, then the studio moves to the next date and the counter drops', function () {
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    [$first] = threeDrafts($this->tvt, '2026-10-13 19:00');
    threeDrafts($this->tvt, '2026-10-20 19:00');
    threeDrafts($this->tvt, '2026-10-27 19:00');

    studio($this->tvt)
        ->assertSet('date', '2026-10-13')
        ->assertSeeHtml('data-studio-counter')
        ->assertSee('1 of 3 to approve')
        ->assertSee('Approve & next (2 left)')
        ->call('approve', $first->id, true)
        ->assertSet('date', '2026-10-20')
        ->assertSee('1 of 2 to approve')
        ->assertSee('Approve & next (1 left)');
});

it('Given an approved post, when I Mark as posted with a link, then status is posted, posted_at is set, and a facebook network row stores the link; an invalid URL is rejected', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();
    $link = 'https://www.facebook.com/StackerMint/posts/1234567890';

    $page = studio($this->tvt, '2026-10-13')
        ->call('markPosted', $post->id, 'not a link')
        ->assertHasErrors(['postLink']);
    expect($post->fresh()->status)->toBe(MarketingPost::STATUS_APPROVED)->and(MarketingPostNetwork::count())->toBe(0);

    $page->call('markPosted', $post->id, $link)->assertHasNoErrors();
    $post->refresh();
    expect($post->status)->toBe(MarketingPost::STATUS_POSTED)
        ->and($post->posted_at?->toDateTimeString())->toBe('2026-10-12 11:00:00')
        ->and($post->networks()->sole())
        ->network->toBe(MarketingPostNetwork::FACEBOOK)
        ->status->toBe(MarketingPostNetwork::STATUS_POSTED)
        ->url->toBe($link);
});

it('marks a post posted with no link and writes no network row', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();

    studio($this->tvt, '2026-10-13')->call('markPosted', $post->id, '')->assertHasNoErrors();

    expect($post->fresh()->status)->toBe(MarketingPost::STATUS_POSTED)->and(MarketingPostNetwork::count())->toBe(0);
});

it('Given an approved post, when I Reschedule into the past, then it is refused', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();

    studio($this->tvt, '2026-10-13')
        ->call('reschedule', $post->id, '2026-10-11T19:00')
        ->assertHasErrors(['rescheduleAt' => 'That time has passed. Pick a later one.']);

    expect($post->fresh()->scheduled_for->toDateTimeString())->toBe('2026-10-13 19:00:00');
    expect(fn () => app(ReschedulePost::class)->handle($post->fresh(), CarbonImmutable::parse('2026-10-12 10:00'), now()))
        ->toThrow(MarketingPostRefusedException::class);
});

it('reschedules an approved post to a later time in the campaign\'s timezone and follows it', function () {
    $this->tvt->update(['timezone' => 'America/New_York']);
    $post = MarketingPost::factory()->forCampaign($this->tvt->fresh(), '2026-10-13 19:00')->approved()->create();

    studio($this->tvt, '2026-10-13')
        ->call('reschedule', $post->id, '2026-10-15T18:30')
        ->assertHasNoErrors()
        ->assertSet('date', '2026-10-15');

    // 18:30 in New York (EDT, UTC-4) is 22:30 UTC.
    expect($post->fresh()->scheduled_for->toDateTimeString())->toBe('2026-10-15 22:30:00');
});

it('logs the post events with post, campaign and version', function () {
    Log::spy();
    $this->tvt->update(['photo_mode' => MarketingCampaign::PHOTO_TEXT_ONLY]);
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')
        ->call('vote', $one->id, 1)
        ->call('approve', $one->id)
        ->call('reschedule', $one->id, '2026-10-14T19:00')
        ->call('unapprove', $one->id);
    app(ApprovePost::class)->handle($one->fresh());
    app(MarkPostPosted::class)->handle($one->fresh(), null, now());
    app(SkipOccurrence::class)->handle($this->tvt, '2026-10-20');

    $context = fn (string $event) => fn ($e, $c) => $e === $event && $c['post_id'] === $one->id && $c['campaign_id'] === $this->tvt->id && array_key_exists('version', $c);
    foreach (['marketing.feedback.recorded', 'marketing.post.approved', 'marketing.post.rescheduled', 'marketing.post.unapproved', 'marketing.post.posted'] as $event) {
        Log::shouldHaveReceived('info')->withArgs($context($event))->atLeast()->once();
    }
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'marketing.post.skipped' && $c['campaign_id'] === $this->tvt->id && $c['date'] === '2026-10-20')->once();
});

// --- the rest of the studio ---

it('liking, then disliking, keeps one vote per post; voting 0 clears it', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');
    MarketingFeedback::factory()->on($one)->create(['note' => 'a written note']);

    $page = studio($this->tvt, '2026-10-13')->call('vote', $one->id, 1)->call('vote', $one->id, -1, ['boring']);
    expect(MarketingFeedback::where('post_id', $one->id)->whereNotNull('vote')->pluck('vote')->all())->toBe([-1]);

    $page->call('vote', $one->id, 0);
    expect(MarketingFeedback::where('post_id', $one->id)->whereNotNull('vote')->count())->toBe(0)
        // A written note (from Revise) is taste too, and a vote never removes it.
        ->and(MarketingFeedback::where('post_id', $one->id)->whereNull('vote')->count())->toBe(1);
});

it('refuses an unknown dislike reason and a reason on a like', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    expect(fn () => app(RecordFeedback::class)->handle($one, -1, ['too salesy']))->toThrow(MarketingPostRefusedException::class)
        ->and(fn () => app(RecordFeedback::class)->handle($one, 1, ['boring']))->toThrow(MarketingPostRefusedException::class)
        ->and(fn () => app(RecordFeedback::class)->handle($one, 2))->toThrow(MarketingPostRefusedException::class);
    expect(MarketingFeedback::count())->toBe(0);
});

it('edits a caption in place and re-lints it, warning only', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')
        ->set('captions.'.$one->id, "Line one.\nLine two.\nLine three.")
        ->assertHasNoErrors()
        ->assertSee('More than 2 lines');

    expect($one->fresh()->caption)->toBe("Line one.\nLine two.\nLine three.")
        ->and(array_column($one->fresh()->lint, 'code'))->toContain('too_long');
});

it('refuses an empty caption', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')->set('captions.'.$one->id, '   ')->assertHasErrors(['captions.'.$one->id]);

    expect($one->fresh()->caption)->toBe('First draft line.');
});

it('uses a favourite, or text only, and either lifts the photo hold', function () {
    [$one, $two] = threeDrafts($this->tvt, '2026-10-13 19:00');
    $fav = MarketingAsset::factory()->for($this->coins)->create();

    studio($this->tvt, '2026-10-13')
        ->call('useFavourite', $one->id, $fav->id)
        ->call('textOnly', $two->id)
        ->assertDontSeeHtml('data-needs-photo="'.$one->id.'"')
        ->assertDontSeeHtml('data-needs-photo="'.$two->id.'"');

    expect($one->fresh()->asset_id)->toBe($fav->id)
        ->and($two->fresh())->asset_id->toBeNull()->is_text_only->toBeTrue();
});

it('refuses another project\'s favourite', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');
    $theirs = MarketingAsset::factory()->create();

    studio($this->tvt, '2026-10-13')->call('useFavourite', $one->id, $theirs->id);

    expect($one->fresh()->asset_id)->toBeNull();
});

it('refuses to approve a draft that needs a photo', function () {
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')->call('approve', $one->id);
    expect($one->fresh()->status)->toBe(MarketingPost::STATUS_DRAFT);

    expect(fn () => app(ApprovePost::class)->handle($one->fresh()))->toThrow(MarketingPostRefusedException::class, 'Add a photo');
});

it('refuses to approve a post that is not a draft', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->posted()->create();

    expect(fn () => app(ApprovePost::class)->handle($post))->toThrow(MarketingPostRefusedException::class);
});

it('ignores a post id from another date or project', function () {
    threeDrafts($this->tvt, '2026-10-13 19:00');
    $other = MarketingPost::factory()->create(['status' => MarketingPost::STATUS_DRAFT]);

    studio($this->tvt, '2026-10-13')->call('approve', $other->id)->assertStatus(404);
});

it('unapproves an approved post back to a draft', function () {
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();

    studio($this->tvt, '2026-10-13')->call('unapprove', $post->id)->assertSeeHtml('data-studio-state="review"');

    expect($post->fresh()->status)->toBe(MarketingPost::STATUS_DRAFT);
});

it('skips a date with drafts, and restores it', function () {
    [$one, $two, $three] = threeDrafts($this->tvt, '2026-10-13 19:00');

    $page = studio($this->tvt, '2026-10-13')->call('skip')->assertSeeHtml('data-studio-state="skipped"');
    expect(collect([$one, $two, $three])->map(fn ($p) => $p->fresh()->status)->unique()->all())->toBe([MarketingPost::STATUS_SKIPPED]);

    $page->call('restore')->assertSeeHtml('data-studio-state="review"');
    expect(collect([$one, $two, $three])->map(fn ($p) => $p->fresh()->status)->unique()->all())->toBe([MarketingPost::STATUS_DRAFT]);
});

it('skips an open date with a placeholder, which Restore discards', function () {
    $page = studio($this->tvt, '2026-10-20')->assertSeeHtml('data-studio-state="open"')->call('skip')->assertSeeHtml('data-studio-state="skipped"');

    $skip = MarketingPost::sole();
    expect($skip)->status->toBe(MarketingPost::STATUS_SKIPPED)->caption->toBe('')
        ->and($skip->scheduled_for->toDateTimeString())->toBe('2026-10-20 19:00:00');

    $page->call('restore')->assertSeeHtml('data-studio-state="open"');
    expect($skip->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED);
});

it('refuses to skip a date that has an approved post', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create();

    expect(fn () => app(SkipOccurrence::class)->handle($this->tvt, '2026-10-13'))->toThrow(MarketingPostRefusedException::class);
});

it('regenerates all 3 drafts with Claude (faked)', function () {
    fakeClaude();
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    $page = studio($this->tvt, '2026-10-13')->call('regenerate')->assertSeeHtml('data-draft-count="3"');
    expect(array_values($page->get('captions')))->toContain("Rainbow toning on a Morgan.\nShow us your best toner.");

    expect($one->fresh()->status)->toBe(MarketingPost::STATUS_DISCARDED)
        ->and($this->tvt->posts()->where('status', MarketingPost::STATUS_DRAFT)->count())->toBe(3);
});

it('shows a revise refusal and changes nothing', function () {
    fakeClaude();
    [$one] = threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')->call('revise', $one->id, '   ', [])->assertHasErrors(['feedback']);

    Http::assertNothingSent();
    expect($one->fresh()->version)->toBe(1);
});

it('creates a one-off post on a chosen date with no campaign, and refuses a past date', function () {
    $page = Livewire::test(PostStudio::class, ['project' => 'coins'])
        ->call('startOneOff')
        ->assertSeeHtml('data-studio-state="one-off"')
        ->set('oneOffAt', '2026-10-11T12:00')
        ->set('oneOffCaption', 'We are at the Baltimore show this weekend.')
        ->call('createOneOff')
        ->assertHasErrors(['oneOffAt']);
    expect(MarketingPost::count())->toBe(0);

    $page->set('oneOffAt', '2026-10-16T12:00')->call('createOneOff')->assertHasNoErrors()->assertSeeHtml('data-studio-state="review"');

    $post = MarketingPost::sole();
    expect($post)->campaign_id->toBeNull()->source->toBe('manual')->status->toBe(MarketingPost::STATUS_DRAFT)
        ->caption->toBe('We are at the Baltimore show this weekend.')
        // The owner's timezone (config board.timezone, New York): 12:00 EDT is 16:00 UTC.
        ->and($post->scheduled_for->toDateTimeString())->toBe('2026-10-16 16:00:00');
});

it('shows a missed post with Post it now and Reschedule', function () {
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-06 19:00')->approved()->create();

    studio($this->tvt, '2026-10-06')
        ->assertSeeHtml('data-studio-state="missed"')
        ->assertSee('Post it now')
        ->assertSee('Reschedule');
});

it('offers Copy caption and Download image (the original file) on an approved post', function () {
    $asset = MarketingAsset::factory()->for($this->coins)->create(['original_name' => 'morgan.jpg']);
    MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->approved()->create(['asset_id' => $asset->id]);

    studio($this->tvt, '2026-10-13')
        ->assertSeeHtml('data-copy-caption')
        ->assertSeeHtml('href="'.route('marketing.asset', ['coins', $asset->id]).'" download="morgan.jpg"')
        ->assertSee('Unapprove')
        ->assertSee('Mark as posted');
});

it('opens from the campaigns list: a campaign\'s to-approve badge and Start approving', function () {
    threeDrafts($this->tvt, '2026-10-13 19:00');

    Livewire::test(CampaignList::class, ['project' => $this->coins])
        ->assertSeeHtml('data-start-approving')
        ->assertSeeHtml('data-open-studio="'.$this->tvt->id.'"');
});

it('opens from a campaign page date', function () {
    threeDrafts($this->tvt, '2026-10-13 19:00');

    Livewire::test(CampaignPage::class, ['project' => $this->coins, 'campaign' => 'trueview-tuesday'])
        ->assertSeeHtml('data-open-studio="2026-10-13"');
});

it('Start approving with nothing to approve says so and stays closed', function () {
    Livewire::test(PostStudio::class, ['project' => 'coins'])->call('open')->assertSet('isOpen', false);
});

it('closes', function () {
    threeDrafts($this->tvt, '2026-10-13 19:00');

    studio($this->tvt, '2026-10-13')->call('close')->assertSet('isOpen', false)->assertDontSeeHtml('data-studio-state');
});

it('refuses each studio action on a post in the wrong state, and logs why', function (string $status, Closure $action, string $reason) {
    Log::spy();
    $post = MarketingPost::factory()->forCampaign($this->tvt, '2026-10-13 19:00')->create(['status' => $status, 'posted_at' => $status === MarketingPost::STATUS_POSTED ? now() : null]);

    expect(fn () => $action($post))->toThrow(MarketingPostRefusedException::class);
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'marketing.post.refused' && $c['reason'] === $reason && $c['post_id'] === $post->id)->once();
})->with([
    'unapprove a draft' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(UnapprovePost::class)->handle($p), 'not_approved'],
    'unapprove a posted post' => [MarketingPost::STATUS_POSTED, fn ($p) => app(UnapprovePost::class)->handle($p), 'not_approved'],
    'reschedule a draft' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(ReschedulePost::class)->handle($p, CarbonImmutable::parse('2026-11-01 19:00'), now()), 'not_approved'],
    'mark a draft posted' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(MarkPostPosted::class)->handle($p, null, now()), 'not_approved'],
    'mark a posted post posted again' => [MarketingPost::STATUS_POSTED, fn ($p) => app(MarkPostPosted::class)->handle($p, null, now()), 'not_approved'],
    'undo an approved post' => [MarketingPost::STATUS_APPROVED, fn ($p) => app(UndoRevision::class)->handle($p), 'not_draft'],
    'undo a draft with no earlier version' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(UndoRevision::class)->handle($p), 'no_version'],
    'edit a posted caption' => [MarketingPost::STATUS_POSTED, fn ($p) => app(EditPostCaption::class)->handle($p, 'New words.'), 'not_editable'],
    'empty a caption' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(EditPostCaption::class)->handle($p, ' '), 'empty_caption'],
    'change a posted photo' => [MarketingPost::STATUS_POSTED, fn ($p) => app(SetPostPhoto::class)->handle($p, null), 'not_editable'],
    'vote 2' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(RecordFeedback::class)->handle($p, 2), 'bad_vote'],
    'an unknown reason' => [MarketingPost::STATUS_DRAFT, fn ($p) => app(RecordFeedback::class)->handle($p, -1, ['too salesy']), 'unknown_reason'],
    'approve a skipped post' => [MarketingPost::STATUS_SKIPPED, fn ($p) => app(ApprovePost::class)->handle($p), 'not_draft'],
]);

it('refuses to restore a date that is not skipped, and a one-off with no caption', function () {
    Log::spy();

    expect(fn () => app(RestoreOccurrence::class)->handle($this->tvt, '2026-10-13'))->toThrow(MarketingPostRefusedException::class)
        ->and(fn () => app(CreateOneOffPost::class)->handle($this->coins, CarbonImmutable::parse('2026-10-20 12:00'), '  ', now()))->toThrow(MarketingPostRefusedException::class);
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'marketing.post.refused' && $c['reason'] === 'not_skipped' && $c['date'] === '2026-10-13')->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'marketing.post.refused' && $c['reason'] === 'empty_caption' && $c['action'] === 'one_off')->once();
});

it('refuses to open another project\'s campaign or post, or a malformed date, and logs why', function (Closure $open, string $reason) {
    Log::spy();
    $other = MarketingCampaign::factory()->create();
    $theirs = MarketingPost::factory()->forCampaign($other, '2026-10-13 19:00')->create();

    $open(Livewire::test(PostStudio::class, ['project' => 'coins']), $other, $theirs)->assertStatus(404);
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c) => $e === 'marketing.studio.refused' && $c['reason'] === $reason)->once();
})->with([
    'another project\'s campaign' => [fn ($t, $c) => $t->call('open', $c->id, '2026-10-13'), 'unknown_campaign'],
    'another project\'s post' => [fn ($t, $c, $p) => $t->call('open', null, null, $p->id), 'unknown_post'],
    'a malformed date' => [fn ($t) => $t->call('open', MarketingCampaign::where('slug', 'trueview-tuesday')->value('id'), '13/10/2026'), 'bad_date'],
]);
