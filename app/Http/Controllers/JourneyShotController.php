<?php

namespace App\Http\Controllers;

use App\Actions\Board\ReadJourneyShots;
use App\Models\Project;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves one journey shot (SB-22's PNGs) for the app map (SB-24). The route's
 * EnsureProjectIsShown has already refused an unknown or disabled project;
 * ReadJourneyShots decides which files exist — only those a manifest lists
 * inside the project's `journey-shots/` folder. The request's names are looked
 * up, never joined onto a path.
 */
class JourneyShotController extends Controller
{
    /**
     * Return the shot `$file` of journey folder `$journey`, or 404 (logged by ReadJourneyShots).
     */
    public function __invoke(Project $project, string $journey, string $file, ReadJourneyShots $shots): BinaryFileResponse
    {
        $path = $shots->file($project, $journey, $file);
        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => ReadJourneyShots::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))],
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
