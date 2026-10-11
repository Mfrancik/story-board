<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * RD-4 in a real browser: every kept page, now in the dark shell, fits a 375 px phone with nothing
 * scrolling sideways but a table in its own scroll box, and a Stories row opens the RD-3 story page.
 * The fixture has a story with mockups, a built story and a journey, so the Stories list, the gallery,
 * the viewer and the app map all have content to lay out. Fixture repo only.
 */
beforeEach(function () {
    Queue::fake();
    $this->repo = new GitFixture;
    $routes = fn (string $r) => "## Data & interfaces\n- Routes: `GET {$r}` (`x`).\n";
    $this->repo->story('FX-1', 'built', 'demo', $routes('/register'));
    $this->repo->story('FX-2', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _(filled AFTER the owner picks)_\n\n".$routes('/settings'));
    foreach (['a', 'b'] as $o) {
        $this->repo->write("docs/mockups/FX-2/option-{$o}.html", "<html><body><h1>FX-2 option {$o}</h1></body></html>");
    }
    $this->repo->write('docs/journeys/sign-up.md', "# Journey — Sign up\n\n## Flow (plain steps)\n1. Register. (FX-1)\n2. Say what you collect. (FX-2)\n");
    $this->repo->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
});

afterEach(function () {
    $this->repo->destroy();
});

/**
 * Script: true when the page itself does not scroll sideways. A table may still be wider than the
 * phone, but only inside its own overflow box, which this does not count.
 */
const RESKIN_NO_SIDEWAYS = 'document.documentElement.scrollWidth <= window.innerWidth';

it('Given each kept page at 375 px, then nothing scrolls sideways except tables in their own scroll box', function (string $url) {
    visit($url)->resize(375, 812)
        ->assertPresent('html.shell')
        ->assertVisible('[data-shell-menu]')
        ->assertScript(RESKIN_NO_SIDEWAYS, true)
        ->assertNoJavaScriptErrors();
})->with([
    'Stories' => '/p/fx/stories',
    'Handbook' => '/p/fx/handbook',
    'App map' => '/p/fx/map',
    'Mockup gallery' => '/mockups',
    'Manage projects' => '/projects',
]);

it('fits the full-screen mockup viewer at 375 px, over the shell', function () {
    visit('/mockups/fx/FX-2')->resize(375, 812)
        ->assertVisible('[data-viewer-close]')
        ->assertScript(RESKIN_NO_SIDEWAYS, true)
        ->assertNoJavaScriptErrors();
});

it('opens a clicked Stories row on its RD-3 story page, with no modal', function () {
    visit('/p/fx/stories')->resize(1280, 900)
        ->assertAttribute('[data-nav-project="fx"]', 'aria-current', 'page')
        ->click('[data-story-link="fx/FX-1"]')
        ->assertPathIs('/p/fx/s/FX-1')
        ->assertMissing('[role="dialog"]')
        ->assertSee('Story FX-1')
        ->assertNoJavaScriptErrors();
});

/**
 * WARN-1 (ADR-045): the shell's appearance stub must leave the old pages' saved light/dark choice alone.
 * The browser runs in dark mode, so an old page that lost the saved "light" would fall back to dark.
 * flux.js takes `Flux.applyAppearance` once, on the first full load, and reuses it on every wire:navigate,
 * so only a session that STARTS on a shell page runs the stub: the second leg is the one that guards it.
 */
it('keeps the light choice an old page saved through a wire:navigate round trip into the shell', function () {
    $page = visit('/p/fx')->inDarkMode()->resize(1280, 900);
    $page->script("localStorage.setItem('flux.appearance', 'light')");

    $page->navigate('/p/fx')
        ->assertScript("document.documentElement.classList.contains('dark')", false)
        ->click('[data-project-tab="stories"]')
        ->assertPathIs('/p/fx/stories')
        ->assertPresent('html.shell')
        ->assertScript("document.documentElement.classList.contains('dark')", false)
        ->assertScript("localStorage.getItem('flux.appearance')", 'light')
        ->back()
        ->assertPathIs('/p/fx')
        ->assertMissing('html.shell')
        ->assertScript("document.documentElement.classList.contains('dark')", false)
        ->assertScript("localStorage.getItem('flux.appearance')", 'light')
        // Second leg: a full load of a shell page first, then a wire:navigate into an old page. Since RD-5 `/` is a
        // shell page and no shell link leads to an old one, so the navigation is started the way wire:navigate does.
        ->navigate('/p/fx/stories')
        ->assertPresent('html.shell')
        ->assertScript("localStorage.getItem('flux.appearance')", 'light')
        ->script("Livewire.navigate('/p/fx')");
    $page->wait(1);
    $page->assertPathIs('/p/fx')
        ->assertMissing('html.shell')
        ->assertScript("document.documentElement.classList.contains('dark')", false)
        ->assertScript("localStorage.getItem('flux.appearance')", 'light')
        ->assertNoJavaScriptErrors();
});
