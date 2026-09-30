<?php

use App\Livewire\Board\ProjectAppMap;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-24 acceptance criteria: `/p/{project}/map` draws every journey in the
 * project's `docs/journeys/*.md` at its ref as a flow of steps, each with its
 * story, state and picture. The fixture has two journeys in the two table
 * shapes real projects use (coins' `Step | ID | Story file | Status` and
 * rent-track's `Step | Story | Path | Status`), sharing `/dashboard`. Every
 * test runs with no journey shots unless it writes a manifest into the
 * registered clone's working tree (where SB-22 will put them). The Alpine
 * step-through is proven in tests/Browser/ProjectAppMapTest.php.
 */
function appMapStory(GitFixture $fixture, string $id, string $status, string $body = ''): void
{
    $fixture->story($id, $status, 'demo', $body);
}

function appMapRoutes(string $route): string
{
    return "## Data & interfaces\n- Schema/migrations: none.\n- Routes: `GET {$route}` (`x`).\n";
}

function appMapGate(string $chosen): string
{
    return "## Design mockup gate\n- Mockups: docs/mockups\n- Chosen option: {$chosen}\n- Why I chose it: fixture\n\n";
}

const APP_MAP_SIGN_UP = <<<'MD'
# Journey — Sign up

The first minutes of a new account.

## Flow (plain steps)
1. Register an account at `/register` → a user exists.
2. Land on the **dashboard** at `/dashboard`.
3. Say what you collect — the chosen form at `/settings/collecting`.
4. A step nobody has written a story for yet.

## Stories (build order)
| Step | ID | Story file | Status |
|---|---|---|---|
| 1 | MP-1 | stories/demo/MP-1-story.md | built |
| 2 | MP-2 | stories/demo/MP-2-story.md | built |
| 3 | MP-3 | stories/demo/MP-3-story.md | approved |

## Journey test
tests/Browser/Journeys/SignUpTest.php
MD;

const APP_MAP_BUY = <<<'MD'
# Journey: Buy a thing — "pay and leave"

## The flow (plain steps)
1. Land on the dashboard. (MP-2)
2. Open the lot page and
   read it through. (MP-4)
3. Pay the invoice. (MP-5)

## Composing stories

| Step | Story | Path | Status |
|---|---|---|---|
| 1 | MP-2 Dashboard | `stories/demo/MP-2-story.md` | **built** |
| 2 | MP-4 Lot page | `stories/demo/MP-4-story.md` | draft |
| 3 | MP-5 Invoice | `stories/demo/MP-5-story.md` | draft |
MD;

beforeEach(function () {
    Queue::fake();
    $this->repo = new GitFixture;
    appMapStory($this->repo, 'MP-1', 'built', appMapRoutes('/register'));
    appMapStory($this->repo, 'MP-2', 'built', appMapRoutes('/dashboard'));
    appMapStory($this->repo, 'MP-3', 'approved', appMapGate('b').appMapRoutes('/settings/collecting'));
    foreach (['a', 'b'] as $o) {
        $this->repo->write("docs/mockups/MP-3/option-{$o}.html", "<html><body>MP-3 option {$o}</body></html>");
        $this->repo->write("docs/mockups/MP-4/option-{$o}.html", "<html><body>MP-4 option {$o}</body></html>");
    }
    appMapStory($this->repo, 'MP-4', 'draft', appMapGate('_(filled AFTER the owner picks; no build before this)_').appMapRoutes('/lots/{id}'));
    appMapStory($this->repo, 'MP-5', 'draft');
    $this->repo->write('docs/journeys/README.md', "# User Journeys\nOne file per flow.\n");
    $this->repo->write('docs/journeys/sign-up.md', APP_MAP_SIGN_UP);
    $this->repo->write('docs/journeys/buy-a-thing.md', APP_MAP_BUY);
    $this->repo->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'alpha', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'alpha'])->assertSuccessful();
    $this->repo->syncProject();
    $this->shots = $this->repo->project.'/storage/app/journey-shots';
});

