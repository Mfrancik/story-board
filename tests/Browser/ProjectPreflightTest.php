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

/*
 * SB-19: the Columns picker. Pure Alpine over the page's own markup, remembered per project in
 * this browser's localStorage under PREFLIGHT_COLUMNS_KEY + project name.
 */

/** The localStorage key prefix the picker saves under; the project's name completes it. */
const PREFLIGHT_COLUMNS_KEY = 'board.preflight.hidden-columns.';

/** Script: how many of a column's header and cells a person can see (1 header + 5 runs when shown). */
function preflightColumnShown(string $col): string
{
    return "[...document.querySelectorAll('[data-col={$col}]')].filter(e => e.offsetParent !== null).length";
}

/** Script: every column's header shown, as a comma list of its keys, in table order. */
const PREFLIGHT_HEADERS = "[...document.querySelectorAll('thead th[data-col]')].filter(e => e.offsetParent !== null).map(e => e.dataset.col).join()";

/** Script: the picker's checkboxes as key:checked:disabled, in list order. */
const PREFLIGHT_TOGGLES = "[...document.querySelectorAll('[data-col-toggle]')].map(e => e.dataset.colToggle + ':' + (e.checked ? 1 : 0) + ':' + (e.disabled ? 1 : 0)).join()";

const PREFLIGHT_ALL_COLUMNS = 'when,branch,where,mode,wall,turns,tools,tokens,share,pack,tier,tests';

it('Given the Preflight page loads, then a Columns control lists every column except that When is disabled and always ticked', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->assertScript("document.querySelector('[data-columns-button]').textContent.replace(/\\s+/g, ' ').trim()", 'Columns')
        ->assertMissing('[data-columns-panel]')
        ->click('[data-columns-button]')
        ->assertVisible('[data-columns-panel]')
        ->assertScript(PREFLIGHT_TOGGLES, 'when:1:1,branch:1:0,where:1:0,mode:1:0,wall:1:0,turns:1:0,tools:1:0,tokens:1:0,share:1:0,pack:1:0,tier:1:0,tests:1:0')
        ->assertSeeIn('[data-columns-panel]', 'Tool calls')
        ->assertSeeIn('[data-columns-panel]', 'Audit tier')
        // The disabled box says why, where a person hovering it will read it.
        ->assertAttributeContains('[data-col-option="when"]', 'title', 'always shown')
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertNoJavaScriptErrors();
});

it('Given Tokens is unticked, then the Tokens header and every Tokens cell are hidden, without a server request', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->assertScript(preflightColumnShown('tokens'), 6);
    $before = $page->script(PREFLIGHT_FETCHES);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="tokens"]')
        ->assertScript(preflightColumnShown('tokens'), 0)
        ->assertScript(PREFLIGHT_HEADERS, 'when,branch,where,mode,wall,turns,tools,share,pack,tier,tests')
        // Every other column is untouched: each still has its header and its 5 cells.
        ->assertScript(preflightColumnShown('turns'), 6)
        ->assertScript(PREFLIGHT_FETCHES, $before)
        ->assertNoJavaScriptErrors();
});

it('Given Tokens is ticked again, then the column shows again', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="tokens"]')
        ->assertScript(preflightColumnShown('tokens'), 0)
        ->click('[data-col-toggle="tokens"]')
        ->assertScript(preflightColumnShown('tokens'), 6)
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertScript("document.querySelector('[data-col-toggle=tokens]').checked", true)
        ->assertNoJavaScriptErrors();
});

it('Given two columns are hidden, then the control reads "Columns · 2 hidden"', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="turns"]')
        ->assertSeeIn('[data-columns-button]', 'Columns · 1 hidden')
        ->click('[data-col-toggle="pack"]')
        ->assertScript("document.querySelector('[data-columns-button]').textContent.replace(/\\s+/g, ' ').trim()", 'Columns · 2 hidden')
        ->assertNoJavaScriptErrors();
});

it('Given columns were hidden and the page is reloaded, then the same columns are still hidden', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="turns"]')
        ->click('[data-col-toggle="tools"]')
        ->click('[data-col-toggle="pack"]')
        ->refresh()
        ->assertScript(PREFLIGHT_HEADERS, 'when,branch,where,mode,wall,tokens,share,tier,tests')
        ->assertScript(preflightColumnShown('tools'), 0)
        ->assertSeeIn('[data-columns-button]', 'Columns · 3 hidden')
        ->click('[data-columns-button]')
        ->assertScript("document.querySelector('[data-col-toggle=tools]').checked", false)
        ->assertNoJavaScriptErrors();
});

