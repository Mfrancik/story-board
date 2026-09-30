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
