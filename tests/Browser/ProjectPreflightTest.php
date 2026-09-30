<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SessionFixture;

/**
 * SB-16 in a real browser: the Preflight tab lists the runs with both trend charts drawn, the mode,
 * branch and where filters narrow the table and the figures with no request to the server, hovering
 * a row marks its run on the charts, and the page fits 375px with no sideways scroll. Fixture CSVs
 * in a temp Claude projects root; never the real ~/.claude.
 */
beforeEach(function () {
    Queue::fake();
    $this->claude = new SessionFixture;
    config(['board.sessions_path' => $this->claude->root]);
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    Project::factory()->create(['name' => 'coins', 'path' => '/Users/me/Code/coins', ...$fresh]);

    $folder = SessionFixture::folderFor('/Users/me/Code/coins');
    $ts = fn (int $daysAgo) => now()->subDays($daysAgo)->utc()->format('Y-m-d\TH:i:s\Z');
    // Old shape (coins): two scoped, one full, one with a blank mode; one opus+sonnet audit.
    $this->claude->preflightCsv($folder, [
        'ts,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model',
        $ts(2).',main,scoped,1223,2,2,567042,1021,0,61,sonnet',
        $ts(5).',feat/ADMIN-24-tenant-console,,826,4,4,515663,1273,0,0,',
        $ts(9).',feat/DLR-12-the-seller-application-form,full,1553,34,45,5853613,11787,2259296,47206,opus+sonnet',
        $ts(40).',feat/GS-29-30-31-fields-decide,scoped,1492,88,97,12780499,21902,7134628,48947,sonnet',
    ]);
    // New shape, from a worktree.
    $this->claude->preflightCsv($folder.'--claude-worktrees-sb-15', [
        'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model',
        $ts(1).',x,feat/SB-15-stories-by-initiative,?,195,5,4,343061,1724,12072,16078,sonnet',
    ]);
});

afterEach(function () {
    $this->claude->destroy();
});

/** Script: the branches of the table rows a person can see, in page order. */
const PREFLIGHT_VISIBLE = "[...document.querySelectorAll('[data-run]')].filter(e => e.offsetParent !== null).map(e => e.querySelector('[data-col=branch]').textContent.trim()).join()";

/** Script: fetch requests made so far (Livewire's requests are fetches). */
const PREFLIGHT_FETCHES = "performance.getEntriesByType('resource').filter(e => e.initiatorType === 'fetch').length";

it('lists the runs newest first with both charts drawn, and the scoped filter leaves only scoped rows without a server request', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->assertScript(PREFLIGHT_VISIBLE, 'feat/SB-15-stories-by-initiative,main,feat/ADMIN-24-tenant-console,feat/DLR-12-the-seller-application-form,feat/GS-29-30-31-fields-decide')
        ->assertSeeIn('[data-run="0"] [data-col=where]', 'sb-15')
        ->assertSeeIn('[data-run="3"] [data-col=tier]', 'check the tier')
        ->assertScript("document.querySelectorAll('[data-chart] svg circle').length", 10)
        ->assertScript("document.querySelectorAll('[data-chart] svg path').length", 2)
        ->assertSeeIn('[data-trend="runs"] [data-current]', '4')
        ->assertSeeIn('[data-trend="runs"] [data-previous]', '1');
    $before = $page->script(PREFLIGHT_FETCHES);

    $page->click('[data-mode-filter="scoped"]')
        ->assertScript(PREFLIGHT_VISIBLE, 'main,feat/GS-29-30-31-fields-decide')
        ->assertSeeIn('[data-runs-shown]', '2 of 5 runs')
        ->assertSeeIn('[data-trend="runs"] [data-current]', '1')
        ->assertScript("document.querySelectorAll('[data-chart] svg circle').length", 4)
        ->click('[data-mode-filter="all"]')
        ->type('[data-branch-filter]', 'ADMIN')
        ->assertScript(PREFLIGHT_VISIBLE, 'feat/ADMIN-24-tenant-console')
        ->clear('[data-branch-filter]')
        ->select('[data-where-filter]', 'worktrees')
        ->assertScript(PREFLIGHT_VISIBLE, 'feat/SB-15-stories-by-initiative')
        ->select('[data-where-filter]', 'main')
        ->type('[data-branch-filter]', 'no-such-branch')
        ->assertVisible('[data-no-match]')
        ->assertScript(PREFLIGHT_FETCHES, $before)
        ->assertNoJavaScriptErrors();
});

it('marks a hovered row\'s run on both charts', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->assertScript("document.querySelectorAll('[data-chart] svg circle[r=\"5\"]').length", 0)
        ->hover('[data-run="2"] [data-col=branch]')
        ->assertScript("document.querySelectorAll('[data-chart] svg circle[r=\"5\"]').length", 2)
        ->assertNoJavaScriptErrors();
});

it('is reached from the project tabs and fits 375px with no sideways scroll', function () {
    $page = visit('/p/coins/stories')->resize(375, 812);

    $page->click('[data-project-tab="preflight"]')
        ->assertPathIs('/p/coins/preflight')
        ->assertVisible('[data-run="0"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
