<?php

use App\Livewire\Board\LiveSessions;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Story;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * SB-11 acceptance criteria: the Live now panel reads Claude Code session metadata
 * (cwd, gitBranch, times) from a transcripts root and links each session to the story
 * its branch is building. Fixtures are hand-written JSONL files in a temp root shaped
 * like the real ones checked on 2026-09-29 (coins worktree `set42` on
 * `feat/SET-42-lock-a-coin-in-its-slot`, `integrate/bullion-phone-1`, `docs/PRF-…`).
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo
        ->write('stories/sets/SET-42-lock-a-coin-in-its-slot.md', implode("\n", [
            '# SET-42 — Lock a coin in its slot',
            'Status: approved          Journey: none',
            'Source: owner 2026-09-20',
            '',
            '## Story',
            'Once a coin is placed in a set slot, it stays there until the owner moves it. The slot shows a lock.',
            '',
            '## Design mockup gate',
            '- Mockups: docs/mockups/SET-42/option-{a,b}.html',
            '- Chosen option: b',
            '',
            '## Links',
            'Journey: none · Depends on: none',
            '',
        ]))
        ->write('docs/mockups/SET-42/option-a.html', '<p>A</p>')
        ->write('docs/mockups/SET-42/option-b.html', '<p>B</p>')
        ->story('MOB-46', 'approved', 'mobile')
        ->commitAndPush();
    $this->coins = Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    $this->coins->update(['indexed_at' => now(), 'refresh_attempted_at' => now()]);

    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $this->set42 = $this->repo->project.'/.claude/worktrees/set42';
});

afterEach(function () {
    $this->repo->destroy();
    $this->sessions->destroy();
});

it('shows a coins card for worktree set42 with its branch, "active 2 min ago", SET-42 with its one-line description and the option-b thumbnail', function () {
    expect(Story::onRef()->where('story_id', 'SET-42')->sole()->mockups['chosen'])->toBe('b');
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000001', 2);

    $response = $this->get('/')->assertOk()
        ->assertSee('Live now')
        ->assertSeeHtml('data-live-session')
        ->assertSeeHtml('data-live-project="coins"')
        ->assertSee('worktree set42')
        ->assertSee('feat/SET-42-lock-a-coin-in-its-slot')
        ->assertSee('active 2 min ago')
        ->assertSeeHtml('data-live-story="coins/SET-42"')
        ->assertSee('Lock a coin in its slot')
        ->assertSee('Once a coin is placed in a set slot, it stays there until the owner moves it.')
        ->assertDontSee('The slot shows a lock.');

    $thumb = Story::onRef()->where('story_id', 'SET-42')->sole()->mockupUrl('option-b.html');
    $response->assertSeeHtml('data-live-thumb="b"')->assertSeeHtml('src="'.$thumb.'"');
});

it('does not show a session file last modified 11 minutes ago', function () {
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000002', 11);

    $this->get('/')->assertOk()
        ->assertDontSeeHtml('data-live-session')
        ->assertSee('No live sessions');
});

it('links the off-main row on the session\'s branch when the branch name carries no ID', function () {
    Story::factory()->for($this->coins)->create([
        'story_id' => 'BUL-70', 'title' => 'Bullion on the phone', 'status' => 'draft',
        'location_kind' => Story::KIND_BRANCH, 'location' => 'branch integrate/bullion-phone-1', 'branch' => 'integrate/bullion-phone-1',
    ]);
    $this->sessions->session($this->repo->project, 'integrate/bullion-phone-1', 'aaaaaaaa-0000-0000-0000-000000000003');

    $this->get('/')->assertOk()
        ->assertSee('main checkout')
        ->assertSeeHtml('data-live-story="coins/BUL-70"')
        ->assertSee('Bullion on the phone')
        ->assertDontSee('No story linked');
});

