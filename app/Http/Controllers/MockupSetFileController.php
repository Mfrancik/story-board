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
     * Types a mockup directory plausibly holds; anything else is served as opaque bytes.
     * Owned here, by the gallery's frame route the redesign keeps; the story page's older
     * mockup route reads it from here until it goes.
     */
    public const TYPES = [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'woff2' => 'font/woff2', 'woff' => 'font/woff',
    ];

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
            'Content-Type' => self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
