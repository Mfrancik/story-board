<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\ApprovePost;
use App\Actions\Marketing\FillCampaign;
use App\Actions\Marketing\ListReviewQueue;
use App\Actions\Marketing\QueuePostRender;
use App\Actions\Marketing\RecordFeedback;
use App\Actions\Marketing\RejectDateDrafts;
use App\Exceptions\MarketingDraftRefusedException;
use App\Exceptions\MarketingPostRefusedException;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The review inbox at `/p/{project}/marketing/review` (MK-20, mockup MK-20 option A "Grid"): every date waiting for
 * the owner's pick, soonest first, as a row of its rendered ads with the caption under each. Per ad: Keep (MK-9's
 * ApprovePost — the date's other drafts are discarded), 👍/👎 with MK-8's dislike reasons (RecordFeedback), and open
 * in the post studio, which sits on this page unchanged. Per date: Reject all 3 (RejectDateDrafts: dislikes, discard,
 * MK-8's Fill for that date), then "Drafting…" until the new three land, or MK-8's failure with Retry.
 *
 * The queue is ListReviewQueue, built on the campaigns list's own "to approve" rule. Moving between dates, the 1/2/3
 * and R keys, the dislike reasons and the Reject all confirm are Alpine (no round trip); every write goes through an
 * Action. Every post id or date the client sends is checked against the queue, so a forged one is a logged 404.
 *
 * The route's EnsureProjectIsShown refuses an unknown or disabled project before mount; GuardsProjectPage re-checks on
 * updates.
 */
#[Layout('layouts.shell')]
class Review extends Component
{
    use GuardsProjectPage;

    /** The campaign filter: ListReviewQueue::ALL, ::ONE_OFF, or a campaign id with a date waiting. */
    public string $filter = ListReviewQueue::ALL;

    /**
     * Keep the project's name.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * A filter chip was picked: keep it only if it is one of the chips shown; anything else would quietly show an
     * empty inbox, so it falls back to All.
     *
     * Side effects: a refused filter logs `marketing.review.refused` (warning, reason bad_filter).
     */
    public function updatedFilter(string $value): void
    {
        $chips = array_column($this->queue()['chips'], 'value');
        if (! in_array($value, $chips, true)) {
            Log::warning('marketing.review.refused', ['project' => $this->project, 'reason' => 'bad_filter', 'filter' => $value]);
            $this->filter = ListReviewQueue::ALL;
        }
    }

    /**
     * "Keep" (or the 1/2/3 key) on draft `$id`: ApprovePost approves it and discards the date's others, so the date
     * leaves the queue. Final from here: there is no Undo (the studio's Unapprove remains).
     *
     * Side effects: ApprovePost writes and logs; logs `marketing.review.kept` (post_id, campaign_id, date); a refusal
     * (still needs its photo) is toasted; a post not in the queue is a logged 404.
     */
    public function keep(ApprovePost $approve, int $id): void
    {
        [$row, $post] = $this->queuedPost($id);
        try {
            $approve->handle($post);
        } catch (MarketingPostRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Log::info('marketing.review.kept', ['project' => $this->project, 'post_id' => $post->id, 'campaign_id' => $post->campaign_id, 'date' => $row['date']]);
        $slot = $row['posts']->search(fn (MarketingPost $p) => $p->id === $post->id);
        Flux::toast(variant: 'success', text: 'Kept ad '.((int) $slot + 1).'. '.$row['at']->format('D j M').' · '.($row['campaign']->name ?? 'One-off').' is approved'
            .($row['posts']->count() > 1 ? '; the other '.($row['posts']->count() - 1 === 1 ? 'one is' : ($row['posts']->count() - 1).' are').' discarded.' : '.'));
    }

    /**
     * 👍 (1), 👎 (-1) or clear (0) on draft `$id`, with dislike `$reasons` (RecordFeedback::REASONS).
     *
     * Side effects: RecordFeedback replaces the post's vote and logs; a refusal is toasted; a post not in the queue
     * is a logged 404.
     *
     * @param  list<string>  $reasons
     */
    public function vote(RecordFeedback $record, int $id, int $vote, array $reasons = []): void
    {
        [, $post] = $this->queuedPost($id);
        try {
            $record->handle($post, $vote, $reasons);
        } catch (MarketingPostRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /**
     * "Reject all 3 and redraft" (confirmed; the R key opens the confirm): every draft of `$campaign`'s `$date` gets
     * a dislike with `$reason`, is discarded, and the date is redrafted by MK-8's Fill.
     *
     * Side effects: RejectDateDrafts writes and logs; a refusal is toasted; a campaign or date not in the queue is a
     * logged 404.
     */
    public function rejectAll(RejectDateDrafts $reject, int $campaign, string $date, ?string $reason = null): void
    {
        $row = $this->queuedDate($campaign, $date);
        if ($row['posts']->isEmpty()) {
            $this->refused('nothing_to_reject', ['campaign_id' => $campaign, 'date' => $date]);
        }
        try {
            $reject->handle($row['campaign'], $date, $reason === '' ? null : $reason, CarbonImmutable::now());
        } catch (MarketingPostRefusedException|MarketingDraftRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: 'Rejected all '.$row['posts']->count().($reason ? ' ('.$reason.')' : '').'. Drafting new ads for '.$row['at']->format('D j M').'.');
    }

    /**
     * "Retry" on a date whose redraft failed: MK-8's Fill for that one date again (the campaign page's Retry).
     *
     * Side effects: FillCampaign marks the date drafting, queues its job and logs; a refusal is toasted; a date not
     * in the queue is a logged 404.
     */
    public function retryDraft(FillCampaign $fill, int $campaign, string $date): void
    {
        $row = $this->queuedDate($campaign, $date);
        try {
            // The queue's campaign carries its runway posts; Fill reads its own.
            $fill->handle($row['campaign']->withoutRelations(), CarbonImmutable::now(), $date);
        } catch (MarketingDraftRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /**
     * "Retry" under an ad whose render failed: queue post `$id`'s render again, keeping its spec (MK-5, as the
     * studio's Retry).
     *
     * Side effects: QueuePostRender writes the post's `ad`, queues the job and logs; a post not in the queue is a
     * logged 404.
     */
    public function retryRender(QueuePostRender $render, int $id): void
    {
        [, $post] = $this->queuedPost($id);
        if (! $render->handle($post, respec: false)) {
            Flux::toast(variant: 'warning', text: 'This post needs its photo before it can be rendered.');
        }
    }

    /**
     * The post studio changed a post (`marketing-changed`): re-render so the queue shows it. The body is empty on
     * purpose; the request itself re-renders the page.
     */
    #[On('marketing-changed')]
    public function refreshFromStudio(): void {}

    /**
     * Render the inbox.
     *
     * Side effects: `marketing.review.viewed` (debug: dates, drafting, filter) on the page load.
     */
    public function render(): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = $this->model();
        $queue = $this->queue();
        // The last date of the filtered campaign was just kept: its chip is gone, so show everything left.
        if (! in_array($this->filter, array_column($queue['chips'], 'value'), true)) {
            $this->filter = ListReviewQueue::ALL;
            $queue = $this->queue();
        }

        if (! Livewire::isLivewireRequest()) {
            Log::debug('marketing.review.viewed', ['project' => $model->name, 'dates' => $queue['total'], 'drafting' => count($queue['dates']) - $queue['waiting'], 'filter' => $this->filter]);
        }

        return view('livewire.marketing.review', [
            'model' => $model,
            'queue' => $queue,
            'brand' => $model->marketingBrand()->first(),
            'reasons' => RecordFeedback::REASONS,
            // While a date drafts or an ad renders, poll until it lands (as the campaign page and the studio do).
            'busy' => collect($queue['dates'])->contains(fn (array $d) => $d['drafting'] === 'drafting'
                || $d['posts']->contains(fn (MarketingPost $p) => $p->renderState() === MarketingPost::RENDER_RENDERING)),
        ])->title('Review · '.$model->name.' · Marketing');
    }

    /**
     * The queue as it stands, narrowed to the filter.
     *
     * @return array{dates: list<array{key: string, campaign: MarketingCampaign|null, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null}>, waiting: int, total: int, chips: list<array{value: string, label: string, count: int}>}
     */
    private function queue(?string $filter = null): array
    {
        return app(ListReviewQueue::class)->handle($this->model(), CarbonImmutable::now(), $filter ?? $this->filter);
    }

    /**
     * Draft `$id` with its row, which must be in the queue (any filter). Anything else — another project's, an
     * approved or discarded post — is a logged 404.
     *
     * @return array{0: array{key: string, campaign: MarketingCampaign|null, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null}, 1: MarketingPost}
     */
    private function queuedPost(int $id): array
    {
        foreach ($this->queue(ListReviewQueue::ALL)['dates'] as $row) {
            $post = $row['posts']->firstWhere('id', $id);
            if ($post !== null) {
                return [$row, $post];
            }
        }

        $this->refused('not_in_queue', ['post_id' => $id]);
    }

    /**
     * `$campaign`'s row for `$date`, which must be in the queue (waiting, drafting or failed). Another project's
     * campaign, a malformed date or a date not waiting is a logged 404.
     *
     * @return array{key: string, campaign: MarketingCampaign, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null}
     */
    private function queuedDate(int $campaign, string $date): array
    {
        if (! MarketingCampaign::where('project_id', $this->model()->id)->whereKey($campaign)->exists()) {
            $this->refused('unknown_campaign', ['campaign_id' => $campaign, 'date' => $date]);
        }
        if (CarbonImmutable::hasFormat($date, 'Y-m-d') === false) {
            $this->refused('bad_date', ['campaign_id' => $campaign, 'date' => $date]);
        }
        foreach ($this->queue(ListReviewQueue::ALL)['dates'] as $row) {
            if ($row['campaign']?->id === $campaign && $row['date'] === $date) {
                /** @var array{key: string, campaign: MarketingCampaign, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null} $row */
                return $row;
            }
        }

        $this->refused('not_in_queue', ['campaign_id' => $campaign, 'date' => $date]);
    }

    /**
     * Log a refused id or date and 404.
     *
     * @param  array<string, mixed>  $context
     */
    private function refused(string $reason, array $context): never
    {
        Log::warning('marketing.review.refused', ['project' => $this->project, 'reason' => $reason, ...$context]);
        abort(404);
    }

    /**
     * The project being shown, read fresh on each request.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }
}