afterEach(function () {
    $this->repo->destroy();
    ($this->outside ?? null) && File::deleteDirectory($this->outside);
});

/**
 * Write a journey's manifest (and its PNGs) where SB-22 puts them: the
 * registered clone's working tree, outside git.
 *
 * @param  list<array<string, mixed>>  $entries
 * @param  list<string>  $pngs
 */
function appMapShots(string $root, string $journey, array $entries, array $pngs = []): void
{
    File::ensureDirectoryExists("{$root}/{$journey}");
    File::put("{$root}/{$journey}/manifest.json", json_encode($entries));
    foreach ($pngs as $png) {
        File::put("{$root}/{$journey}/{$png}", "\x89PNG\r\n\x1a\n{$journey}/{$png}");
    }
}

/** One step's markup in the overview, by its `data-step` key. */
function appMapStep(string $html, string $key): string
{
    expect($html)->toContain('data-step="'.$key.'"');
    preg_match('#<li\b[^>]*data-step="'.preg_quote($key, '#').'".*?</li>#s', $html, $m);

    return $m[0];
}

it('Given a project with two journey docs, when /p/{project}/map loads, then both flows are listed with their steps in order and each step\'s story ID and state', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    // Journeys A–Z by file (buy-a-thing, sign-up); each one's steps in doc order with story and state.
    expect($html)->toContain('data-journey="buy-a-thing"')->toContain('data-journey="sign-up"');
    $this->get('/p/alpha/map')->assertSeeInOrder([
        'data-journey="buy-a-thing"', 'Buy a thing',
        'data-step="buy-a-thing/1"', 'data-story="MP-2"', 'data-state="built"', 'Land on the dashboard',
        'data-step="buy-a-thing/2"', 'data-story="MP-4"', 'data-state="pending"', 'Open the lot page and read it through',
        'data-step="buy-a-thing/3"', 'data-story="MP-5"', 'data-state="pending"', 'Pay the invoice',
        'data-journey="sign-up"', 'Sign up',
        'data-step="sign-up/1"', 'data-story="MP-1"', 'data-state="built"', 'Register an account at',
        'data-step="sign-up/2"', 'data-story="MP-2"', 'data-state="built"',
        'data-step="sign-up/3"', 'data-story="MP-3"', 'data-state="pending"',
        'data-step="sign-up/4"',
    ], false);
});

it('shows a journey doc step that names no story or no route with a not linked marker, not hidden', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    // Step 4 of sign-up names no story; MP-5 names no route anywhere.
    expect(appMapStep($html, 'sign-up/4'))->toContain('data-unlinked="story"')->toContain('Not linked')
        ->and(appMapStep($html, 'buy-a-thing/3'))->toContain('data-unlinked="route"')->toContain('Not linked');
});

it('Given a project with no journey shots at all (no SB-22), then every built step shows a placeholder page labelled with its route and story ID, the map is fully navigable, and the page returns 200', function () {
    expect(is_dir($this->shots))->toBeFalse();
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    foreach (['sign-up/1' => ['MP-1', '/register'], 'sign-up/2' => ['MP-2', '/dashboard'], 'buy-a-thing/1' => ['MP-2', '/dashboard']] as $key => [$story, $route]) {
        $step = appMapStep($html, $key);
        expect($step)->toContain('data-picture="placeholder"')->toContain($story)->toContain($route)->not->toContain('<img');
    }

    // Navigable: a flow picker entry per journey plus All flows, every step reachable, Back and Next.
    expect($html)->toContain('data-flow="all"')
        ->toContain('data-flow="sign-up"')
        ->toContain('data-flow="buy-a-thing"')
        ->toContain('data-back')
        ->toContain('data-next')
        ->toContain('data-open-large')
        ->toContain('data-strip-step="sign-up/4"');
});

