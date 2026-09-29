<?php

namespace App\Http\Middleware;

use App\Actions\Board\CheckProjectShown;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the pre-SB-7 filter URL working: `/?project=coins` moved to `/p/coins`
 * (301, so bookmarks update). A name the board cannot show goes to `/` instead
 * of a 404 — the link is old, not wrong. Other filters ride along.
 */
class RedirectProjectFilter
{
    /**
     * @param  CheckProjectShown  $check  decides unknown vs disabled for every project URL
     */
    public function __construct(private readonly CheckProjectShown $check) {}

    /**
     * Redirect a request that carries a `project` filter; pass any other through.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->query('project');
        if (! is_string($name) || $name === '') {
            return $next($request);
        }

        $rest = $request->except('project');
        $reason = $this->check->refusal($name);
        if ($reason !== null) {
            Log::info('board.project_filter_redirected', ['project' => $name, 'reason' => $reason, 'to' => 'home']);

            return redirect()->route('home', $rest);
        }

        return redirect()->route('projects.show', ['project' => $name, ...$rest], 301);
    }
}
