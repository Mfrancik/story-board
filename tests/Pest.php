<?php

use App\Http\Middleware\RefreshStaleProjects;
use App\Livewire\Board\Home;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
 * The URL of the SB-9 all-projects dashboard (`Home`) for tests (RD-5). `/` is the overview now; `Home` stays in
 * the code, unrouted, until RD-9 deletes it, so its tests render it at this test-only route — with the same
 * refresh-on-load middleware `/` gave it — to keep their coverage until then. `$query` is appended (`?story=…`).
 */
function oldHome(string $query = ''): string
{
    if (! Route::has('test.old-home')) {
        Route::livewire('/test/old-home', Home::class)->middleware(RefreshStaleProjects::class)->name('test.old-home');
        Route::getRoutes()->refreshNameLookups();
    }

    return '/test/old-home'.$query;
}
