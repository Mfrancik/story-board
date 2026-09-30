<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * SB-24 in a real browser: All flows marks the shared screen and lights it in
 * both lanes on hover → pick a flow → placeholders for built steps, the chosen
 * mockup for a pending one → open step 1 large → Next through to the end,
 * where Next is disabled and the step stays → ← and → keys step → Escape
 * closes, all without a page load; and the page fits 375px. Fixture repo only.
 */
beforeEach(function () {
    Queue::fake();
    $this->repo = new GitFixture;
    $routes = fn (string $r) => "## Data & interfaces\n- Routes: `GET {$r}` (`x`).\n";
    $this->repo->story('FX-1', 'built', 'demo', $routes('/register'));
    $this->repo->story('FX-2', 'built', 'demo', $routes('/dashboard'));
    $this->repo->story('FX-3', 'approved', 'demo', "## Design mockup gate\n- Chosen option: b\n- Why I chose it: fixture\n\n".$routes('/settings'));
    foreach (['a', 'b'] as $o) {
        $this->repo->write("docs/mockups/FX-3/option-{$o}.html", "<html><body><h1>FX-3 option {$o}</h1></body></html>");
    }
    $this->repo->write('docs/journeys/sign-up.md', "# Journey — Sign up\n\n## Flow (plain steps)\n1. Register. (FX-1)\n2. Land on the dashboard. (FX-2)\n3. Say what you collect. (FX-3)\n");
    $this->repo->write('docs/journeys/come-back.md', "# Journey — Come back\n\n## Flow (plain steps)\n1. Land on the dashboard again. (FX-2)\n");
    $this->repo->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
});

afterEach(function () {
    $this->repo->destroy();
});

it('Given a step open large, when Next is pressed on the last step, then it stays and Next is disabled; the whole map steps through with no page load', function () {
    $page = visit('/p/fx/map')->resize(1280, 900)
        ->assertVisible('[data-overview]')
        ->assertAttribute('[data-step="come-back/1"]', 'data-shared', 'A')
        ->assertAttribute('[data-step="sign-up/2"]', 'data-shared', 'A');
    // A marker on window survives Alpine but not a page load.
    $page->script('window.__sb24 = 1');

    // Hovering a shared screen dims the screens that are not it, in every lane.
    $page->hover('[data-step="come-back/1"] button')
        ->assertScript('document.querySelector("[data-step=\'sign-up/1\'] button").classList.contains("opacity-35")', true)
        ->assertScript('document.querySelector("[data-step=\'sign-up/2\'] button").classList.contains("opacity-35")', false);

    $page->click('[data-flow="sign-up"]')
        ->assertVisible('[data-journey-view="sign-up"]')
        ->assertAttribute('[data-stage-step="sign-up/1"] [data-picture]', 'data-picture', 'placeholder')
        ->assertAttribute('[data-strip-step="sign-up/3"] [data-picture]', 'data-picture', 'mockup')
        ->click('[data-open-large]')
        ->assertAttribute('[data-stage]', 'role', 'dialog')
        ->click('[data-lightbox-next]')
        ->assertPresent('[data-stage-step="sign-up/2"]')
        ->click('[data-lightbox-next]')
        ->assertPresent('[data-stage-step="sign-up/3"]')
        ->assertScript('document.querySelector("[data-lightbox-next]").disabled', true)
        ->assertScript('document.querySelector("[data-next]").disabled', true);

    // Next on the last step: it stays, by button and by key.
    $page->script('document.querySelector("[data-lightbox-next]").click()');
    $page->keys('[data-stage]', 'ArrowRight')
        ->assertPresent('[data-stage-step="sign-up/3"]')
        ->assertAttribute('[data-stage-step="sign-up/3"] [data-picture]', 'data-picture', 'mockup')
        ->keys('[data-stage]', 'ArrowLeft')
        ->assertPresent('[data-stage-step="sign-up/2"]')
        ->keys('[data-stage]', 'Escape')
        ->assertScript('document.querySelector("[data-stage]").getAttribute("role")', null)
        ->assertScript('window.__sb24', 1);

    // Back to All flows.
    $page->click('[data-flow="all"]')->assertVisible('[data-overview]')->assertScript('window.__sb24', 1);
});

it('fits a 375px phone with no sideways page scroll, on All flows and on one flow', function () {
    $page = visit('/p/fx/map')->resize(375, 800)
        ->assertVisible('[data-overview]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);

    $page->click('[data-flow="sign-up"]')
        ->assertVisible('[data-journey-view="sign-up"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
});
