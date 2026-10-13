<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\ApprovePost;
use App\Actions\Marketing\BuildCampaignRunway;
use App\Actions\Marketing\CreateOneOffPost;
use App\Actions\Marketing\EditPostCaption;
use App\Actions\Marketing\FillCampaign;
use App\Actions\Marketing\FindOccurrencePosts;
use App\Actions\Marketing\FindProjectAsset;
use App\Actions\Marketing\ListDatesToApprove;
use App\Actions\Marketing\MarkPostPosted;
use App\Actions\Marketing\RecordFeedback;
use App\Actions\Marketing\RegenerateOccurrence;
use App\Actions\Marketing\ReschedulePost;
use App\Actions\Marketing\RestoreOccurrence;
use App\Actions\Marketing\ReviseDraft;
use App\Actions\Marketing\SetPostPhoto;
use App\Actions\Marketing\SkipOccurrence;
use App\Actions\Marketing\StoreMarketingAsset;
use App\Actions\Marketing\UnapprovePost;
use App\Actions\Marketing\UndoRevision;
use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingAiException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Exceptions\MarketingPostRefusedException;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\MarketingAsset;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\DraftingStatus;
use App\Services\Marketing\MarketingAiClient;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The post studio (MK-9, the prototype's studio): one large modal to see a date's drafts big, flip between them,
 * tell the AI what to change, like or dislike, give a post its photo and approve it — then the approved post's
 * copy, download, reschedule, unapprove and mark-as-posted. It replaces the planned Review page.
 *
 * It sits on the campaigns list and the campaign page and opens on the `open-studio` event: a campaign and date (a
 * timeline row), a campaign alone (its first date to approve), nothing ("Start approving": the first date across
 * every campaign that is on), or a post (a one-off). Flipping, the compare toggle, the dislike reasons and Copy
 * caption are Alpine; every write goes through an Action, and the page under it is told (`marketing-changed`) to
 * re-read. The queue behind "1 of N to approve" and "Approve & next" is ListDatesToApprove, the campaigns list's
 * own count, so the two agree.
 *
 * Every post id the client sends is checked against the date the studio is on, so a forged id is a 404.
 */
class PostStudio extends Component
{
    use GuardsProjectPage;
    use WithFileUploads {
        _uploadErrored as livewireUploadErrored;
    }

    /** Whether the studio is showing. */
    public bool $isOpen = false;

    /** The campaign whose date is shown; null for a one-off. Locked: only the server may choose what is shown. */
    #[Locked]
    public ?int $campaignId = null;

    /** The date shown (Y-m-d, campaign timezone); null for a one-off. */
    #[Locked]
    public ?string $date = null;

    /** The one-off post shown, when it is one. */
    #[Locked]
    public ?int $postId = null;

    /** Whether the approval queue is every campaign that is on ("Start approving") rather than this campaign. */
    #[Locked]
    public bool $queueAll = false;

    /** The "+ One-off post" form is showing. */
    #[Locked]
    public bool $creatingOneOff = false;

    /** @var array<int, string> each shown post's caption box, by post id; saved on blur */
    public array $captions = [];

    /** @var array<int, TemporaryUploadedFile|null> "Upload a photo for this post", by post id; stored as soon as it arrives */
    public array $uploads = [];

    /** "+ One-off post": when, as a datetime-local value in the owner's timezone. */
    public string $oneOffAt = '';

    /** "+ One-off post": its caption. */
    public string $oneOffCaption = '';

    /**
     * Keep the project's name. The studio starts closed.
     */
    public function mount(string $project): void
    {
        $this->project = $project;
    }

    /**
     * Open the studio (the `open-studio` event): on `$campaign`'s `$date`, on `$campaign`'s first date to approve, on
     * one-off `$post`, or with nothing given on the first date to approve across every campaign that is on. With
     * nothing to approve it says so and stays closed.
     *
     * Side effects: a campaign or post of another project, or a malformed date, logs `marketing.studio.refused` and
     * is a 404; `marketing.studio.opened` (debug).
     */
    #[On('open-studio')]
    public function open(?int $campaign = null, ?string $date = null, ?int $post = null): void
    {
        $this->resetErrorBag();
        $this->reset('campaignId', 'date', 'postId', 'queueAll', 'creatingOneOff', 'captions', 'uploads', 'oneOffAt', 'oneOffCaption');
        $model = $this->model();

        if ($post !== null) {
            $found = $model->marketingPosts()->with('campaign')->find($post) ?? $this->refused('unknown_post', ['post_id' => $post]);
            if ($found->campaign !== null && $found->scheduled_for !== null) {
                $this->showDate($found->campaign, $found->scheduled_for->copy()->setTimezone($found->campaign->timezone)->toDateString());
            } else {
                $this->postId = $found->id;
            }
        } elseif ($campaign !== null && $date !== null) {
            if (CarbonImmutable::hasFormat($date, 'Y-m-d') === false) {
                $this->refused('bad_date', ['date' => $date]);
            }
            $this->showDate($this->campaignOf($campaign), $date);
        } else {
            $this->queueAll = $campaign === null;
            $first = $this->queue($campaign !== null ? $this->campaignOf($campaign) : null)[0] ?? null;
            if ($first === null) {
                Flux::toast(text: 'Nothing needs approval right now.');

                return;
            }
            $this->campaignId = $first['campaign_id'];
            $this->date = $first['date'];
        }

        $this->isOpen = true;
        $this->syncCaptions();
        Log::debug('marketing.studio.opened', ['project' => $this->project, 'campaign_id' => $this->campaignId, 'date' => $this->date, 'post_id' => $this->postId]);
    }

    /**
     * Close the studio (✕, Escape, the backdrop).
     */
    public function close(): void
    {
        $this->isOpen = false;
        $this->reset('creatingOneOff', 'captions', 'uploads');
    }

    /**
     * "+ One-off post" (the `start-one-off` event): open the studio on the one-off form, at noon three days out.
     */
    #[On('start-one-off')]
    public function startOneOff(): void
    {
        $this->resetErrorBag();
        $this->reset('campaignId', 'date', 'postId', 'captions', 'uploads', 'oneOffCaption');
        $this->oneOffAt = CarbonImmutable::now((string) config('board.timezone'))->addDays(3)->setTime(12, 0)->format('Y-m-d\TH:i');
        $this->creatingOneOff = true;
        $this->isOpen = true;
    }

    /**
     * "Create post" on the one-off form: a manual draft at the chosen time (owner's timezone), then the studio shows it.
     *
     * Side effects: CreateOneOffPost writes and logs; tells the page to re-read.
     */
    public function createOneOff(CreateOneOffPost $create): void
    {
        $this->validate(
            ['oneOffAt' => ['required', 'date_format:Y-m-d\TH:i'], 'oneOffCaption' => ['required', 'string', 'max:'.EditPostCaption::MAX_LENGTH]],
            ['oneOffAt.date_format' => 'Pick a date and time.', 'oneOffCaption.required' => 'Write the post first.'],
        );
        $at = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->oneOffAt, (string) config('board.timezone'));

        try {
            $post = $create->handle($this->model(), $at ?: CarbonImmutable::now(), $this->oneOffCaption, CarbonImmutable::now());
        } catch (MarketingPostRefusedException $e) {
            throw ValidationException::withMessages([$e->reason === 'past' ? 'oneOffAt' : 'oneOffCaption' => $e->getMessage()]);
        }

        $this->creatingOneOff = false;
        $this->postId = $post->id;
        $this->syncCaptions();
        $this->changed('One-off post created');
    }

    /**
     * "Rebuild" in "What should change?": ReviseDraft (MK-8) rewrites draft `$id` from `$feedback` and `$chips`; the
     * draft updates in place as its next version.
     *
     * Side effects: one Claude call and ReviseDraft's writes and logs; a refusal or failure shows under the box.
     *
     * @param  list<string>  $chips
     * @return bool whether it was rebuilt, so the box clears only then
     */
    public function revise(ReviseDraft $revise, int $id, string $feedback = '', array $chips = []): bool
    {
        $post = $this->shownPost($id);
        try {
            $revise->handle($post, $feedback, $chips);
        } catch (MarketingDraftRefusedException $e) {
            $this->addError('feedback', $e->getMessage());

            return false;
        } catch (MarketingAiException $e) {
            $this->addError('feedback', 'The AI could not rebuild it ('.$e->reason.'). Try again in a moment.');

            return false;
        }

        return $this->changed('Rebuilt as v'.$post->version, sync: true);
    }

    /**
     * "Undo" next to the version mark: draft `$id` goes back to its previous version.
     *
     * Side effects: UndoRevision writes and logs.
     */
    public function undo(UndoRevision $undo, int $id): void
    {
        $post = $this->shownPost($id);
        $this->attempt(fn () => $undo->handle($post)) && $this->changed('Back to v'.$post->version, sync: true);
    }

    /**
     * Thumbs up (1), down (-1) or clear (0) on post `$id`, with dislike `$reasons`.
     *
     * Side effects: RecordFeedback replaces the post's vote and logs `marketing.feedback.recorded`.
     *
     * @param  list<string>  $reasons
     */
    public function vote(RecordFeedback $record, int $id, int $vote, array $reasons = []): void
    {
        $post = $this->shownPost($id);
        $this->attempt(fn () => $record->handle($post, $vote, $reasons));
    }

    /**
     * A caption box lost focus with a change: save it (EditPostCaption re-lints it).
     *
     * @param  mixed  $value  the new caption
     * @param  string  $key  the post id
     */
    public function updatedCaptions(mixed $value, string $key): void
    {
        $post = $this->shownPost((int) $key);
        if ($post->caption === trim((string) $value)) {
            return;
        }
        try {
            app(EditPostCaption::class)->handle($post, (string) $value);
        } catch (MarketingPostRefusedException $e) {
            $this->addError('captions.'.$key, $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: 'Caption saved');
        $this->dispatch('marketing-changed');
    }

    /**
     * A photo was chosen in "Upload a photo for this post": validate it (JPG, PNG or WebP, 10 MB at most, the
     * favourites' limits), store it as one of the project's photos — not a favourite — and give it to the post.
     *
     * Side effects: StoreMarketingAsset writes the file and row; SetPostPhoto writes and logs.
     *
     * @param  mixed  $value  the upload
     * @param  string  $key  the post id
     */
    public function updatedUploads(mixed $value, string $key): void
    {
        $post = $this->shownPost((int) $key);
        try {
            $this->validate(
                ['uploads.'.$key => ['required', 'file', 'mimetypes:'.implode(',', MarketingAsset::PHOTO_MIMES), 'max:'.Settings::PHOTO_MAX_KB]],
                ['uploads.*.mimetypes' => 'Photos must be JPG, PNG or WebP images.', 'uploads.*.max' => 'Photos can be 10 MB at most.', 'uploads.*.required' => 'Choose a photo.'],
            );
        } finally {
            $file = $this->uploads[(int) $key] ?? null;
            // A rejected file left in the array would fail the next pick too.
            unset($this->uploads[(int) $key]);
        }

        $asset = app(StoreMarketingAsset::class)->handle($this->model(), $file, favourite: false);
        $this->attempt(fn () => app(SetPostPhoto::class)->handle($post, $asset)) && $this->changed('Photo added to this post');
    }

    /**
     * Livewire rejects an upload over its own temporary limit (12 MB) before updatedUploads() runs, naming "12288
     * kilobytes". Say the studio's own limit instead, under the same field (as Settings does).
     *
     * @param  string  $name  the property the upload was for
     * @param  string|null  $errorsInJson  Livewire's upload validation errors
     * @param  bool  $isMultiple  whether the input takes several files
     *
     * @throws ValidationException always, as Livewire's does.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple): void
    {
        try {
            $this->livewireUploadErrored($name, $errorsInJson, $isMultiple);
        } catch (ValidationException $e) {
            if (! str_starts_with($name, 'uploads.')) {
                throw $e;
            }
            unset($this->uploads[(int) substr($name, strlen('uploads.'))]);

            throw ValidationException::withMessages([$name => ['Photos can be 10 MB at most.']]);
        }
    }

    /**
     * "Use a favourite": favourite `$asset` becomes post `$id`'s photo. Another project's id changes nothing.
     *
     * Side effects: SetPostPhoto writes and logs; FindProjectAsset logs a refusal.
     */
    public function useFavourite(FindProjectAsset $find, SetPostPhoto $set, int $id, int $asset): void
    {
        $post = $this->shownPost($id);
        $found = $find->handle($this->model(), $asset);
        if ($found === null) {
            Flux::toast(variant: 'warning', text: 'That photo is no longer here.');

            return;
        }
        $this->attempt(fn () => $set->handle($post, $found)) && $this->changed('Using your favourite');
    }

    /**
     * "Text only": post `$id` goes out as the brand text card, with no photo.
     *
     * Side effects: SetPostPhoto writes and logs.
     */
    public function textOnly(SetPostPhoto $set, int $id): void
    {
        $post = $this->shownPost($id);
        $this->attempt(fn () => $set->handle($post, null)) && $this->changed('Text only: the brand text card');
    }

    /**
     * "Approve for <date>" (`$next` false) or "Approve & next" (true): approve draft `$id`, discarding the date's
     * others; with next, move to the first date still to approve, or close when there is none.
     *
     * Side effects: ApprovePost writes and logs.
     */
    public function approve(ApprovePost $approve, int $id, bool $next = false): void
    {
        $post = $this->shownPost($id);
        if (! $this->attempt(fn () => $approve->handle($post))) {
            return;
        }
        $when = $post->scheduled_for?->copy()->setTimezone($post->campaign->timezone ?? (string) config('board.timezone'));
        $this->dispatch('marketing-changed');

        $following = $next ? ($this->queue($this->queueAll ? null : $post->campaign)[0] ?? null) : null;
        if ($following !== null) {
            $this->campaignId = $following['campaign_id'];
            $this->date = $following['date'];
            $this->reset('postId', 'uploads');
            $this->syncCaptions();
            Flux::toast(variant: 'success', text: 'Approved '.$when?->format('D j M').'. Next: '.$following['at']->format('D j M'));

            return;
        }
        if ($next) {
            $this->close();
            Flux::toast(variant: 'success', text: 'Approved '.$when?->format('D j M').'. Nothing else needs approval.');

            return;
        }
        $this->syncCaptions();
        Flux::toast(variant: 'success', text: 'Approved for '.$when?->format('D j M').' at '.$when?->format('H:i'));
    }

    /**
     * "Unapprove" (confirmed in board/confirm-modal): approved post `$id` needs approval again.
     *
     * Side effects: UnapprovePost writes and logs.
     */
    public function unapprove(UnapprovePost $unapprove, int $id): void
    {
        $post = $this->shownPost($id);
        $this->attempt(fn () => $unapprove->handle($post)) && $this->changed('Unapproved. It needs approval again.', sync: true);
    }

    /**
     * "Reschedule" → Save: move approved post `$id` to `$at` (datetime-local, the post's timezone); the studio follows
     * it to its new date. A time already past is refused under the field.
     *
     * Side effects: ReschedulePost writes and logs.
     */
    public function reschedule(ReschedulePost $reschedule, int $id, string $at): void
    {
        $post = $this->shownPost($id);
        $zone = $post->campaign->timezone ?? (string) config('board.timezone');
        $when = CarbonImmutable::hasFormat($at, 'Y-m-d\TH:i') ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $at, $zone) : null;
        if (! $when instanceof CarbonImmutable) {
            throw ValidationException::withMessages(['rescheduleAt' => 'Pick a date and time.']);
        }
        try {
            $reschedule->handle($post, $when, CarbonImmutable::now());
        } catch (MarketingPostRefusedException $e) {
            throw ValidationException::withMessages(['rescheduleAt' => $e->getMessage()]);
        }

        if ($this->campaignId !== null) {
            $this->date = $when->toDateString();
        }
        $this->changed('Scheduled for '.$when->format('D j M').' at '.$when->format('H:i'), sync: true);
    }

    /**
     * "Mark posted" (also step 3 of "Post it now"): approved post `$id` went out by hand, with its Facebook link
     * `$link` when given. A link that is not a web address is refused under the field.
     *
     * Side effects: MarkPostPosted writes the post and its facebook network row, and logs.
     */
    public function markPosted(MarkPostPosted $mark, int $id, string $link = ''): void
    {
        $post = $this->shownPost($id);
        $link = trim($link);
        validator(['postLink' => $link], ['postLink' => ['nullable', 'url:http,https', 'max:2048']], ['postLink.url' => 'Paste the post\'s full link, starting https://'])->validate();

        $this->attempt(fn () => $mark->handle($post, $link === '' ? null : $link, CarbonImmutable::now())) && $this->changed('Marked as posted', sync: true);
    }

    /**
     * "Skip date": nothing posts on this campaign date.
     *
     * Side effects: SkipOccurrence writes and logs.
     */
    public function skip(SkipOccurrence $skip): void
    {
        $campaign = $this->campaign() ?? abort(404);
        $this->attempt(fn () => $skip->handle($campaign, (string) $this->date)) && $this->changed('Skipped '.CarbonImmutable::parse((string) $this->date)->format('D j M').'. Nothing posts that day.', sync: true);
    }

    /**
     * "Restore this date": undo Skip.
     *
     * Side effects: RestoreOccurrence writes and logs.
     */
    public function restore(RestoreOccurrence $restore): void
    {
        $campaign = $this->campaign() ?? abort(404);
        $this->attempt(fn () => $restore->handle($campaign, (string) $this->date)) && $this->changed('Date restored', sync: true);
    }

    /**
     * "Regenerate all 3": RegenerateOccurrence (MK-8) swaps the date's drafts for three new ones; a failure keeps them.
     *
     * Side effects: one Claude call and its writes and logs.
     */
    public function regenerate(RegenerateOccurrence $regenerate, FindOccurrencePosts $find): void
    {
        $campaign = $this->campaign() ?? abort(404);
        $first = $find->handle($campaign, (string) $this->date)->first();
        $at = $first?->scheduled_for !== null ? CarbonImmutable::instance($first->scheduled_for)->setTimezone($campaign->timezone) : $find->at($campaign, (string) $this->date);
        try {
            $regenerate->handle($campaign, $at);
        } catch (MarketingDraftRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        } catch (MarketingAiException $e) {
            Flux::toast(variant: 'danger', text: 'The AI could not draft new ones ('.$e->reason.'). The drafts you had are kept.');

            return;
        }
        $this->changed('3 new drafts', sync: true);
    }

    /**
     * "Fill this date" on an open date: queue drafting for it (FillCampaign, MK-8); the studio polls until it lands.
     *
     * Side effects: FillCampaign marks the date drafting and queues its job (logs); a refusal is toasted.
     */
    public function fillDate(FillCampaign $fill): void
    {
        $campaign = $this->campaign() ?? abort(404);
        try {
            $fill->handle($campaign, CarbonImmutable::now(), $this->date);
        } catch (MarketingDraftRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->changed('Drafting 3 posts', sync: true);
    }

    /**
     * Render the studio: nothing while closed; otherwise the date's state (open, review, approved, missed, posted,
     * skipped) or the one-off form, with the queue position.
     *
     * Side effects: a campaign whose schedule cannot be read logs `marketing.runway.failed` (source studio) and the
     * studio says why.
     */
    public function render(BuildCampaignRunway $runway, FindOccurrencePosts $find, ListDatesToApprove $queue, DraftingStatus $drafting, MarketingAiClient $ai): View|string
    {
        if ($this->gone || ! $this->isOpen) {
            return '<div data-studio-closed></div>';
        }

        $model = $this->model();
        $campaign = $this->campaign();
        $now = CarbonImmutable::now();
        $posts = new Collection;
        $error = null;
        $state = 'one-off';

        if (! $this->creatingOneOff) {
            try {
                $posts = $this->posts($find);
            } catch (CampaignScheduleException $e) {
                Log::warning('marketing.runway.failed', ['project' => $model->name, 'campaign_id' => $campaign?->id, 'reason' => $e->getMessage(), 'source' => 'studio']);
                $error = $e->getMessage();
            }
            $posts->load(['versions', 'feedback' => fn ($q) => $q->whereNotNull('vote')->latest('id')]);
            $state = $runway->state($posts);
            if ($state === 'approved' && $posts->contains(fn (MarketingPost $p) => $p->isMissed($now))) {
                $state = 'missed';
            }
        }

        $shown = match ($state) {
            'review' => $posts->where('status', MarketingPost::STATUS_DRAFT)->values(),
            'approved', 'missed' => $posts->where('status', MarketingPost::STATUS_APPROVED)->values(),
            'posted' => $posts->filter(fn (MarketingPost $p) => $p->isPosted())->values(),
            default => new Collection,
        };
        $zone = $campaign->timezone ?? (string) config('board.timezone');
        $at = $shown->first()?->scheduled_for?->copy()->setTimezone($zone)
            ?? ($campaign !== null && $this->date !== null && $error === null ? $find->at($campaign, $this->date) : null);

        $list = $this->postId === null && ! $this->creatingOneOff ? $queue->handle($model, $this->queueAll ? null : $campaign, $now) : [];
        $position = collect($list)->search(fn (array $q) => $q['campaign_id'] === $this->campaignId && $q['date'] === $this->date);
        $others = count($list) - ($position === false ? 0 : 1);

        $draftingState = $state === 'open' && $campaign !== null && $this->date !== null ? ($drafting->states($campaign->id, [$this->date])[$this->date] ?? null) : null;

        return view('livewire.marketing.post-studio', [
            'model' => $model,
            'campaign' => $campaign,
            'brand' => $model->marketingBrand()->first(),
            'state' => $state,
            'shown' => $shown,
            'at' => $at,
            'error' => $error,
            'position' => $position === false ? null : $position + 1,
            'queueCount' => count($list),
            'others' => $others,
            'drafting' => $draftingState,
            'aiReady' => $ai->isConfigured(),
            'favourites' => $state === 'one-off' ? new Collection : $model->marketingAssets()->favouritePhotos()->get(),
            'minAt' => $now->setTimezone($zone)->format('Y-m-d\TH:i'),
        ]);
    }

    /**
     * The posts on the date shown (or the one-off), kept ones only.
     *
     * @return Collection<int, MarketingPost>
     *
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    private function posts(FindOccurrencePosts $find): Collection
    {
        if ($this->postId !== null) {
            return $this->model()->marketingPosts()->whereKey($this->postId)->where('status', '!=', MarketingPost::STATUS_DISCARDED)
                ->with(['asset', 'campaign', 'networks'])->get();
        }
        $campaign = $this->campaign();

        return $campaign === null || $this->date === null ? new Collection : $find->handle($campaign, $this->date);
    }

    /**
     * Post `$id`, which must be one of the posts the studio is showing. Anything else — another date's, another
     * project's, a discarded one — is logged as `marketing.studio.refused` and is a 404.
     */
    private function shownPost(int $id): MarketingPost
    {
        $post = $this->posts(app(FindOccurrencePosts::class))->firstWhere('id', $id);

        return $post ?? $this->refused('not_shown', ['post_id' => $id]);
    }

    /**
     * Show `$campaign`'s `$date`, keeping the queue to that campaign.
     */
    private function showDate(MarketingCampaign $campaign, string $date): void
    {
        $this->campaignId = $campaign->id;
        $this->date = $date;
        $this->queueAll = false;
    }

    /**
     * Fill the caption boxes from the posts now shown.
     */
    private function syncCaptions(): void
    {
        try {
            $this->captions = $this->posts(app(FindOccurrencePosts::class))->mapWithKeys(fn (MarketingPost $p) => [$p->id => $p->caption])->all();
        } catch (CampaignScheduleException) {
            // render() logs and shows the broken schedule; there is nothing to edit.
            $this->captions = [];
        }
    }

    /**
     * The approval queue for `$campaign`, or every campaign that is on when null.
     *
     * @return list<array{campaign_id: int, date: string, at: CarbonImmutable}>
     */
    private function queue(?MarketingCampaign $campaign): array
    {
        return app(ListDatesToApprove::class)->handle($this->model(), $campaign?->fresh(), CarbonImmutable::now());
    }

    /**
     * Run a studio Action; a refusal is toasted. Returns whether it went through.
     */
    private function attempt(callable $action): bool
    {
        try {
            $action();
        } catch (MarketingPostRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Say what happened, tell the page under the studio to re-read, and (with `$sync`) refresh the caption boxes.
     */
    private function changed(string $message, bool $sync = false): bool
    {
        if ($sync) {
            $this->syncCaptions();
        }
        Flux::toast(variant: 'success', text: $message);
        $this->dispatch('marketing-changed');

        return true;
    }

    /**
     * Log a refused open or post id and 404.
     *
     * @param  array<string, mixed>  $context
     */
    private function refused(string $reason, array $context): never
    {
        Log::warning('marketing.studio.refused', ['project' => $this->project, 'reason' => $reason, 'campaign_id' => $this->campaignId, 'date' => $this->date, ...$context]);
        abort(404);
    }

    /**
     * The project the studio is on, read fresh on each request.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }

    /**
     * Campaign `$id` of this project, or a logged 404.
     */
    private function campaignOf(int $id): MarketingCampaign
    {
        return MarketingCampaign::where('project_id', $this->model()->id)->find($id) ?? $this->refused('unknown_campaign', ['campaign_id' => $id]);
    }

    /**
     * The campaign shown, re-read (null for a one-off).
     */
    private function campaign(): ?MarketingCampaign
    {
        return $this->campaignId === null ? null : MarketingCampaign::where('project_id', $this->model()->id)->findOrFail($this->campaignId);
    }
}
