<?php

namespace App\Http\Middleware;

use App\Actions\Board\CheckProjectShown;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a `/p/{project}` page — the project page and the story page under it —
 * for a project that is unknown or disabled (SB-7), so both routes 404 in one place
 * and say why in the log. It runs before SubstituteBindings
 * (bootstrap/app.php priority), so an unknown name is refused and logged here
 * rather than failing silently in Livewire's route-model binding.
 */
class EnsureProjectIsShown
{
    /**
     * @param  CheckProjectShown  $check  decides unknown vs disabled for every project URL
     */
    public function __construct(private readonly CheckProjectShown $check) {}

    /**
     * 404 with `board.project_page_refused` when the route's project may not be shown.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route('project');

        $reason = $this->check->refusal($name);
        if ($reason !== null) {
            Log::info('board.project_page_refused', ['project' => $name, 'reason' => $reason]);
            abort(404);
        }

        return $next($request);
    }
}