it('Given hidden columns for coins, when another project\'s Preflight page opens, then all its columns show', function () {
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    Project::factory()->create(['name' => 'acme', 'path' => '/Users/me/Code/acme', ...$fresh]);
    $this->claude->preflightCsv(SessionFixture::folderFor('/Users/me/Code/acme'), [
        'ts,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model',
        now()->subDay()->utc()->format('Y-m-d\TH:i:s\Z').',main,scoped,60,1,1,100,1,0,0,sonnet',
    ]);
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="tokens"]')
        ->navigate('/p/acme/preflight')
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertScript("document.querySelector('[data-columns-button]').textContent.replace(/\\s+/g, ' ').trim()", 'Columns')
        // And coins kept its own choice: the key is per project, not one shared setting.
        ->navigate('/p/coins/preflight')
        ->assertScript(preflightColumnShown('tokens'), 0)
        ->assertNoJavaScriptErrors();
});

it('Given Reset columns is clicked, then every column shows and the saved choice is cleared', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="turns"]')
        ->click('[data-col-toggle="tier"]')
        ->assertScript("localStorage.getItem('".PREFLIGHT_COLUMNS_KEY."coins')", '["turns","tier"]')
        ->click('[data-columns-reset]')
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertScript("document.querySelector('[data-columns-button]').textContent.replace(/\\s+/g, ' ').trim()", 'Columns')
        ->assertScript("localStorage.getItem('".PREFLIGHT_COLUMNS_KEY."coins')", null)
        ->refresh()
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertNoJavaScriptErrors();
});

it('Given localStorage throws, then the page renders all columns and the control still hides and shows columns', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);
    // A saved choice exists, so "all columns" below proves the read failed safe rather than found nothing.
    $page->click('[data-columns-button]')->click('[data-col-toggle="tokens"]')->assertScript(preflightColumnShown('tokens'), 0);

    // Storage blocked the way a browser blocks it: a SecurityError from every call this page's own
    // code makes. Flux's `flux.appearance` reads are spared: Flux reads that key with no guard (in
    // <head> and again on alpine:init), which is vendor code outside this story and would fail
    // assertNoJavaScriptErrors for a reason that is not ours.
    $page->page()->context()->addInitScript(<<<'JS'
        for (const m of ['getItem', 'setItem', 'removeItem']) {
            const real = Storage.prototype[m];
            Storage.prototype[m] = function (key, ...rest) {
                if (String(key).startsWith('flux.')) return real.call(this, key, ...rest);
                throw new DOMException('The operation is insecure.', 'SecurityError');
            };
        }
    JS);

    $page->refresh()
        ->assertScript("(() => { try { localStorage.getItem('x'); return 'readable'; } catch (e) { return e.name; } })()", 'SecurityError')
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertScript(preflightColumnShown('tokens'), 6)
        ->click('[data-columns-button]')
        ->click('[data-col-toggle="tokens"]')
        ->assertScript(preflightColumnShown('tokens'), 0)
        ->assertSeeIn('[data-columns-button]', 'Columns · 1 hidden')
        ->click('[data-col-toggle="tokens"]')
        ->assertScript(preflightColumnShown('tokens'), 6)
        ->click('[data-col-toggle="pack"]')
        ->click('[data-columns-reset]')
        ->assertScript(PREFLIGHT_HEADERS, PREFLIGHT_ALL_COLUMNS)
        ->assertNoJavaScriptErrors();
});

it('Given a filter (mode = scoped) and a hidden column, then both apply together', function () {
    $page = visit('/p/coins/preflight')->resize(1280, 900);

    $page->click('[data-mode-filter="scoped"]')
        ->click('[data-columns-button]')
        ->click('[data-col-toggle="tokens"]')
        ->assertScript(PREFLIGHT_VISIBLE, 'main,feat/GS-29-30-31-fields-decide')
        ->assertSeeIn('[data-runs-shown]', '2 of 5 runs')
        // The header plus the two scoped runs' cells; the hidden rows' cells stay hidden too.
        ->assertScript(preflightColumnShown('turns'), 3)
        ->assertScript(preflightColumnShown('tokens'), 0)
        // The trend strip still follows the filter, not the columns.
        ->assertSeeIn('[data-trend="runs"] [data-current]', '1')
        ->click('[data-mode-filter="all"]')
        ->assertScript(preflightColumnShown('turns'), 6)
        ->assertScript(preflightColumnShown('tokens'), 0)
        ->assertNoJavaScriptErrors();
});

it('Given a 375 px viewport, then the Columns list opens without horizontal page scroll', function () {
    $page = visit('/p/coins/preflight')->resize(375, 812);

    $page->click('[data-columns-button]')
        ->assertVisible('[data-columns-panel]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript("(() => { const r = document.querySelector('[data-columns-panel]').getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()", true)
        ->click('[data-col-toggle="branch"]')
        ->assertScript(preflightColumnShown('branch'), 0)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
