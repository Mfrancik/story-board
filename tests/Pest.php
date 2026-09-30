<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
