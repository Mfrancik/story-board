<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingAiException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use App\Models\MarketingPostVersion;
use App\Services\Marketing\MarketingAiClient;
use App\Services\Marketing\MarketingPrompts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "What should change?" on a draft (MK-8; its box arrives with the studio, MK-9): Claude rewrites the post from the
 * owner's feedback text and chips. The post becomes the next version; the version it replaces is kept in
 * marketing_post_versions with the feedback given on it, and the feedback is stored as taste (marketing_feedback).
 * Nothing is written unless the rewrite succeeds.
 *
 * The rewrite carries a new ad too (MK-19): the note can change the words on the image or the layout. The kept version
 * holds the old one in its generation_meta (`ad`, what Claude chose), so Undo puts it back.
 */
class ReviseDraft
{
    /** The quick-feedback chips, as the studio shows them. */
    public const CHIPS = ['Shorter', 'Warmer', 'More about the set', 'Different photo', 'Less salesy'];

    /**
     * @param  MarketingAiClient  $ai  the model
     * @param  MarketingPrompts  $prompts  what the model is asked
     * @param  LintCaption  $lint  the copy-rules lint run on the new caption
     * @param  QueuePostRender  $render  queues the new version's ad render (MK-5)
     * @param  NormaliseAdSpec  $normalise  turns Claude's new ad into a drawable spec (MK-19)
     */
    public function __construct(
        private MarketingAiClient $ai,
        private MarketingPrompts $prompts,
        private LintCaption $lint,
        private QueuePostRender $render,
        private NormaliseAdSpec $normalise,
    ) {}