it('Given a built step with a journey shot in the project\'s manifest.json, then its picture is that shot with the capture time', function () {
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'step' => 'register', 'route' => '/register', 'story' => 'MP-1', 'captured_at' => '2026-09-28T10:15:00Z', 'commit' => 'abc1234', 'file' => '01-register.png'],
    ], ['01-register.png']);

    $html = $this->get('/p/alpha/map')->assertOk()->getContent();
    $step = appMapStep($html, 'sign-up/1');
    $url = route('shots.file', ['project' => 'alpha', 'journey' => 'sign-up', 'file' => '01-register.png']);

    expect($step)->toContain('data-picture="shot"')->toContain('src="'.$url.'"')->toContain('Sep 28, 2026 10:15')
        // Another built step with no entry keeps its placeholder.
        ->and(appMapStep($html, 'sign-up/2'))->toContain('data-picture="placeholder"');

    // The shot itself is served from the manifest's whitelist, as an image.
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('matches a shot to its step by route when the manifest names no story', function () {
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'step' => 'dashboard', 'route' => '/dashboard', 'captured_at' => '2026-09-28T10:16:00Z', 'commit' => 'abc1234', 'file' => '02-dashboard.png'],
    ], ['02-dashboard.png']);

    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect(appMapStep($html, 'sign-up/2'))->toContain('data-picture="shot"')
        // Shots belong to their own journey: buy-a-thing's /dashboard step has none.
        ->and(appMapStep($html, 'buy-a-thing/1'))->toContain('data-picture="placeholder"');
});

it('Given a manifest entry whose path is outside journey-shots/, then it is refused, the step falls back to the placeholder, and board.journey_shot_refused is logged', function (string $path) {
    $this->outside = sys_get_temp_dir().'/story-board-outside-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->outside);
    File::put($this->outside.'/secret.png', 'outside');
    File::put($this->repo->project.'/.env.png', 'APP_KEY=secret-from-the-project');
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'step' => 'register', 'route' => '/register', 'story' => 'MP-1', 'captured_at' => '2026-09-28T10:15:00Z', 'commit' => 'abc', 'file' => str_replace('{outside}', $this->outside, $path)],
    ]);
    Log::spy();

    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect(appMapStep($html, 'sign-up/1'))->toContain('data-picture="placeholder"')->not->toContain('<img')
        ->and($html)->not->toContain('secret-from-the-project');
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.journey_shot_refused'
        && $c['project'] === 'alpha' && $c['path'] === str_replace('{outside}', $this->outside, $path))->once();
})->with([
    'climbs out' => ['../../../../.env.png'],
    'absolute' => ['{outside}/secret.png'],
    'another folder' => ['storage/app/public/x.png'],
]);

it('refuses a manifest entry that is a symlink out of journey-shots/', function () {
    $this->outside = sys_get_temp_dir().'/story-board-outside-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->outside);
    File::put($this->outside.'/secret.png', 'outside');
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'story' => 'MP-1', 'route' => '/register', 'captured_at' => '2026-09-28T10:15:00Z', 'file' => '01-register.png'],
    ]);
    symlink($this->outside.'/secret.png', $this->shots.'/sign-up/01-register.png');
    Log::spy();

    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect(appMapStep($html, 'sign-up/1'))->toContain('data-picture="placeholder"');
    $this->get(route('shots.file', ['project' => 'alpha', 'journey' => 'sign-up', 'file' => '01-register.png']))->assertNotFound();
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.journey_shot_refused' && $c['project'] === 'alpha')->atLeast()->once();
});

it('serves only files the manifest lists: a PNG beside it, a traversal and an unknown journey all 404, logged', function (string $journey, string $file) {
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'story' => 'MP-1', 'route' => '/register', 'captured_at' => '2026-09-28T10:15:00Z', 'file' => '01-register.png'],
    ], ['01-register.png', 'unlisted.png']);
    File::put($this->repo->project.'/.env', 'APP_KEY=secret-from-the-project');
    Log::spy();

    $this->get('/shots/alpha/'.$journey.'/'.$file)->assertNotFound()->assertDontSee('secret-from-the-project');

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.journey_shot_refused'
        && $c['project'] === 'alpha')->once();
})->with([
    'unlisted' => ['sign-up', 'unlisted.png'],
    'traversal' => ['sign-up', '..%2F..%2F..%2F..%2F.env'],
    'unknown journey' => ['nope', '01-register.png'],
    'manifest itself' => ['sign-up', 'manifest.json'],
]);

