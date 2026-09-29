<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID (logging-standards §Correlation ID): an
 * inbound X-Request-Id is reused when it looks like an ID, otherwise a UUID is
 * made. It rides in Laravel's Context, so every log line of the request carries
 * it, and is returned on the response.
 */
class AssignRequestId
{
    /** An inbound ID is trusted only in this shape — it ends up in every log line. */
    private const INBOUND = '/^[A-Za-z0-9._-]{1,64}$/D';

    /**
     * Assign the ID, run the request, echo the ID back.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $inbound = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match(self::INBOUND, $inbound) ? $inbound : (string) Str::uuid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
