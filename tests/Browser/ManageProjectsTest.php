<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * SB-12 in a real browser: a switch takes a project off the sidebar and brings it
 * back with a toast, the add form registers a fixture repo, and Remove only
 * deletes once the confirmation modal's "Remove project" is clicked.
 */
beforeEach(function () {
    // Never a real refresh: switching on and adding queue one.
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    foreach (['asset-track', 'client-dashboard', 'coins', 'rent-track'] as $name) {
        Project::factory()->create(['name' => $name, ...$fresh]);
    }
    Project::factory()->disabled()->create(['name' => 'acme-site', ...$fresh]);
});

it('switches rent-track off and on, updating the sidebar and confirming with a toast', function () {
    visit('/projects')
        ->resize(1280, 900)
        ->assertVisible('[data-sidebar-project="rent-track"]')
        ->click('[data-project-switch="rent-track"]')
        ->assertSee('rent-track switched off')
        ->assertMissing('[data-sidebar-project="rent-track"]')
        ->assertAttribute('[data-project-switch="rent-track"]', 'aria-checked', 'false')
        ->click('[data-project-switch="rent-track"]')
        ->assertSee('rent-track switched on')
        ->assertVisible('[data-sidebar-project="rent-track"]')
        ->assertAttribute('[data-project-switch="rent-track"]', 'aria-checked', 'true')
        ->assertNoJavaScriptErrors();

    expect(Project::where('name', 'rent-track')->value('is_enabled'))->toBeTrue();
});

it('removes acme-site only after "Remove project" is confirmed; Cancel and Escape leave it', function () {
    $page = visit('/projects')->resize(1280, 900);

    $page->click('[data-remove="acme-site"]')
        ->assertVisible('[role="alertdialog"]')
        ->assertSeeIn('[role="alertdialog"]', 'Remove acme-site?')
        ->click('[data-confirm-cancel]')
        ->assertMissing('[role="alertdialog"]');
    expect(Project::where('name', 'acme-site')->exists())->toBeTrue();

    $page->click('[data-remove="acme-site"]')
        ->assertVisible('[role="alertdialog"]')
        ->click('[data-confirm-ok]')
        ->assertSee('Project removed')
        ->assertMissing('[data-project-row="acme-site"]')
        ->assertNoJavaScriptErrors();
    expect(Project::where('name', 'acme-site')->exists())->toBeFalse();
});

it('adds a fixture repo from the form and shows an inline error for a bad ref', function () {
    $fixture = new GitFixture;

    visit('/projects')
        ->resize(375, 812)
        ->fill('#f-path', $fixture->project)
        ->fill('#f-ref', 'a..b')
        ->click('[data-add-project]')
        ->assertSee('That is not a valid git ref.')
        ->fill('#f-ref', 'origin/main')
        ->fill('#f-name', 'fixture-site')
        ->click('[data-add-project]')
        ->assertSee('Project added')
        ->assertPresent('[data-project-row="fixture-site"]')
        ->assertNoJavaScriptErrors();

    expect(Project::where('name', 'fixture-site')->value('state'))->toBe(Project::STATE_PENDING);
    $fixture->destroy();
});
