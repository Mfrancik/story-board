<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\JourneyShots;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

// L-7: a Browser test runs only under bin/browser-lock.sh, which sets BROWSER_LOCK_HELD. Two unlocked runs on
// 127.0.0.1 share Playwright cookies and flake each other; a bare `--filter` reaches this suite too. CI is one job
// on its own runner, so it is exempt.
pest()->beforeEach(function () {
    if (getenv('BROWSER_LOCK_HELD') !== '1' && getenv('CI') === false) {
        throw new RuntimeException('Browser tests run only under the lock: bin/browser-lock.sh php artisan test tests/Browser …');
    }
})->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Screenshot one step of a journey test (SB-22): visits `$route` (or uses
 * `$page`) and, with `JOURNEY_SHOTS=1`, saves a desktop and a 375 px PNG plus
 * manifest entries under `storage/app/journey-shots/<journey>/`. Returns the
 * page so the test keeps asserting. See Tests\Support\JourneyShots.
 */
function journeyStep(string $journey, string $step, string $route, ?object $page = null): object
{
    return JourneyShots::step($journey, $step, $route, $page);
}

/**
 * A Messages API reply (MK-8) whose one text block is `$payload` as JSON, the shape MarketingAi reads: what a fake
 * Claude returns. Usage is fixed so cost tests know the numbers.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function claudeReply(array $payload, string $stopReason = 'end_turn'): array
{
    return [
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => json_encode($payload)]],
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 400],
    ];
}

/**
 * Fake Claude (MK-8): every Messages API call returns `$payload` (default: three drafts with no photo). Sets a test
 * API key so the client counts as configured.
 *
 * @param  array<string, mixed>|null  $payload
 */
function fakeClaude(?array $payload = null): void
{
    config(['services.anthropic.key' => 'test-key-not-real', 'services.anthropic.model' => 'claude-sonnet-5-5']);
    $payload ??= ['drafts' => [
        ['caption' => "Rainbow toning on a Morgan.\nShow us your best toner.", 'asset_id' => null],
        ['caption' => 'Album toning, straight from the paper.', 'asset_id' => null],
        ['caption' => 'Rate this rainbow from one to ten.', 'asset_id' => null],
    ]];
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReply($payload))]);
}
