<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * RD-4 acceptance criteria: the kept pages (Stories, Handbook, Mockup gallery and viewer, Manage
 * projects, App map) render in the RD-2 dark shell with their project current in its sidebar, the
 * Stories rows link to the RD-3 story page instead of opening the SB-8 modal, and the project tabs
 * are Stories, Preflight, Handbook and Map. One git fixture project with a story and its mockups is
 * enough for every page to render; the 375 px check is in tests/Browser/ReskinKeptPagesTest.php.
 */
beforeEach(function () {
    Queue::fake();
    $this->repo = new GitFixture;
    $this->repo->story('AL-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _(filled AFTER the owner picks)_\n")
        ->story('AL-2', 'built', 'demo');
    foreach (['a', 'b'] as $o) {
        $this->repo->write("docs/mockups/AL-1/option-{$o}.html", "<html><body>AL-1 option {$o}</body></html>");
    }
    $this->repo->commitAndPush();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->alpha = Project::factory()->create(['name' => 'alpha', 'path' => $this->repo->project, ...$fresh]);
    Project::factory()->create(['name' => 'beta', ...$fresh]);
    $this->artisan('board:refresh', ['project' => 'alpha'])->assertSuccessful();
});

afterEach(function () {
    $this->repo->destroy();
});

/**
 * The opening tag of one sidebar entry, found by its data attribute, so attribute assertions stay on it.
 */
function reskinNav(string $html, string $attribute): string
{
    preg_match('/<a\b[^>]*'.preg_quote($attribute, '/').'[^>]*>/', $html, $m);

    return $m[0] ?? '';
}

it('Given each kept page, when it renders, then it uses the new shell and its project is current in the sidebar', function (string $url, ?string $current) {
    $html = $this->get($url)->assertOk()
        ->assertSeeHtml('class="shell"')
        ->assertSeeHtml('data-shell-sidebar')
        // The old board shell's sidebar is gone from the page.
        ->assertDontSeeHtml('data-sidebar-project')
        ->getContent();

    if ($current === 'project') {
        expect(reskinNav($html, 'data-nav-project="alpha"'))->toContain('aria-current="page"')
            ->and(reskinNav($html, 'data-nav-project="beta"'))->not->toContain('aria-current');
    } else {
        // A page with no project marks no project; the gallery and viewer mark Mockups.
        expect($html)->not->toMatch('/<a\b[^>]*data-nav-project="[^"]*"[^>]*aria-current/')
            ->and(str_contains(reskinNav($html, 'data-nav="mockups"'), 'aria-current="page"'))->toBe($current === 'mockups');
    }
})->with([
    'Stories' => ['/p/alpha/stories', 'project'],
    'Handbook' => ['/p/alpha/handbook', 'project'],
    'App map' => ['/p/alpha/map', 'project'],
    'Mockup viewer' => ['/mockups/alpha/AL-1', 'project'],
    'Mockup gallery' => ['/mockups', 'mockups'],
    'Manage projects' => ['/projects', null],
]);

it('marks Mockups current in the sidebar on the mockup viewer as well as the gallery', function () {
    $html = $this->get('/mockups/alpha/AL-1')->assertOk()->getContent();

    expect(reskinNav($html, 'data-nav="mockups"'))->toContain('aria-current="page"');
});

it('Given the Stories page, then no story modal is on the page, and each row links to /p/{project}/s/{id}', function () {
    $html = $this->get('/p/alpha/stories')->assertOk()
        ->assertDontSeeLivewire('board.story-modal')
        ->assertDontSeeHtml('story-modal')
        ->assertDontSeeHtml('?story=')
        ->assertDontSeeHtml('aria-haspopup="dialog"')
        ->getContent();

    foreach (['AL-1', 'AL-2'] as $id) {
        preg_match('/<li\b[^>]*data-story-row="'.$id.'".*?<\/li>/s', $html, $row);
        expect($row[0] ?? '')->toMatch('/<a\b[^>]*href="'.preg_quote(url("/p/alpha/s/{$id}"), '/').'"/');
    }
});

it('Given the project tabs, then they are Stories, Preflight, Handbook and Map, with no Dashboard tab', function (string $url, string $current) {
    $html = $this->get($url)->assertOk()->getContent();

    preg_match('/<nav\b[^>]*data-project-tabs.*?<\/nav>/s', $html, $nav);
    preg_match_all('/data-project-tab="([^"]+)"[^>]*>([^<]+)</', $nav[0] ?? '', $tabs);

    expect($tabs[1])->toBe(['stories', 'preflight', 'handbook', 'map'])
        ->and(array_map('trim', $tabs[2]))->toBe(['Stories', 'Preflight', 'Handbook', 'Map'])
        ->and($nav[0])->not->toContain('Dashboard')
        ->and($nav[0])->not->toContain('href="'.url('/p/alpha').'"')
        ->and($nav[0])->toMatch('/data-project-tab="'.$current.'"[^>]*aria-current="page"/');
})->with([
    ['/p/alpha/stories', 'stories'],
    ['/p/alpha/preflight', 'preflight'],
    ['/p/alpha/handbook', 'handbook'],
    ['/p/alpha/map', 'map'],
]);

it('points the project header\'s breadcrumb at the project\'s Stories page, not the old dashboard', function () {
    $html = $this->get('/p/alpha/handbook')->assertOk()->getContent();

    preg_match('/<header\b.*?<\/header>/s', $html, $header);

    expect($header[0] ?? '')->toContain('href="'.url('/p/alpha/stories').'"')
        ->not->toContain('href="'.url('/p/alpha').'"')
        ->not->toContain('Toggle dark mode');
});

it('keeps Flux toasts on the shell pages that raise them, without the old pages\' light/dark appearance script', function (string $url) {
    $this->get($url)->assertOk()
        ->assertSeeHtml('<ui-toast')
        ->assertSeeHtml('/flux/flux')
        ->assertDontSeeHtml('flux.appearance');
})->with(['/projects', '/mockups/alpha/AL-1']);

it('leaves the old home and project dashboard on the board layout, still rendering', function (string $url) {
    $this->get($url)->assertOk()
        ->assertDontSeeHtml('class="shell"')
        ->assertSeeHtml('data-sidebar-project="alpha"');
})->with(['/', '/p/alpha']);
