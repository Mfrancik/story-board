<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingAiException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Services\Marketing\MarketingAiClient;
use App\Services\Marketing\MarketingPrompts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drafts one campaign date (MK-8): asks Claude for MarketingPrompts::DRAFTS captions and saves them as `draft` posts
 * (`source=ai`) with their generation_meta and lint warnings. All or nothing: a failed or unusable reply writes no
 * post. With `$replace` (RegenerateOccurrence) the date's current drafts are discarded in the same transaction as
 * the new ones are saved, so a failed regenerate leaves them in place.
 *
 * Photos follow the campaign's photo mode: `favourites` and `ai_decides` keep an id the model picked only when it is
 * one of the project's favourites offered in the prompt (any other id is dropped and logged); `per_post` and
 * `text_only` posts never get one from the model.
 */
class DraftOccurrence
{
    /**
     * @param  MarketingAiClient  $ai  the model
     * @param  MarketingPrompts  $prompts  what the model is asked
     * @param  LintCaption  $lint  the copy-rules lint run on every caption
     * @param  CampaignSchedule  $schedule  the campaign's timezone, for which day a post is on
     * @param  BuildCampaignRunway  $runway  whose day() places a post on a date
     * @param  QueuePostRender  $render  queues each new draft's ad render (MK-5)
     */
    public function __construct(
        private MarketingAiClient $ai,
        private MarketingPrompts $prompts,
        private LintCaption $lint,
        private CampaignSchedule $schedule,
        private BuildCampaignRunway $runway,
        private QueuePostRender $render,
    ) {}

    /**
     * Draft `$campaign`'s date `$at` (the occurrence's time) and return the new posts, in candidate order.
     *
     * Side effects: one Claude call; writes the posts (and with `$replace` discards the old drafts); queues each new
     * draft's ad render (MK-5, QueuePostRender); logs `marketing.ai.draft.started|succeeded|failed|refused`,
     * `marketing.ai.invalid_asset`, `marketing.lint.warned`.
     *
     * @return Collection<int, MarketingPost>
     *
     * @throws MarketingDraftRefusedException when no key is set or the date is not open (with `$replace`: has a post that is not a draft)
     * @throws MarketingAiException when the call fails or the reply is unusable; nothing is written
     * @throws CampaignScheduleException when the campaign's timezone cannot be read
     */
    public function handle(MarketingCampaign $campaign, CarbonImmutable $at, bool $replace = false): Collection
    {
        $zone = $this->schedule->zone($campaign);
        $date = $at->setTimezone($zone)->toDateString();
        $context = ['campaign_id' => $campaign->id, 'date' => $date];

        if (! $this->ai->isConfigured()) {
            $this->refuse($context, 'not_configured', 'Add ANTHROPIC_API_KEY to .env to draft with AI.');
        }

        $existing = $campaign->posts()->whereNotNull('scheduled_for')->where('status', '!=', MarketingPost::STATUS_DISCARDED)
            ->whereBetween('scheduled_for', [$at->subDay(), $at->addDay()])->get()
            ->filter(fn (MarketingPost $p) => $this->runway->day($p, $zone) === $date);
        // Fill only fills an empty date; Regenerate only replaces drafts, never an approved, posted or skipped post.
        $blocked = $replace ? $existing->contains(fn (MarketingPost $p) => $p->status !== MarketingPost::STATUS_DRAFT) : $existing->isNotEmpty();
        if ($blocked) {
            $this->refuse($context, 'not_open', 'This date already has a post.');
        }

        $prompt = $this->prompts->draft($campaign, $at);
        Log::info('marketing.ai.draft.started', [...$context, 'model' => $this->ai->model(), 'topics' => count($prompt['topics']),
            'skipped_ideas' => $prompt['skipped_ideas'], 'replace' => $replace]);

        try {
            $reply = $this->ai->complete($prompt['system'], $prompt['user'], MarketingPrompts::DRAFTS_SCHEMA);
            $drafts = $this->drafts($reply['data']);
        } catch (MarketingAiException $e) {
            Log::error('marketing.ai.draft.failed', [...$context, 'model' => $this->ai->model(), 'reason' => $e->reason,
                'status' => $e->status, 'error_type' => $e->errorType, 'duration_ms' => $e->durationMs]);

            throw $e;
        }
        $meta = $reply['meta'];

        $posts = DB::transaction(function () use ($campaign, $at, $drafts, $prompt, $meta, $replace, $existing) {
            if ($replace && $existing->isNotEmpty()) {
                MarketingPost::whereKey($existing->modelKeys())->update(['status' => MarketingPost::STATUS_DISCARDED]);
            }

            $brandRules = $campaign->project()->firstOrFail()->marketingBrand()->first()->copy_rules ?? [];

            return collect($drafts)->map(fn (array $draft, int $i) => MarketingPost::create([
                'project_id' => $campaign->project_id,
                'campaign_id' => $campaign->id,
                'scheduled_for' => $at->utc(),
                'status' => MarketingPost::STATUS_DRAFT,
                'caption' => $draft['caption'],
                'version' => 1,
                'asset_id' => $this->asset($campaign, $draft['asset_id'], $prompt['assets']),
                'source' => 'ai',
                'generation_meta' => [...$meta, 'call' => 'draft', 'candidate' => $i + 1, 'drafts_in_call' => count($drafts),
                    'topic_key' => $prompt['topics'][$i]['key'] ?? null, 'topic' => $prompt['topics'][$i]['label'] ?? null,
                    // MK-18: which inspiration the prompt quoted, for the studio's "Inspired by" row.
                    'inspiration_ids' => $prompt['inspiration_ids']],
                'lint' => $this->lint->handle($draft['caption'], $brandRules),
            ]));
        });

        foreach ($posts as $post) {
            if ($post->lint !== []) {
                Log::debug('marketing.lint.warned', ['campaign_id' => $campaign->id, 'post_id' => $post->id, 'codes' => array_column((array) $post->lint, 'code')]);
            }
        }
        Log::info('marketing.ai.draft.succeeded', [...$context, 'post_ids' => $posts->pluck('id')->all(), 'replaced' => $replace ? $existing->count() : 0,
            'model' => $meta['model'], 'input_tokens' => $meta['input_tokens'], 'output_tokens' => $meta['output_tokens'],
            'cost_cents' => $meta['cost_micro_usd'] === null ? null : round($meta['cost_micro_usd'] / 10000, 4), 'duration_ms' => $meta['duration_ms']]);

        // MK-5: every draft becomes an ad image (a draft still waiting for its photo is skipped until it gets one).
        $posts->each(fn (MarketingPost $post) => $this->render->handle($post));

        return $posts;
    }