it('reads "No story linked" for docs/PRF-browser-flake-stories (no number) and for a detached HEAD', function (string $branch) {
    // An off-main row on a branch literally named HEAD must still not link a detached session.
    Story::factory()->for($this->coins)->create(['story_id' => 'PRF-9', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch HEAD', 'branch' => 'HEAD']);
    $this->sessions->session($this->repo->project.'/.claude/worktrees/prf7ef', $branch, 'aaaaaaaa-0000-0000-0000-000000000004');

    $this->get('/')->assertOk()
        ->assertSeeHtml('data-live-session')
        ->assertSee('No story linked')
        ->assertDontSeeHtml('data-live-story=');
})->with(['docs/PRF-browser-flake-stories', 'HEAD']);

it('links no story from a lower-case branch like integrate/bul-65-66-mob-46 (IDs match upper case only)', function () {
    $this->sessions->session($this->repo->project, 'integrate/bul-65-66-mob-46', 'aaaaaaaa-0000-0000-0000-000000000005');

    $this->get('/')->assertOk()
        ->assertSee('No story linked')
        ->assertDontSeeHtml('data-live-story=');
});

it('does not show a session whose cwd is in no registered project, or in a disabled one, and logs why', function () {
    Log::spy();
    $off = Project::factory()->create(['name' => 'rent-track', 'path' => '/code/rent-track', 'is_enabled' => false]);
    $this->sessions->session('/code/story-board', 'feat/SB-11-live-sessions', 'aaaaaaaa-0000-0000-0000-000000000006');
    $this->sessions->session($off->path.'/.claude/worktrees/x', 'feat/RT-1-x', 'aaaaaaaa-0000-0000-0000-000000000007');

    $this->get('/')->assertOk()
        ->assertDontSeeHtml('data-live-session')
        ->assertSee('No live sessions');

    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.session_ignored' && $c['reason'] === 'unregistered' && $c['cwd'] === '/code/story-board')->atLeast()->once();
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.session_ignored' && $c['reason'] === 'disabled' && $c['project'] === 'rent-track')->atLeast()->once();
});

it('matches a session in one of the project\'s registered locations, named by its folder', function () {
    ProjectLocation::create(['project_id' => $this->coins->id, 'kind' => ProjectLocation::KIND_ALIAS, 'path' => '/code/coins-2']);
    $this->sessions->session('/code/coins-2/app', 'main', 'aaaaaaaa-0000-0000-0000-000000000008');

    $this->get('/')->assertOk()
        ->assertSeeHtml('data-live-project="coins"')
        ->assertSee('coins-2');
});

it('uses the last valid line when the last line of a session file is truncated', function () {
    $id = 'aaaaaaaa-0000-0000-0000-000000000009';
    $this->sessions->file(SessionFixture::folderFor($this->set42), $id, [
        SessionFixture::line($this->repo->project, 'main', $id, 5),
        SessionFixture::line($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', $id, 2),
        substr(SessionFixture::line($this->repo->project, 'feat/OTHER-1-x', $id, 1), 0, 60),
    ], 2, raw: true);

    $this->get('/')->assertOk()
        ->assertSee('worktree set42')
        ->assertSee('feat/SET-42-lock-a-coin-in-its-slot')
        ->assertDontSee('feat/OTHER-1-x');
});

it('skips a file with no parseable line, logs board.session_unreadable once with the file name, and still shows the other sessions', function () {
    Log::spy();
    $bad = $this->sessions->file('-code-coins', 'bbbbbbbb-0000-0000-0000-000000000001', ['{"cwd": "/code/coi', 'not json at all'], 2);
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000010');

    $this->get('/')->assertOk()->assertSee('worktree set42')->assertDontSee('Sessions unavailable');
    // A second scan (the cache expired) must not log the same unchanged file again.
    $this->travel(21)->seconds();
    $this->get('/')->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.session_unreadable'
        && $c['file'] === basename($bad) && $c['reason'] === 'no_valid_line' && ! str_contains(json_encode($c), '/code/coi'))->once();
});

it('shows "Sessions unavailable" when every live file is unreadable, and the page still returns 200', function () {
    $this->sessions->file('-code-coins', 'bbbbbbbb-0000-0000-0000-000000000002', ['garbage'], 1);
    $this->sessions->file('-code-coins', 'bbbbbbbb-0000-0000-0000-000000000003', [''], 3);

    $this->get('/')->assertOk()
        ->assertSee('Sessions unavailable')
        ->assertSee('What needs me')
        ->assertDontSee('No live sessions');
});

it('shows "No Claude Code sessions folder found" and logs board.sessions_root_missing when the root does not exist', function () {
    Log::spy();
    $missing = $this->sessions->root.'/nope';
    config(['board.sessions_path' => $missing]);

    $this->get('/')->assertOk()
        ->assertSee('No Claude Code sessions folder found')
        ->assertSee('What needs me');

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_root_missing' && $c['path'] === $missing)->once();
});

it('renders nothing from a session file but cwd, gitBranch and the times', function () {
    $id = 'SESSION-ID-SENTINEL';
    $this->sessions->file(SessionFixture::folderFor($this->set42), $id, [
        SessionFixture::line($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', $id, 2, [
            'message' => ['role' => 'user', 'content' => 'MESSAGE-BODY-SENTINEL'],
            'version' => 'VERSION-SENTINEL',
            'requestId' => 'REQUEST-SENTINEL',
            'slug' => 'SLUG-SENTINEL',
        ]),
    ], 2);

    $html = $this->get('/')->assertOk()->assertSee('worktree set42')->getContent();

    expect($html)->not->toContain('SENTINEL');
});

it('reads the files once when the panel polls twice within 20 seconds', function () {
    Log::spy();
    $file = $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000011');

    $panel = Livewire::test(LiveSessions::class)->assertSee('worktree set42');
    // Gone from disk, yet the next poll inside 20 s still shows it: it came from the cache.
    File::delete($file);
    $this->travel(15)->seconds();
    $panel->call('$refresh')->assertSee('worktree set42');

    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_read')->once();

    // Past 20 s the next poll scans again.
    $this->travel(6)->seconds();
    $panel->call('$refresh')->assertDontSee('worktree set42')->assertSee('No live sessions');
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_read')->twice();
});

it('logs board.sessions_read with the file count, the live count and the time taken', function () {
    Log::spy();
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000012', 2);
    $this->sessions->session($this->repo->project, 'main', 'aaaaaaaa-0000-0000-0000-000000000013', 30);

    $this->get('/')->assertOk();

    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_read' && $c['files'] === 2 && $c['live'] === 1 && is_int($c['ms']))->once();
});

it('polls on the panel only, every 30 seconds', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'wire:poll'))->toBe(1)
        ->and($html)->toContain('wire:poll.30s');
});