it('returns 404 for a shot of a disabled or unknown project', function () {
    Project::factory()->disabled()->create(['name' => 'off']);

    $this->get('/shots/off/sign-up/01.png')->assertNotFound();
    $this->get('/shots/nope/sign-up/01.png')->assertNotFound();
});

it('skips a manifest that is not JSON and keeps the placeholders, logged', function () {
    File::ensureDirectoryExists($this->shots.'/sign-up');
    File::put($this->shots.'/sign-up/manifest.json', '{not json');
    Log::spy();

    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect(appMapStep($html, 'sign-up/1'))->toContain('data-picture="placeholder"');
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.journey_shot_refused'
        && $c['project'] === 'alpha' && $c['path'] === 'journey-shots/sign-up/manifest.json')->once();
});

it('Given a pending step whose story has Chosen option: b, then its picture is option b from the gallery', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();
    $step = appMapStep($html, 'sign-up/3');

    expect($step)->toContain('data-picture="mockup"')
        ->toContain(route('mockups.frame', ['project' => 'alpha', 'story' => 'MP-3', 'file' => 'option-b.html']))
        ->toContain('sandbox="allow-scripts"')
        ->toContain('option B')
        ->not->toContain('allow-same-origin');
});

it('Given a pending step whose story awaits a pick, then it shows "awaiting pick" linking to /mockups/{project}/{story}', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect(appMapStep($html, 'buy-a-thing/2'))->toContain('data-picture="awaiting"')->toContain('Awaiting pick')
        ->and($html)->toMatch('#<a\b[^>]*href="'.preg_quote(route('mockups.show', ['project' => 'alpha', 'story' => 'MP-4']), '#').'"[^>]*data-awaiting-link#');
});

it('shows "no mockup yet" for a pending step whose story has no mockups', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect(appMapStep($html, 'buy-a-thing/3'))->toContain('data-picture="none"')->toContain('No mockup yet');
});

it('Given a step open large, when Next is pressed on the last step, then it stays and Next is disabled (the bound markup)', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    // Alpine clamps the step and binds disabled; tests/Browser/ProjectAppMapTest.php presses it.
    expect($html)->toMatch('/data-next[^>]*x-bind:disabled="atLast"/')
        ->and($html)->toMatch('/data-lightbox-next[^>]*x-bind:disabled="atLast"/');
});

it('Given two journeys visiting the same route, then the overview marks the shared screen', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    // /dashboard is step 1 of buy-a-thing (from MP-2's Routes line) and step 2 of sign-up (named in the step).
    expect(appMapStep($html, 'buy-a-thing/1'))->toContain('data-shared="A"')
        ->and(appMapStep($html, 'sign-up/2'))->toContain('data-shared="A"')
        ->and(appMapStep($html, 'sign-up/1'))->not->toContain('data-shared=');
    $this->get('/p/alpha/map')->assertSeeInOrder(['data-shared-list', 'data-shared-route="/dashboard"', 'Buy a thing · 1', 'Sign up · 2'], false);
});

it('Given a journey doc that fails to parse, then the other journeys still render and the page says which one failed', function () {
    $this->repo->write('docs/journeys/broken.md', "# Journey — Broken\n\nOnly prose. No steps, no stories.\n")->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'alpha'])->assertSuccessful();
    Log::spy();

    $html = $this->get('/p/alpha/map')->assertOk()->getContent();

    expect($html)->toContain('data-journey="sign-up"')->toContain('data-journey="buy-a-thing"')
        ->not->toContain('data-journey="broken"')
        ->toMatch('#data-unparsed[^>]*>.*docs/journeys/broken\.md#s');
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.journey_unparsed'
        && $c === ['project' => 'alpha', 'file' => 'docs/journeys/broken.md'])->once();
});

