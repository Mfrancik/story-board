<?php

use App\Actions\Board\ReadCurrentVersion;
use App\Actions\Board\ReadProjectRoutes;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

/**
 * SB-23: the mockup viewer's Current pane — the newest journey shot (SB-22's
 * manifest, read through SB-24's ReadJourneyShots) whose route matches the
 * story's Where. One fixture project with routes/web.php committed, stories
 * naming an existing covered route, an existing uncovered route, a new route
 * and a route pattern; shots are written into its working tree per test.
 */
function currentStory(GitFixture $fixture, string $id, string $route): void
{
    $fixture->story($id, 'draft', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n\n"
        ."## Data & interfaces\n- Routes: `GET {$route}`.\n");
    foreach (['a', 'b'] as $letter) {
        $fixture->write("docs/mockups/{$id}/option-{$letter}.html", "<html><body>{$id} option {$letter}</body></html>");
    }
}

/**
 * Write a journey's manifest and PNGs where SB-22 puts them.
 *
 * @param  list<array<string, mixed>>  $entries
 * @param  list<string>  $pngs
 */
function currentShots(string $project, string $journey, array $entries, array $pngs = []): void
{
    $dir = "{$project}/storage/app/journey-shots/{$journey}";
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/manifest.json", json_encode($entries));
    foreach ($pngs as $png) {
        File::put("{$dir}/{$png}", "\x89PNG\r\n\x1a\n{$journey}/{$png}");
    }
}

beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo->write('routes/web.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Route;

        Route::get('/', fn () => view('welcome'))->name('home');

        Route::middleware('auth')->group(function () {
            // A closure body's braces must not close the group: '{project}' is a string, not a scope.
            Route::get('/about', function () { return view('about'); });
            Route::prefix('p/{project}')->name('projects.')->group(function () {
                Route::get('preflight', Preflight::class)->name('preflight');
            });
            Route::livewire('/widgets', Widgets::class)->name('widgets');
        });
        PHP);
    currentStory($this->repo, 'CO-1', '/p/coins/preflight');
    currentStory($this->repo, 'CO-2', '/widgets');
    currentStory($this->repo, 'CO-3', '/gadgets');
    currentStory($this->repo, 'CO-4', '/p/{project}/preflight');
    $this->repo->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    $this->shots = $this->repo->project.'/storage/app/journey-shots';
});

afterEach(function () {
    $this->repo->destroy();
});

it('Given a manifest shot for /p/coins/preflight and a story whose Where is that route, then the compare opens with Current on the left and option a on the right, with the shot\'s capture time', function () {
    $captured = now()->subDays(2)->utc()->startOfMinute();
    currentShots($this->repo->project, 'preflight', [
        ['step' => 'open', 'route' => '/p/coins/preflight', 'story' => 'CO-9', 'captured_at' => $captured->toIso8601String(), 'commit' => 'abc1234', 'file' => '01-preflight.png'],
    ], ['01-preflight.png']);

    $html = $this->get('/mockups/coins/CO-1')->assertOk()
        ->assertSee('data-compare-open="true"', false)
        ->assertSee('data-default-left="current"', false)
        ->assertSee('data-default-right="a"', false)
        ->assertSee('data-current-pane="shot"', false)
        ->assertSee('Captured '.$captured->format('M j, Y H:i').' UTC')
        ->assertSee('abc1234')
        ->assertDontSee('may be out of date')
        ->getContent();

    expect($html)->toContain('src="'.route('shots.file', ['project' => 'coins', 'journey' => 'preflight', 'file' => '01-preflight.png']).'"');
});

it('matches a shot by route pattern when the story names /p/{project}/preflight, and takes the newest', function () {
    currentShots($this->repo->project, 'preflight', [
        ['route' => '/p/coins/preflight', 'captured_at' => now()->subDays(3)->toIso8601String(), 'commit' => 'old1111', 'file' => '01-old.png'],
        ['route' => '/p/coins/preflight', 'captured_at' => now()->subDay()->toIso8601String(), 'commit' => 'new2222', 'file' => '02-new.png'],
    ], ['01-old.png', '02-new.png']);

    $this->get('/mockups/coins/CO-4')->assertOk()
        ->assertSee('data-current-pane="shot"', false)
        ->assertSee('02-new.png')
        ->assertDontSee('01-old.png');
});

it('prefers an exact route match over a pattern match', function () {
    $current = app(ReadCurrentVersion::class);
    $shot = fn (string $route, string $file, int $days) => ['journey' => 'j', 'step' => null, 'route' => $route, 'story' => null,
        'captured_at' => now()->subDays($days), 'commit' => null, 'file' => $file, 'path' => '/x/'.$file];

    $pick = $current->newest(['j' => [$shot('/p/{project}/preflight', 'pattern.png', 1), $shot('/p/coins/preflight', 'exact.png', 5)]], '/p/coins/preflight');

    expect($pick['file'] ?? null)->toBe('exact.png')
        ->and(ReadCurrentVersion::matches('/p/{project}/preflight', '/p/coins/preflight'))->toBeTrue()
        ->and(ReadCurrentVersion::matches('/p/coins/preflight/', '/p/{p}/preflight?x=1'))->toBeTrue()
        ->and(ReadCurrentVersion::matches('/p/{project:name}/map', '/p/coins/map'))->toBeTrue()
        ->and(ReadCurrentVersion::matches('/p/{project}/preflight', '/p/coins/stories'))->toBeFalse()
        ->and(ReadCurrentVersion::matches('/p/{project}', '/p/coins/preflight'))->toBeFalse();
});