it('skips a session file that resolves outside the root, and logs it', function () {
    Log::spy();
    $outside = sys_get_temp_dir().'/story-board-sessions/outside-'.bin2hex(random_bytes(4)).'.jsonl';
    File::put($outside, SessionFixture::line($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'x')."\n");
    File::ensureDirectoryExists($this->sessions->root.'/-code-coins');
    symlink($outside, $this->sessions->root.'/-code-coins/cccccccc-0000-0000-0000-000000000001.jsonl');

    try {
        $this->get('/')->assertOk()->assertDontSee('worktree set42');
        Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.session_unreadable' && $c['reason'] === 'outside_root')->once();
    } finally {
        File::delete($outside);
    }
});

it('links every upper-case ID in the branch name that exists in the project, and skips the rest', function () {
    $this->sessions->session($this->repo->project, 'integrate/SET-42-NOPE-9-MOB-46', 'aaaaaaaa-0000-0000-0000-000000000014');

    $this->get('/')->assertOk()
        ->assertSeeHtml('data-live-story="coins/SET-42"')
        ->assertSeeHtml('data-live-story="coins/MOB-46"')
        ->assertDontSeeHtml('data-live-story="coins/NOPE-9"');
});

it('shows only this project\'s sessions on /p/{project}, and "No live sessions" on a project with none', function () {
    Project::factory()->create(['name' => 'asset-track', 'path' => '/code/asset-track', 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000015');

    $this->get('/p/coins')->assertOk()->assertSee('Live now')->assertSee('worktree set42');
    $this->get('/p/asset-track')->assertOk()->assertSee('Live now')->assertSee('No live sessions')->assertDontSeeHtml('data-live-session');
});

it('shows a live badge with the count next to each project with a live session in the sidebar', function () {
    Project::factory()->create(['name' => 'asset-track', 'path' => '/code/asset-track', 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000016');
    $this->sessions->session($this->repo->project, 'main', 'aaaaaaaa-0000-0000-0000-000000000017');

    $html = $this->get('/projects')->assertOk()
        ->assertSeeHtml('data-live-count="2"')
        ->assertSeeHtml('title="2 live sessions"')
        ->getContent();

    expect(substr_count($html, 'data-live-count='))->toBe(1);
});

it('opens the SB-8 modal when a linked story is clicked', function () {
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000018');

    $this->get('/')->assertOk()
        ->assertSeeHtml('data-live-story="coins/SET-42"')
        ->assertSeeHtml('aria-haspopup="dialog"')
        ->assertSeeHtml("\$dispatch('board-story'");
});