it('Given a project with no journey docs, then the empty state explains where journeys come from and returns 200', function () {
    $bare = new GitFixture;
    $bare->story('BA-1', 'built')->commitAndPush();
    Project::factory()->create(['name' => 'bare', 'path' => $bare->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'bare'])->assertSuccessful();

    try {
        $this->get('/p/bare/map')->assertOk()
            ->assertSee('data-empty', false)
            ->assertSee('bare has no journeys yet.')
            ->assertSee('docs/journeys/&lt;slug&gt;.md', false)
            ->assertSee('/story');
    } finally {
        $bare->destroy();
    }
});

it('shows the empty state, not an error, for a project with no snapshot yet', function () {
    Project::factory()->create(['name' => 'fresh', 'path' => $this->repo->project, 'sha' => null, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    Log::spy();

    $this->get('/p/fresh/map')->assertOk()->assertSee('data-empty', false);

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.app_map_viewed' && $c['project'] === 'fresh' && $c['journeys'] === 0)->once();
});

it('says the journeys could not be read, logged, when git cannot list them', function () {
    $this->project->update(['sha' => str_repeat('f', 40)]);
    Log::spy();

    $this->get('/p/alpha/map')->assertOk()->assertSee('data-unreadable', false);

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.app_map_unreadable' && $c['project'] === 'alpha')->once();
});

it('Given a disabled or unknown project, then the route returns 404', function () {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get('/p/nope/map')->assertNotFound();
    $this->get('/p/acme-site/map')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c === ['project' => 'nope', 'reason' => 'unknown'])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c === ['project' => 'acme-site', 'reason' => 'disabled'])->once();
});

it('and hydrate() re-checks it: a project switched off mid-visit sends the owner home, logged', function () {
    $page = Livewire::test(ProjectAppMap::class, ['project' => $this->project]);
    $this->project->update(['is_enabled' => false]);
    Log::spy();

    $page->call('$refresh')->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused'
        && $c === ['project' => 'alpha', 'reason' => 'disabled', 'request' => 'update'])->once();
});

it('Given the page loads, then board.app_map_viewed is logged with project, journeys, steps, shots', function () {
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'story' => 'MP-1', 'route' => '/register', 'captured_at' => '2026-09-28T10:15:00Z', 'file' => '01-register.png'],
    ], ['01-register.png']);
    Log::spy();

    $this->get('/p/alpha/map')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.app_map_viewed'
        && $c === ['project' => 'alpha', 'journeys' => 2, 'steps' => 7, 'shots' => 1])->once();
});

it('marks App map as the current project tab, and the other tabs link to it', function () {
    $html = $this->get('/p/alpha/map')->assertOk()->getContent();
    expect($html)->toMatch('/data-project-tab="map"[^>]*aria-current="page"/');

    $this->get('/p/alpha/stories')->assertOk()->assertSee('data-project-tab="map"', false);
});

it('never writes to the project: the working tree and git status are unchanged by a visit', function () {
    appMapShots($this->shots, 'sign-up', [
        ['journey' => 'sign-up', 'story' => 'MP-1', 'route' => '/register', 'captured_at' => '2026-09-28T10:15:00Z', 'file' => '01-register.png'],
    ], ['01-register.png']);
    $status = $this->repo->git($this->repo->project, 'status', '--porcelain', '--ignored');
    $manifest = md5_file($this->shots.'/sign-up/manifest.json');

    $this->get('/p/alpha/map')->assertOk();
    $this->get(route('shots.file', ['project' => 'alpha', 'journey' => 'sign-up', 'file' => '01-register.png']))->assertOk();

    expect($this->repo->git($this->repo->project, 'status', '--porcelain', '--ignored'))->toBe($status)
        ->and(md5_file($this->shots.'/sign-up/manifest.json'))->toBe($manifest);
});
