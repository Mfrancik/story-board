<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SessionFixture;

/**
 * RD-2 in a real browser: the dark shell's sidebar is a drawer behind a menu button below 768 px
 * (Alpine, no server round-trip) and a fixed column above it, the page never scrolls sideways at
 * 375 px, and the body is set in the self-hosted Geist. Temp Claude projects root; never ~/.claude.
 */
beforeEach(function () {
    Queue::fake();
    $this->claude = new SessionFixture;
    config(['board.sessions_path' => $this->claude->root]);
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    Project::factory()->create(['name' => 'coins', ...$fresh]);
    Project::factory()->create(['name' => 'client-dashboard', ...$fresh]);
});

afterEach(function () {
    $this->claude->destroy();
});

/** Script: the menu button's rendered height and width, the smaller of the two (the 44 px target rule). */
const SHELL_MENU_SIZE = "(() => { const r = document.querySelector('[data-shell-menu]').getBoundingClientRect(); return Math.min(r.width, r.height) >= 44; })()";

it('Given a 375 px viewport, then the sidebar is hidden behind a menu button that opens and closes it, and the page does not scroll sideways', function () {
    $page = visit('/p/coins/preflight')->resize(375, 812);

    $page->assertMissing('[data-shell-sidebar]')
        ->assertVisible('[data-shell-menu]')
        ->assertScript(SHELL_MENU_SIZE, true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->click('[data-shell-menu]')
        ->assertVisible('[data-shell-sidebar]')
        ->assertVisible('[data-nav-project="coins"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->click('[data-shell-close]')
        ->assertMissing('[data-shell-sidebar]')
        ->click('[data-shell-menu]')
        ->assertVisible('[data-shell-sidebar]')
        ->keys('[data-shell-sidebar]', 'Escape')
        ->assertMissing('[data-shell-sidebar]')
        ->assertNoJavaScriptErrors();
});

it('shows the sidebar as a column with no menu button at 1280 px, the current project marked, set in Geist', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->assertVisible('[data-shell-sidebar]')
        ->assertMissing('[data-shell-menu]')
        ->assertAttribute('[data-nav-project="coins"]', 'aria-current', 'page')
        ->assertScript("getComputedStyle(document.body).fontFamily.startsWith('Geist')", true)
        ->assertScript("document.fonts.ready.then(() => document.fonts.check('16px Geist'))", true)
        ->assertNoJavaScriptErrors();
});
