<?php

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * RD-1: the correlation-ID middleware (logging-standards §Correlation ID) run
 * directly on a request, without loading a board page.
 */

/**
 * Run the middleware on a request carrying `$inbound` (or none), returning the
 * response and the ID the request's context held while it ran.
 *
 * @return array{0: Response, 1: mixed}
 */
function rdAssign(?string $inbound): array
{
    $request = Request::create('/anything');
    if ($inbound !== null) {
        $request->headers->set('X-Request-Id', $inbound);
    }
    $seen = null;
    $response = (new AssignRequestId)->handle($request, function () use (&$seen) {
        $seen = Context::get('request_id');

        return new Response('ok');
    });

    return [$response, $seen];
}

it('reuses an inbound X-Request-Id of a safe shape, in the context and on the response', function () {
    [$response, $seen] = rdAssign('abc-123_x.Y');

    expect($seen)->toBe('abc-123_x.Y')
        ->and($response->headers->get('X-Request-Id'))->toBe('abc-123_x.Y');
});

it('makes a UUID when there is no inbound ID, or it is not a safe shape', function (?string $inbound) {
    [$response, $seen] = rdAssign($inbound);

    expect(Str::isUuid($seen))->toBeTrue()
        ->and($response->headers->get('X-Request-Id'))->toBe($seen);
})->with([
    'none' => [null],
    'empty' => [''],
    'log injection' => ["abc\nfake.event"],
    'too long' => [str_repeat('a', 65)],
    'spaces' => ['a b'],
]);