    /**
     * Rewrite draft `$post` from `$feedback` and `$chips` (any of CHIPS) and return it as its next version.
     *
     * Side effects: one Claude call; writes a version row, a feedback row and the post (its new ad spec included, MK-19);
     * queues the post's ad render (MK-5); logs `marketing.ai.revise.succeeded|failed|refused`, `marketing.ai.invalid_asset`,
     * `marketing.ai.ad_spec_fixed`, `marketing.lint.warned`.
     *
     * @param  list<string>  $chips
     *
     * @throws MarketingDraftRefusedException when the post is not a draft, the feedback is empty, a chip is unknown, or no key is set
     * @throws MarketingAiException when the call fails or the reply is unusable; nothing is written
     */
    public function handle(MarketingPost $post, string $feedback, array $chips = []): MarketingPost
    {
        $feedback = trim($feedback);
        $chips = array_values(array_unique($chips));

        if ($post->status !== MarketingPost::STATUS_DRAFT) {
            $this->refuse($post, 'not_draft', 'Only a draft can be revised.');
        }
        if (array_diff($chips, self::CHIPS) !== []) {
            $this->refuse($post, 'unknown_chip', 'Pick from the suggested changes.');
        }
        if ($feedback === '' && $chips === []) {
            $this->refuse($post, 'empty_feedback', 'Say what should change, or pick a suggestion.');
        }
        if (! $this->ai->isConfigured()) {
            $this->refuse($post, 'not_configured', 'Add ANTHROPIC_API_KEY to .env to revise with AI.');
        }

        $prompt = $this->prompts->revise($post, $feedback, $chips);
        try {
            $reply = $this->ai->complete($prompt['system'], $prompt['user'], MarketingPrompts::CAPTION_SCHEMA);
            $caption = is_string($reply['data']['caption'] ?? null) ? trim($reply['data']['caption']) : '';
            if ($caption === '') {
                throw new MarketingAiException('Claude sent back an empty caption.', 'invalid_reply');
            }
        } catch (MarketingAiException $e) {
            Log::error('marketing.ai.revise.failed', ['campaign_id' => $post->campaign_id, 'post_id' => $post->id, 'model' => $this->ai->model(),
                'reason' => $e->reason, 'status' => $e->status, 'error_type' => $e->errorType, 'duration_ms' => $e->durationMs]);

            throw $e;
        }
        $meta = $reply['meta'];
        $asset = $this->asset($post, $reply['data']['asset_id'] ?? null, $prompt['assets']);
        $rules = $post->project()->firstOrFail()->marketingBrand()->first()->copy_rules ?? [];
        // A reply with no ad (the schema asks for one) keeps the ad Claude chose before, rather than losing it.
        $choice = MarketingPrompts::adChoice($reply['data']['ad'] ?? null) ?? MarketingPrompts::adChoice($post->generation_meta['ad'] ?? null);

        DB::transaction(function () use ($post, $feedback, $chips, $caption, $asset, $meta, $rules, $choice) {
            MarketingPostVersion::create([
                'post_id' => $post->id, 'version' => $post->version, 'caption' => $post->caption, 'asset_id' => $post->asset_id,
                'feedback' => $feedback !== '' ? $feedback : null, 'chips' => $chips !== [] ? $chips : null, 'generation_meta' => $post->generation_meta,
            ]);
            MarketingFeedback::create([
                'project_id' => $post->project_id, 'campaign_id' => $post->campaign_id, 'post_id' => $post->id,
                'vote' => null, 'reasons' => $chips !== [] ? $chips : null, 'note' => $feedback !== '' ? $feedback : null,
            ]);
            $post->fill([
                'caption' => $caption, 'asset_id' => $asset, 'version' => $post->version + 1,
                'generation_meta' => [...$meta, 'call' => 'revise', 'topic_key' => $post->generation_meta['topic_key'] ?? null, 'topic' => $post->generation_meta['topic'] ?? null,
                    // A rebuild keeps the draft's examples (MK-18), so its "Inspired by" row survives the rewrite.
                    'inspiration_ids' => $post->generation_meta['inspiration_ids'] ?? [], 'ad' => $choice],
                'lint' => $this->lint->handle($caption, $rules),
            ]);
            // Normalised against the new photo; merged over the old `ad` so its render ids stay until the new images
            // replace them (QueuePostRender keeps them, StorePostRenders deletes them).
            $post->ad = [...(array) $post->ad, ...$this->normalise->handle($post, $choice)];
            $post->save();
        });

        if ($post->lint !== []) {
            Log::debug('marketing.lint.warned', ['campaign_id' => $post->campaign_id, 'post_id' => $post->id, 'codes' => array_column((array) $post->lint, 'code')]);
        }
        Log::info('marketing.ai.revise.succeeded', ['campaign_id' => $post->campaign_id, 'post_id' => $post->id, 'version' => $post->version,
            'chips' => $chips, 'layout' => $post->ad['layout'] ?? null, 'model' => $meta['model'], 'input_tokens' => $meta['input_tokens'], 'output_tokens' => $meta['output_tokens'],
            'cost_cents' => $meta['cost_micro_usd'] === null ? null : round($meta['cost_micro_usd'] / 10000, 4), 'duration_ms' => $meta['duration_ms']]);
        // The spec was just stored from Claude's (MK-19), so it is kept rather than rebuilt.
        $this->render->handle($post, respec: false);

        return $post;
    }

    /**
     * The photo the new version carries. Unless "Different photo" offered favourites (`$offered` not null), the
     * photo stays as it is. Otherwise the model's pick, when it was offered; any other id is logged and dropped,
     * and the post keeps its photo.
     *
     * @param  list<int>|null  $offered
     */
    private function asset(MarketingPost $post, mixed $picked, ?array $offered): ?int
    {
        if ($offered === null || $picked === null) {
            return $post->asset_id;
        }
        if (is_int($picked) && in_array($picked, $offered, true)) {
            return $picked;
        }
        Log::warning('marketing.ai.invalid_asset', ['campaign_id' => $post->campaign_id, 'post_id' => $post->id, 'asset_id' => $picked]);

        return $post->asset_id;
    }

    /**
     * Log a refusal and throw it.
     *
     * @throws MarketingDraftRefusedException always
     */
    private function refuse(MarketingPost $post, string $reason, string $message): never
    {
        Log::info('marketing.ai.revise.refused', ['post_id' => $post->id, 'reason' => $reason]);

        throw new MarketingDraftRefusedException($message, $reason);
    }
}
