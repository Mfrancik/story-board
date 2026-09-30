<?php

namespace App\Http\Controllers;

use App\Actions\Board\ParseVersion;
use App\Actions\Board\ReadMockupFile;
use App\Exceptions\MockupNotFoundException;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Serves raw mockup bytes from a project's ref (SB-4), always sandboxed.
 */
class MockupFileController extends Controller
{
    /** Types a mockup directory plausibly holds; anything else is served as opaque bytes. Shared with the gallery's frame route (SB-21). */
    public const TYPES = [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'woff2' => 'font/woff2', 'woff' => 'font/woff',
    ];

    /**
     * The CSP `sandbox` directive gives the response an opaque origin even when
     * it is opened directly rather than in the board's iframe, so a mockup's
     * scripts can run but can never read the board's DOM, cookies or storage,
     * nor navigate the top window. Without allow-same-origin, allow-scripts is safe.
     */
    private const CSP = "sandbox allow-scripts allow-popups; default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https:; frame-ancestors 'self'";

    /**
     * Return `$file` from `$storyId`'s mockup directory (at the ref, or at `?v=`'s branch), or 404.
     */
    public function __invoke(Request $request, Project $project, string $storyId, string $file, ReadMockupFile $read, ParseVersion $parse): Response
    {
        if (! $project->is_enabled) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $storyId, 'reason' => 'project disabled']);
            abort(404);
        }
        $version = $parse->handle($request->query('v'), ['project' => $project->name, 'story' => $storyId, 'file' => $file]);

        try {
            $bytes = $read->handle($project, $storyId, $file, $version);
        } catch (MockupNotFoundException) {
            // ReadMockupFile has already logged why (mockup_path_rejected / mockup_not_found).
            abort(404);
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return response($bytes, 200, [
            'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
