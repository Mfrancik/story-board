<?php

namespace App\Services\Marketing;

use App\Exceptions\MarketingAiException;

/**
 * What the marketing Actions need from a language model (MK-8): one structured completion. Bound to MarketingAi
 * (Claude's Messages API); tests fake the HTTP underneath it with Http::fake, so the real request shape is tested.
 */
interface MarketingAiClient
{
    /**
     * Whether an API key is set. Without one, Fill is disabled and every call is refused.
     */
    public function isConfigured(): bool;

    /**
     * The model calls are made with (MARKETING_AI_MODEL).
     */
    public function model(): string;

    /**
     * Ask the model once. `$system` is the fixed, cacheable part (brand voice and copy rules); `$user` the part that
     * changes per call. The reply is JSON matching `$schema`.
     *
     * Side effects: one external HTTP call.
     *
     * @param  array<string, mixed>  $schema  a JSON schema for the reply
     * @return array{data: array<string, mixed>, meta: array{model: string, input_tokens: int, output_tokens: int, cache_read_input_tokens: int, cache_creation_input_tokens: int, cost_micro_usd: int|null, prompt_hash: string, duration_ms: int}}
     *
     * @throws MarketingAiException when there is no key, the call fails or times out, the model refuses, or the reply is not the JSON asked for
     */
    public function complete(string $system, string $user, array $schema): array;
}
