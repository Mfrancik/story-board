<?php

use App\Models\Project;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * RD-8 in a real browser, standing in for the story's manual check (one hand-written brain record beside the
 * project's CSV) against a fixture sessions root, never the real ~/.claude: the matched row reads Brain, the
 * Where it ran filter narrows to it with no request to the server, and the project's home tile reads "on brain".
 */
beforeEach(function () {
    $this->claude = new SessionFixture;
    config(['board.sessions_path' => $this->claude->root]);
    // A real repo, so the home page's Shipped this week reads it as it does for any project.
    $this->fixture = new GitFixture;
    $this->fixture->write('a.txt', '1')->commitAt('feat(SB-1): first', now()->subDays(1));
    $this->fixture->git($this->fixture->project, 'fetch', '--quiet');
    Project::factory()->create(['name' => 'story-board', 'path' => $this->fixture->project,
        'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)]);

    $folder = SessionFixture::folderFor($this->fixture->project);
    $at = fn (int $hoursAgo, int $plusMinutes = 0) => now()->subHours($hoursAgo)->addMinutes($plusMinutes)->utc()->format('Y-m-d\TH:i:s\Z');
    $this->claude->preflightCsv($folder, [
        'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model',
        $at(2).',story-board,feat/RD-8-brain,scoped,180,4,4,1000,10,0,0,sonnet',
        $at(5).',story-board,feat/RD-8-mac,scoped,700,4,4,1000,10,0,0,sonnet',
        $at(9).',story-board,feat/RD-8-unknown,scoped,500,4,4,1000,10,0,0,sonnet',
    ]);
    $record = fn (string $id, string $branch, string $runner, string $finished) => [
        'schema' => 1, 'run_id' => $id, 'project' => 'story-board', 'branch' => $branch, 'sha' => str_repeat('b', 40),
        'runner' => $runner, 'host' => $runner === 'brain' ? 'brain' : 'mac', 'mode' => 'scoped', 'started_at' => $finished,
        'finished_at' => $finished, 'wall_s' => 170, 'verdict' => 'GO', 'gates' => [['name' => 'pest', 'result' => 'pass', 'seconds' => 120]],
    ];
    $this->claude->preflightRun($folder, 'b1', $record('b1', 'feat/RD-8-brain', 'brain', $at(2, 4)));
    $this->claude->preflightRun($folder, 'm1', $record('m1', 'feat/RD-8-mac', 'local', $at(5, 1)));
});

afterEach(function () {
    $this->claude->destroy();
    $this->fixture->destroy();
});

/** Script: the branches of the table rows a person can see, in page order. */
const RAN_ON_VISIBLE = "[...document.querySelectorAll('[data-run]')].filter(e => e.offsetParent !== null).map(e => e.querySelector('[data-col=branch]').textContent.trim()).join()";

/** Script: fetch requests made so far (Livewire's requests are fetches). */
const RAN_ON_FETCHES = "performance.getEntriesByType('resource').filter(e => e.initiatorType === 'fetch').length";

it('reads Brain on the matched row and the Brain filter narrows to it without a server request', function () {
    $page = visit('/p/story-board/preflight')->resize(1280, 900);

    $page->assertSeeIn('[data-run="0"] [data-col=ran_on]', 'Brain')
        ->assertSeeIn('[data-run="1"] [data-col=ran_on]', 'Mac')
        ->assertSeeIn('[data-run="2"] [data-col=ran_on]', '—')
        ->assertSeeIn('[data-runner-wall="brain"] [data-median]', '3:00')
        ->assertSeeIn('[data-runner-wall="mac"] [data-median]', '11:40');
    $before = $page->script(RAN_ON_FETCHES);

    $page->click('[data-ran-on-filter="brain"]')
        ->assertScript(RAN_ON_VISIBLE, 'feat/RD-8-brain')
        ->assertSeeIn('[data-runs-shown]', '1 of 3 runs')
        // One runner left in view: nothing to compare.
        ->assertMissing('[data-runner-walls]')
        ->click('[data-ran-on-filter="unknown"]')
        ->assertScript(RAN_ON_VISIBLE, 'feat/RD-8-unknown')
        ->click('[data-ran-on-filter="all"]')
        ->assertScript(RAN_ON_VISIBLE, 'feat/RD-8-brain,feat/RD-8-mac,feat/RD-8-unknown')
        ->assertScript(RAN_ON_FETCHES, $before)
        ->assertNoJavaScriptErrors();
});

it('hides and restores the Ran on column from the Columns picker', function () {
    $page = visit('/p/story-board/preflight')->resize(1280, 900);

    $page->click('[data-columns-button]')
        ->click('[data-col-toggle="ran_on"]')
        ->assertMissing('[data-run="0"] [data-col=ran_on]')
        ->click('[data-columns-reset]')
        ->assertVisible('[data-run="0"] [data-col=ran_on]')
        ->assertNoJavaScriptErrors();
});

it('shows "on brain" in the project\'s home tile when its last run matched a brain record', function () {
    $page = visit('/')->resize(1280, 900);

    $page->assertSeeIn('[data-tile="story-board"] [data-tile-ran-on]', 'on brain')
        ->assertNoJavaScriptErrors();
});