    /**
     * The reply's candidates, trimmed: exactly DRAFTS non-empty captions, or the reply is unusable.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string, asset_id: mixed}>
     *
     * @throws MarketingAiException when fewer than DRAFTS usable captions came back
     */
    private function drafts(array $data): array
    {
        $drafts = [];
        foreach (is_array($data['drafts'] ?? null) ? $data['drafts'] : [] as $draft) {
            $caption = is_array($draft) && is_string($draft['caption'] ?? null) ? trim($draft['caption']) : '';
            if ($caption !== '') {
                $drafts[] = ['caption' => $caption, 'asset_id' => $draft['asset_id'] ?? null];
            }
        }
        if (count($drafts) < MarketingPrompts::DRAFTS) {
            throw new MarketingAiException('Claude sent back '.count($drafts).' usable captions, not '.MarketingPrompts::DRAFTS.'.', 'invalid_reply');
        }

        return array_slice($drafts, 0, MarketingPrompts::DRAFTS);
    }

    /**
     * The photo a draft keeps: the model's pick when it was one of the ids offered, else none. An id that was not
     * offered (another project's, a deleted one, an invented one) is logged and dropped.
     *
     * @param  list<int>  $offered
     */
    private function asset(MarketingCampaign $campaign, mixed $picked, array $offered): ?int
    {
        // per_post and text_only offer nothing: whatever the model says, the post has no photo from it.
        if ($picked === null || $offered === []) {
            return null;
        }
        if (is_int($picked) && in_array($picked, $offered, true)) {
            return $picked;
        }
        Log::warning('marketing.ai.invalid_asset', ['campaign_id' => $campaign->id, 'asset_id' => $picked, 'photo_mode' => $campaign->photo_mode]);

        return null;
    }

    /**
     * Log a refusal and throw it.
     *
     * @param  array{campaign_id: int, date: string}  $context
     *
     * @throws MarketingDraftRefusedException always
     */
    private function refuse(array $context, string $reason, string $message): never
    {
        Log::info('marketing.ai.draft.refused', ['campaign_id' => $context['campaign_id'], 'reason' => $reason, 'date' => $context['date']]);

        throw new MarketingDraftRefusedException($message, $reason);
    }
}
