<?php

namespace App\Services\Marketing;

use App\Exceptions\MarketingAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Claude for marketing copy (MK-8), over Laravel's HTTP client against the Messages API: one structured completion
 * per call, the fixed system part cached, the reply's usage turned into tokens and an estimated cost. The API key
 * travels only in the `x-api-key` header and is never logged or stored; the prompt is stored only as a hash.
 */
class MarketingAi implements MarketingAiClient
{
    /** The Messages API. */
    public const URL = 'https://api.anthropic.com/v1/messages';

    /** The API version header every request carries. */
    public const API_VERSION = '2023-06-01';

    /** The model when MARKETING_AI_MODEL is not set: the current Sonnet. */
    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    /**
     * Server-side fallback: when the model declines on policy grounds the API re-runs the request on a fallback
     * model inside the same call, so a false-positive refusal does not cost the owner a Retry. The reply's `model`
     * names who answered, and that is the model recorded and priced.
     */
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Seconds to wait for a reply. Three short captions take seconds; past this the date shows "Drafting failed". */
    public const TIMEOUT_SECONDS = 90;

    /** Room for adaptive thinking plus the JSON; a cut-off reply is useless, so this is generous rather than tight. */
    public const MAX_TOKENS = 16000;

    /**
     * Price per token in micro-USD (= USD per million tokens), for the cost estimate: input, output, cache read,
     * cache write (5-minute, 1.25 × input). A model not listed is recorded with a null cost rather than a guess.
     */
    public const PRICES = [
        'claude-sonnet-5-5' => [2.0, 10.0, 0.20, 2.50],
        'claude-opus-5-5' => [4.0, 20.0, 0.20, 5.0],
        'claude-haiku-5-5' => [0.10, 0.50, 0.01, 0.125],
    ];

    /**
     * {@inheritDoc}
     */
    public function isConfigured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * {@inheritDoc}
     */
    public function model(): string
    {
        $model = config('services.anthropic.model');

        return is_string($model) && $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /**
     * {@inheritDoc}
     */
    public function complete(string $system, string|array $user, array $schema): array
    {
        if (! $this->isConfigured()) {
            throw new MarketingAiException('Add ANTHROPIC_API_KEY to .env to draft with AI.', 'not_configured');
        }

        $started = hrtime(true);
        $body = [
            'model' => $this->model(),
            'max_tokens' => self::MAX_TOKENS,
            // Voice and copy rules change only when the owner edits them, so this block is the cache prefix.
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $user]],
            // Short captions need judgement, not depth: medium effort, and a schema so the reply is always JSON.
            'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            'fallbacks' => 'default',
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta' => self::FALLBACK_BETA,
            ])->acceptJson()->asJson()->connectTimeout(10)->timeout(self::TIMEOUT_SECONDS)->post(self::URL, $body);
        } catch (ConnectionException) {
            throw new MarketingAiException('Claude did not answer in time.', 'timeout', null, $this->since($started));
        }

        $duration = $this->since($started);
        if ($response->failed()) {
            $type = $response->json('error.type');
            throw new MarketingAiException("Claude answered HTTP {$response->status()}.", 'http_error', $response->status(), $duration, is_string($type) ? $type : null);
        }

        $stop = $response->json('stop_reason');
        if ($stop === 'refusal') {
            throw new MarketingAiException('Claude declined to write this one.', 'refusal', $response->status(), $duration);
        }
        if ($stop === 'max_tokens') {
            throw new MarketingAiException('Claude ran out of room before finishing.', 'truncated', $response->status(), $duration);
        }

        $text = collect((array) $response->json('content'))->first(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'text');
        $data = is_array($text) ? json_decode((string) ($text['text'] ?? ''), true) : null;
        if (! is_array($data)) {
            throw new MarketingAiException('Claude replied with something other than the JSON asked for.', 'invalid_reply', $response->status(), $duration);
        }

        $model = is_string($response->json('model')) ? $response->json('model') : $this->model();
        $usage = [
            'input_tokens' => (int) $response->json('usage.input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
            'cache_read_input_tokens' => (int) $response->json('usage.cache_read_input_tokens', 0),
            'cache_creation_input_tokens' => (int) $response->json('usage.cache_creation_input_tokens', 0),
        ];

        /** @var array<string, mixed> $data */
        return [
            'data' => $data,
            'meta' => [
                'model' => $model,
                ...$usage,
                'cost_micro_usd' => $this->cost($model, $usage),
                // Enough to tell two calls' prompts apart, or match one to a re-run, without storing the prompt.
                'prompt_hash' => hash('sha256', $system."\n\n".(is_string($user) ? $user : json_encode($user))),
                'duration_ms' => $duration,
            ],
        ];
    }

    /**
     * The call's estimated cost in micro-USD, or null for a model with no listed price (alias or dated snapshot).
     *
     * @param  array{input_tokens: int, output_tokens: int, cache_read_input_tokens: int, cache_creation_input_tokens: int}  $usage
     */
    private function cost(string $model, array $usage): ?int
    {
        // The reply may name a dated snapshot (`claude-sonnet-5-5-20261001`) rather than the alias asked for, so
        // price by the longest listed alias the id starts with; an exact-key lookup would silently record no cost.
        $alias = collect(array_keys(self::PRICES))
            ->filter(fn (string $listed): bool => $model === $listed || str_starts_with($model, $listed.'-'))
            ->sortByDesc(fn (string $listed): int => strlen($listed))
            ->first();
        if ($alias === null) {
            return null;
        }
        [$in, $out, $read, $write] = self::PRICES[$alias];

        return (int) round($usage['input_tokens'] * $in + $usage['output_tokens'] * $out
            + $usage['cache_read_input_tokens'] * $read + $usage['cache_creation_input_tokens'] * $write);
    }

    /**
     * Milliseconds since `$started` (an hrtime).
     */
    private function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
