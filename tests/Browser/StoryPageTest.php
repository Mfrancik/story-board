<?php

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Tests\Support\GitFixture;

/**
 * RD-3 in a real browser: on a story awaiting a pick, the option buttons switch
 * the frame and the primary action without a page load, and Pick writes the
 * story file — in a throwaway fixture checkout only. A long built story fits on
 * one screen with its Details closed, at desktop and phone width.
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo->story('FX-1', 'draft', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n");
    foreach (['a', 'b', 'c'] as $o) {
        $this->repo->write("docs/mockups/FX-1/option-{$o}.html", "<html><body><h1>FX-1 option {$o}</h1></body></html>");
    }
    // A long built story: hundreds of lines the page must keep folded.
    $long = "## Acceptance criteria\n".implode('', array_map(fn ($i) => "- Given case {$i}, then it works.\n", range(1, 40)))
        ."\n## Notes\n".str_repeat("A line of notes that nobody reads on the first visit.\n", 300);
    $this->repo->story('FX-2', 'built', 'demo', $long)->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $this->repo->syncProject();
    $this->repo->git($this->repo->project, 'config', 'user.name', 'Fixture Owner');
    $this->repo->git($this->repo->project, 'config', 'user.email', 'owner@example.test');
});

afterEach(function () {
    $this->repo->destroy();
});

it('switches options without a page load and picks option B with a reason into the story file', function () {
    $page = visit('/p/fx/s/FX-1')->resize(1280, 800)
        ->assertSeeIn('[data-primary-action]', 'Pick option A')
        ->assertScript('document.querySelector("[data-mockup-frame]").getAttribute("src").endsWith("option-a.html")', true);

    // A marker on window survives an Alpine switch but not a page load.
    $page->script('window.__rd3 = 1');
    $page->click('[data-mockup-option="b"]')
        ->assertSeeIn('[data-primary-action]', 'Pick option B')
        ->assertScript('document.querySelector("[data-mockup-frame]").getAttribute("src").endsWith("option-b.html")', true)
        ->assertScript('window.__rd3', 1);

    $page->type('#pick-reason', 'calmer layout')
        ->click('[data-primary-action]')
        ->assertVisible('[data-pick-done]')
        ->assertSee('Picked B')
        ->assertMissing('[data-primary-action]');

    expect(File::get($this->repo->project.'/stories/demo/FX-1-story.md'))->toContain("- Chosen option: b\n")
        ->toContain('(board): calmer layout');
});

it('fits a long built story on one screen with every Details section closed, at 1280 and 375 px', function () {
    $page = visit('/p/fx/s/FX-2')->resize(1280, 800)
        ->assertSee('Story FX-2')
        ->assertScript('[...document.querySelectorAll("details")].every((d) => ! d.open)', true)
        ->assertScript('document.documentElement.scrollHeight <= window.innerHeight', true)
        ->assertMissing('[data-story-mockups]');

    $page->resize(375, 800)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
});
