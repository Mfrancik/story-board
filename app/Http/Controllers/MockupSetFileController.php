<?php

namespace App\Http\Controllers;

use App\Actions\Board\ReadMockupSetFile;
use App\Exceptions\MockupNotFoundException;
use App\Models\Project;
use Illuminate\Http\Response;

/**
 * Serves a mockup set's files into the gallery's frames (SB-21), always
 * sandboxed. The route's EnsureProjectIsShown has already refused an unknown or
 * disabled project; ReadMockupSetFile decides which names exist.
 */
class MockupSetFileController extends Controller
{
    /**
     * `sandbox allow-scripts` with no `allow-same-origin`: the file runs in an
     * opaque origin even when opened in its own tab, so its scripts can never
     * reach the board's DOM, cookies or storage, nor navigate the top window.
     */
    private const CSP = "sandbox allow-scripts; default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https:; frame-ancestors 'self'";

    /**
     * Return `$file` from `$story`'s set at the ref, or 404.
     */
    public function __invoke(Project $project, string $story, string $file, ReadMockupSetFile $read): Response
    {
        try {
            $bytes = $read->handle($project, $story, $file);
        } catch (MockupNotFoundException) {
            // ReadMockupSetFile has already logged why.
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => MockupFileController::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