it('Given no shot for the route, then the Current pane shows "No journey test covers this route yet"', function () {
    currentShots($this->repo->project, 'preflight', [
        ['route' => '/p/coins/preflight', 'captured_at' => now()->toIso8601String(), 'file' => '01-preflight.png'],
    ], ['01-preflight.png']);

    $this->get('/mockups/coins/CO-2')->assertOk()
        ->assertSee('data-current-pane="uncovered"', false)
        ->assertSee('No journey test covers this route yet')
        ->assertSee('data-compare-open="false"', false)
        ->assertDontSee('No current version — new page');
});

it('Given a story for a new page (Where names a route with no shot and no existing route), then it shows "No current version — new page"', function () {
    $this->get('/mockups/coins/CO-3')->assertOk()
        ->assertSee('data-current-pane="new"', false)
        ->assertSee('No current version — new page')
        ->assertDontSee('No journey test covers this route yet');
});

it('Given a shot older than 14 days, then it is labelled as possibly out of date', function () {
    $captured = now()->subDays(20)->utc();
    currentShots($this->repo->project, 'preflight', [
        ['route' => '/p/coins/preflight', 'captured_at' => $captured->toIso8601String(), 'file' => '01-preflight.png'],
    ], ['01-preflight.png']);

    $this->get('/mockups/coins/CO-1')->assertOk()
        ->assertSee('data-current-stale', false)
        ->assertSee('captured '.$captured->format('M j, Y').', may be out of date');
});

it('Given a shot path in the manifest outside journey-shots/, then it is refused and logged', function () {
    Log::spy();
    File::put($this->repo->project.'/.env.png', 'APP_KEY=secret-from-the-project');
    currentShots($this->repo->project, 'preflight', [
        ['route' => '/p/coins/preflight', 'captured_at' => now()->toIso8601String(), 'file' => '../../../../.env.png'],
    ]);

    $this->get('/mockups/coins/CO-1')->assertOk()
        ->assertSee('data-current-pane="uncovered"', false)
        ->assertDontSee('.env.png');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'board.journey_shot_refused'
        && $context['project'] === 'coins' && $context['path'] === '../../../../.env.png')->once();
});

it('Given the Current pane renders, then board.mockup_viewed includes current: true|false', function () {
    currentShots($this->repo->project, 'preflight', [
        ['route' => '/p/coins/preflight', 'captured_at' => now()->toIso8601String(), 'file' => '01-preflight.png'],
    ], ['01-preflight.png']);
    Log::spy();

    $this->get('/mockups/coins/CO-1')->assertOk();
    $this->get('/mockups/coins/CO-2')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_viewed'
        && $context['story'] === 'CO-1' && $context['current'] === true && $context['compare'] === true)->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_viewed'
        && $context['story'] === 'CO-2' && $context['current'] === false && $context['compare'] === false)->once();
});

it('says no shot can be matched when the story names no route, and never claims a new page', function () {
    $this->repo->story('CO-5', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n");
    $this->repo->write('docs/mockups/CO-5/option-a.html', '<html><body>CO-5</body></html>');
    $this->repo->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();

    $this->get('/mockups/coins/CO-5')->assertOk()
        ->assertSee('data-current-pane="uncovered"', false)
        ->assertSee('The story names no route');
});

it('does not claim a new page when the project\'s routes cannot be read, and logs why', function () {
    Log::spy();

    // A commit the checkout does not have: ls-tree fails, as it would on a broken checkout.
    expect(app(ReadProjectRoutes::class)->handle($this->project, str_repeat('0', 40)))->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'board.project_routes_unreadable'
        && $context['project'] === 'coins')->once();
});

it('reads route URIs from a project\'s route files with group prefixes, closures and api.php\'s prefix', function () {
    $routes = app(ReadProjectRoutes::class);

    // routes/web.php read at the project's snapshot commit, as the viewer reads it.
    expect($routes->handle($this->project->fresh(), (string) $this->project->fresh()->sha))
        ->toBe(['/', '/about', '/p/{project}/preflight', '/widgets'])
        ->and($routes->parse("<?php\nRoute::prefix('v1')->group(function () {\n Route::match(['get', 'post'], 'orders/{order}', X::class);\n Route::resource('photos', P::class);\n});\n", 'routes/api.php'))
        ->toBe(['/api/v1/orders/{order}', '/api/v1/photos', '/api/v1/photos/{photo}', '/api/v1/photos/create', '/api/v1/photos/{photo}/edit']);
});
