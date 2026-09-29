<?php

namespace App\Actions\Board;

use Illuminate\Support\Facades\Log;

/**
 * Reads the `?v=` query value that names an off-main version (SB-5) — the one
 * place the story page and the mockup route decide what a version id may be.
 */
class ParseVersion
{
    /**
     * The version row id, null when `v` is absent; a 404 (logged) for anything
     * but a positive integer.
     *
     * @param  array<string, mixed>  $context  what was being requested, for the log line
     */
    public function handle(mixed $raw, array $context): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (! is_string($raw) || ! ctype_digit($raw)) {
            Log::warning('board.version_rejected', [...$context, 'v' => is_scalar($raw) ? $raw : gettype($raw)]);
            abort(404);
        }

        return (int) $raw;
    }
}
