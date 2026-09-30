<?php

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Tests\Support\GitFixture;

/**
 * SB-21 in a real browser: gallery → viewer → switch option without a page
 * load → compare side by side, stacked at 375px → Esc back; and a pick made
 * against a throwaway fixture checkout only.
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo->story('FX-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n");
    foreach (['a', 'b', 'c'] as $o) {
        $this->repo->write("docs/mockups/FX-1/option-{$o}.html", "<html><body><h1>FX-1 option {$o}</h1></body></html>");
    }
    $this->repo->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $this->repo->syncProject();
    $this->repo->git($this->repo->project, 'config', 'user.name', 'Fixture Owner');
    $this->repo->git($this->repo->project, 'config', 'user.email', 'owner@example.test');
});

afterEach(function () {
    $this->repo->destroy();
});

it('opens a set full screen, switches option without a page load, compares side by side, stacks at 375px, and Esc returns to the gallery', function () {
    $page = visit('/mockups')->resize(1280, 800)
        ->assertSee('Awaiting pick')
        ->click('[data-set="fx/FX-1"] a')
        ->assertPathIs('/mockups/fx/FX-1')
        ->assertScript('document.querySelector("[data-viewer-frame]").getAttribute("src").endsWith("option-a.html")', true);

    // A marker on window survives an Alpine switch but not a page load.
    $page->script('window.__sb21 = 1');
    $page->click('[data-option-tab="c"]')
        ->assertScript('document.querySelector("[data-viewer-frame]").getAttribute("src").endsWith("option-c.html")', true)
        ->assertScript('window.__sb21', 1);

    $page->click('[data-compare-toggle]')
        ->assertVisible('[data-compare-pane="left"]')
        ->assertVisible('[data-compare-pane="right"]');
    $page->select('[data-compare-pane="right"] select', 'c');
    $page->assertScript('document.querySelector("[data-compare-pane=right] iframe").getAttribute("src").endsWith("option-c.html")', true)
        // Side by side: one row, two columns.
        ->assertScript('(() => { const l = document.querySelector("[data-compare-pane=left]").getBoundingClientRect(), r = document.querySelector("[data-compare-pane=right]").getBoundingClientRect(); return l.top === r.top && r.left >= l.right; })()', true);

    $page->resize(375, 800)
        ->assertScript('(() => { const l = document.querySelector("[data-compare-pane=left]").getBoundingClientRect(), r = document.querySelector("[data-compare-pane=right]").getBoundingClientRect(); return r.top >= l.bottom; })()', true)
        ->assertVisible('[data-compare-swap]')
        ->click('[data-compare-swap]')
        ->assertScript('document.querySelector("[data-compare-pane=left] iframe").getAttribute("src").endsWith("option-c.html")', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);

    $page->keys('[data-viewer]', 'Escape')
        ->assertPathIs('/mockups')
        ->assertVisible('[data-set="fx/FX-1"]')
        ->assertNoJavaScriptErrors();
});

it('picks option b with a reason from the viewer, committing only in the fixture checkout', function () {
    visit('/mockups/fx/FX-1')->resize(1280, 800)
        ->click('[data-pick="b"]')
        ->assertVisible('[data-pick-dialog]')
        ->type('#pick-reason', 'cleaner table')
        ->click('[data-pick-dialog] button[type="submit"]')
        ->assertMissing('[data-pick]')
        ->assertSee('Picked B')
        ->assertSee('cleaner table')
        ->assertNoJavaScriptErrors();

    expect(File::get($this->repo->project.'/stories/demo/FX-1-story.md'))->toContain("- Chosen option: b\n")
        ->and(trim($this->repo->git($this->repo->project, 'log', '-1', '--format=%s')))->toBe('docs(FX-1): record mockup pick b');
});

/** Whether the card for a set is on screen (x-show hides it with display:none). */
function setCardVisible(string $set): string
{
    return "(() => { const el = document.querySelector('[data-set=\"{$set}\"]'); return !!el && el.offsetParent !== null; })()";
}

it('opens on awaiting picks only, and All shows the picked sets too', function () {
    $this->repo->story('FX-2', 'draft', 'demo', "## Design mockup gate\n- Chosen option: b\n- Why I chose it: owner pick 2026-09-29, cleaner table\n");
    $this->repo->write('docs/mockups/FX-2/option-a.html', '<html><body>FX-2 a</body></html>');
    $this->repo->write('docs/mockups/FX-2/option-b.html', '<html><body>FX-2 b</body></html>');
    $this->repo->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $this->repo->syncProject();

    visit('/mockups')->resize(1280, 800)
        ->assertAttribute('[data-filter-status="awaiting"]', 'aria-pressed', 'true')
        ->assertScript(setCardVisible('fx/FX-1'), true)
        ->assertScript(setCardVisible('fx/FX-2'), false)
        ->click('[data-filter-status="all"]')
        ->assertScript(setCardVisible('fx/FX-2'), true);
});

it('opens on All when no set is awaiting a pick', function () {
    $this->repo->story('FX-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: a\n- Why I chose it: owner pick 2026-09-29, fine\n");
    $this->repo->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $this->repo->syncProject();

    visit('/mockups')->resize(1280, 800)
        ->assertAttribute('[data-filter-status="all"]', 'aria-pressed', 'true')
        ->assertScript(setCardVisible('fx/FX-1'), true);
});

it('opens on All for a project with nothing awaiting, even when another project has sets awaiting', function () {
    $this->repo->story('FX-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: a\n- Why I chose it: owner pick 2026-09-29, fine\n");
    $this->repo->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $this->repo->syncProject();

    $other = new GitFixture;
    try {
        $other->story('FY-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n");
        $other->write('docs/mockups/FY-1/option-a.html', '<html><body>FY-1 a</body></html>');
        $other->commitAndPush();
        Project::factory()->create(['name' => 'fy', 'path' => $other->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
        $this->artisan('board:refresh', ['project' => 'fy'])->assertSuccessful();
        $other->syncProject();

        visit('/mockups?project=fx')->resize(1280, 800)
            ->assertAttribute('[data-filter-status="all"]', 'aria-pressed', 'true')
            ->assertScript(setCardVisible('fx/FX-1'), true);
        visit('/mockups')->resize(1280, 800)
            ->assertAttribute('[data-filter-status="awaiting"]', 'aria-pressed', 'true')
            ->assertScript(setCardVisible('fy/FY-1'), true);
    } finally {
        $other->destroy();
    }
});

it('opens a set whose route has a journey shot in compare, Current (the shot) left and option a right (SB-23)', function () {
    $this->repo->story('FX-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n\n## Data & interfaces\n- Routes: `GET /widgets`.\n");
    $this->repo->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $this->repo->syncProject();
    $dir = $this->repo->project.'/storage/app/journey-shots/widgets';
    File::ensureDirectoryExists($dir);
    // A real 1×1 PNG, so the browser decodes it and the test can see it loaded.
    File::put("{$dir}/01-widgets.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    File::put("{$dir}/manifest.json", json_encode([['route' => '/widgets', 'captured_at' => now()->subDay()->toIso8601String(), 'commit' => 'abc1234', 'file' => '01-widgets.png']]));

    visit('/mockups/fx/FX-1')->resize(1280, 800)
        ->assertVisible('[data-compare-pane="left"] [data-current-pane="shot"] img')
        ->assertScript('document.querySelector("[data-compare-pane=left] img").naturalWidth', 1)
        ->assertScript('document.querySelector("[data-compare-pane=right] iframe").getAttribute("src").endsWith("option-a.html")', true)
        ->assertSee('abc1234')
        ->assertNoJavaScriptErrors();
});
