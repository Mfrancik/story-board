<?php

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * RD-9 acceptance criteria: the pages the redesign replaced are gone with their code. `/prod` and the bare
 * project page 404, the legacy `/?project=` redirect is gone, every kept route still answers, and nothing in
 * the codebase still names a deleted class, view or helper. One fixture project with one story and a mockup.
 */
beforeEach(function () {
    Queue::fake();
    $this->fixture = new GitFixture;
    $this->fixture->story('RD-1', 'draft', 'demo', "## Design mockup gate\n- Mockups: docs/mockups/RD-1/\n"
        ."- Chosen option: _(filled AFTER the owner picks; no build before this)_\n- Why I chose it: _pending_\n\n## Links\n");
    foreach (['a', 'b'] as $letter) {
        $this->fixture->write("docs/mockups/RD-1/option-{$letter}.html", "<p>RD-1 {$letter}</p>");
    }
    $this->fixture->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh')->assertSuccessful();
    $this->fixture->syncProject();
});

afterEach(function () {
    $this->fixture->destroy();
});

/**
 * The class names, views and helpers RD-9 deleted, as regexes. Word boundaries keep the kept look-alikes
 * (GuardsProjectPage, ListLiveSessions, Shell\Sidebar) from matching.
 *
 * @return list<string>
 */
function retiredNames(): array
{
    return [
        '/Livewire\\\\Board\\\\Home\b/',
        '/\bProjectPage\b/',
        '/\bStoryModal\b/',
        '/\bLiveSessions\b/',
        '/ProductionDashboard\b/',
        '/Components\\\\Board\\\\Sidebar\b/',
        '/\bListWhatNeedsMe\b/',
        '/\bResolveStoryLink\b/',
        '/\bReadProjectProgress\b/',
        '/\bRedirectProjectFilter\b/',
        '/\bReadProductionMetrics\b/',
        '/\boldHome\b/',
        '/layouts[.\/]board\b/',
        '/livewire\.board\.(home|project-page|story-modal|live-sessions|production-dashboard)\b/',
        '/x-board\.(needs-me-cards|project-card|section|story-row|sidebar|prod-stat|prod-change|prod-row-status)\b/',
        '/\bprojects\.show\b/',
    ];
}

it('Given /prod and a bare /p/{project}, then each is 404', function (string $url) {
    $this->get($url)->assertNotFound();
})->with(['/prod', '/p/fx', '/p/unknown']);

it('Given /?project=story-board, then it is not redirected; the overview renders unfiltered', function (string $name) {
    $this->get("/?project={$name}")->assertOk()
        ->assertSeeHtml('data-filter-label class="truncate">All projects</span>')
        ->assertDontSeeHtml('data-filter-active');
})->with(['story-board', 'fx']);

it('Given every remaining route, then each returns 200', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    '/',
    '/projects',
    '/mockups',
    '/mockups/fx/RD-1',
    '/p/fx/stories',
    '/p/fx/preflight',
    '/p/fx/handbook',
    '/p/fx/map',
    '/p/fx/s/RD-1',
    '/p/fx/m/RD-1/option-a.html',
]);

arch('Given the codebase, then no class uses a deleted class')
    ->expect('App')
    ->not->toUse([
        'App\Livewire\Board\Home',
        'App\Livewire\Board\ProjectPage',
        'App\Livewire\Board\StoryModal',
        'App\Livewire\Board\LiveSessions',
        'App\Livewire\Board\ProductionDashboard',
        'App\View\Components\Board\Sidebar',
        'App\Actions\Board\ListWhatNeedsMe',
        'App\Actions\Board\ResolveStoryLink',
        'App\Actions\Board\ReadProjectProgress',
        'App\Actions\Board\ListProductionDashboard',
        'App\Http\Middleware\RedirectProjectFilter',
        'App\Jobs\ReadProductionMetrics',
    ]);

it('Given the codebase, then nothing in app, resources, routes, bootstrap or tests names a deleted class, view or helper', function () {
    // Blade and route files are outside the arch scan, so the names are also searched as text.
    $hits = [];
    foreach (['app', 'resources', 'routes', 'bootstrap', 'tests'] as $dir) {
        foreach (File::allFiles(base_path($dir)) as $file) {
            if ($file->getRealPath() === __FILE__ || ! in_array($file->getExtension(), ['php', 'css', 'js'], true)) {
                continue;
            }
            foreach (retiredNames() as $pattern) {
                if (preg_match($pattern, $file->getContents()) === 1) {
                    $hits[] = $dir.'/'.$file->getRelativePathname().' ~ '.$pattern;
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

it('Given the views, then no copy sends the owner to the retired project dashboard to refresh', function () {
    // The "Refresh this project" button died with the old project page; the handbook and app-map error states
    // now point at refresh-on-load and `board:refresh` instead.
    $hits = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains($file->getContents(), 'from its dashboard'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($hits)->toBe([]);
});
