<?php

use App\Actions\Board\ListLiveSessions;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Story;
use App\Services\SessionReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;
use Tests\Support\SessionFixture;

/**
 * SB-11 and SB-25's session rules, at the action level: which transcript files are live, which project a
 * session belongs to, and which stories its branch builds. These assertions lived in LiveSessionsTest against
 * the old Live now panel until RD-9 deleted it; the overview's Live now (RD-5) runs the same reader and the same
 * linking rules through ListLiveSessions::overview(). Fixtures are hand-written JSONL files in a temp root shaped
 * like the real ones checked on 2026-09-29.
 */
beforeEach(function () {
    $this->repo = new GitFixture;
    $this->repo->story('SET-42', 'approved', 'sets')->story('MOB-46', 'approved', 'mobile')->commitAndPush();
    $this->coins = Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();

    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);
    $this->set42 = $this->repo->project.'/.claude/worktrees/set42';
});

afterEach(function () {
    $this->repo->destroy();
    $this->sessions->destroy();
});

/**
 * The overview's Live now cards, as ListLiveSessions hands them to the page.
 *
 * @return list<array<string, mixed>>
 */
function liveCards(): array
{
    return app(ListLiveSessions::class)->overview()['sessions'];
}

/**
 * The story IDs linked to the only live card.
 *
 * @return list<string>
 */
function linkedIds(): array
{
    $cards = liveCards();
    expect($cards)->toHaveCount(1);

    return $cards[0]['stories'];
}

/** An untracked story row as IndexOffMain writes it: tagged with its checkout's branch and root. */
function untrackedRow(Project $project, string $id, string $root, string $branch): Story
{
    return Story::factory()->for($project)->create([
        'story_id' => $id, 'title' => "Untracked {$id}", 'status' => 'draft',
        'location_kind' => Story::KIND_UNTRACKED, 'location' => "untracked in {$root}", 'branch' => $branch,
    ]);
}

it('links the story named in the session\'s branch, and does not count a file last modified 11 minutes ago as live', function () {
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000001', 2);
    $this->sessions->session($this->set42, 'feat/MOB-46-old', 'aaaaaaaa-0000-0000-0000-000000000002', 11);

    $cards = liveCards();

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['project'])->toBe('coins')
        ->and($cards[0]['branch'])->toBe('feat/SET-42-lock-a-coin-in-its-slot')
        ->and($cards[0]['stories'])->toBe(['SET-42']);
});

it('renders nothing on the overview from a session file\'s message, version, request id or slug', function () {
    // Ported from the deleted LiveSessionsTest privacy check: the overview card is the only page that renders a
    // session now, so the sentinels must stay off `/` too.
    $id = 'aaaaaaaa-0000-0000-0000-000000000099';
    $this->sessions->file(SessionFixture::folderFor($this->set42), $id, [
        SessionFixture::line($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', $id, 2, [
            'message' => ['role' => 'user', 'content' => 'MESSAGE-BODY-SENTINEL'],
            'version' => 'VERSION-SENTINEL',
            'requestId' => 'REQUEST-SENTINEL',
            'slug' => 'SLUG-SENTINEL',
        ]),
    ], 2);

    $html = $this->get('/')->assertOk()->assertSee('data-live-story="coins/SET-42"', false)->getContent();

    expect($html)->not->toContain('SENTINEL');
});

it('links the off-main row on the session\'s branch when the branch name carries no ID', function () {
    Story::factory()->for($this->coins)->create([
        'story_id' => 'BUL-70', 'title' => 'Bullion on the phone', 'status' => 'draft',
        'location_kind' => Story::KIND_BRANCH, 'location' => 'branch integrate/bullion-phone-1', 'branch' => 'integrate/bullion-phone-1',
    ]);
    $this->sessions->session($this->repo->project, 'integrate/bullion-phone-1', 'aaaaaaaa-0000-0000-0000-000000000003');

    expect(linkedIds())->toBe(['BUL-70']);
});

it('links no story for docs/PRF-browser-flake-stories (no number) or a detached HEAD', function (string $branch) {
    // An off-main row on a branch literally named HEAD must still not link a detached session.
    Story::factory()->for($this->coins)->create(['story_id' => 'PRF-9', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch HEAD', 'branch' => 'HEAD']);
    $this->sessions->session($this->repo->project.'/.claude/worktrees/prf7ef', $branch, 'aaaaaaaa-0000-0000-0000-000000000004');

    expect(linkedIds())->toBe([]);
})->with(['docs/PRF-browser-flake-stories', 'HEAD']);

it('links no story from a lower-case branch like integrate/bul-65-66-mob-46 (IDs match upper case only)', function () {
    $this->sessions->session($this->repo->project, 'integrate/bul-65-66-mob-46', 'aaaaaaaa-0000-0000-0000-000000000005');

    expect(linkedIds())->toBe([]);
});

it('links every upper-case ID in the branch name that exists in the project, and skips the rest', function () {
    $this->sessions->session($this->repo->project, 'integrate/SET-42-NOPE-9-MOB-46', 'aaaaaaaa-0000-0000-0000-000000000014');

    expect(linkedIds())->toBe(['SET-42', 'MOB-46']);
});

it('ignores a session whose cwd is in no registered project, or in a disabled one, and logs why', function () {
    Log::spy();
    $off = Project::factory()->create(['name' => 'rent-track', 'path' => '/code/rent-track', 'is_enabled' => false]);
    $this->sessions->session('/code/story-board', 'feat/SB-11-live-sessions', 'aaaaaaaa-0000-0000-0000-000000000006');
    $this->sessions->session($off->path.'/.claude/worktrees/x', 'feat/RT-1-x', 'aaaaaaaa-0000-0000-0000-000000000007');

    expect(liveCards())->toBe([]);

    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.session_ignored' && $c['reason'] === 'unregistered' && $c['cwd'] === '/code/story-board')->atLeast()->once();
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.session_ignored' && $c['reason'] === 'disabled' && $c['project'] === 'rent-track')->atLeast()->once();
});

it('matches a session in one of the project\'s registered locations to that project', function () {
    ProjectLocation::create(['project_id' => $this->coins->id, 'kind' => ProjectLocation::KIND_ALIAS, 'path' => '/code/coins-2']);
    $this->sessions->session('/code/coins-2/app', 'main', 'aaaaaaaa-0000-0000-0000-000000000008');

    expect(collect(liveCards())->pluck('project')->all())->toBe(['coins']);
});

it('uses the last valid line when the last line of a session file is truncated', function () {
    $id = 'aaaaaaaa-0000-0000-0000-000000000009';
    $this->sessions->file(SessionFixture::folderFor($this->set42), $id, [
        SessionFixture::line($this->repo->project, 'main', $id, 5),
        SessionFixture::line($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', $id, 2),
        substr(SessionFixture::line($this->repo->project, 'feat/OTHER-1-x', $id, 1), 0, 60),
    ], 2, raw: true);

    $cards = liveCards();

    expect($cards)->toHaveCount(1)->and($cards[0]['branch'])->toBe('feat/SET-42-lock-a-coin-in-its-slot');
});

it('skips a file with no parseable line, logs board.session_unreadable once with the file name, and still reads the other sessions', function () {
    Log::spy();
    $bad = $this->sessions->file('-code-coins', 'bbbbbbbb-0000-0000-0000-000000000001', ['{"cwd": "/code/coi', 'not json at all'], 2);
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000010');

    $live = app(SessionReader::class)->live();
    expect($live['status'])->toBe(SessionReader::OK)->and($live['sessions'])->toHaveCount(1);
    // A second scan (the cache expired) must not log the same unchanged file again.
    $this->travel(21)->seconds();
    app(SessionReader::class)->live();

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.session_unreadable'
        && $c['file'] === basename($bad) && $c['reason'] === 'no_valid_line' && ! str_contains(json_encode($c), '/code/coi'))->once();
});

it('reads as unavailable when every live file is unreadable', function () {
    $this->sessions->file('-code-coins', 'bbbbbbbb-0000-0000-0000-000000000002', ['garbage'], 1);
    $this->sessions->file('-code-coins', 'bbbbbbbb-0000-0000-0000-000000000003', [''], 3);

    expect(app(SessionReader::class)->live())->toBe(['status' => SessionReader::UNAVAILABLE, 'sessions' => []]);
});

it('reads as missing and logs board.sessions_root_missing when the root does not exist', function () {
    Log::spy();
    $missing = $this->sessions->root.'/nope';
    config(['board.sessions_path' => $missing]);

    expect(app(SessionReader::class)->live())->toBe(['status' => SessionReader::MISSING, 'sessions' => []]);

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_root_missing' && $c['path'] === $missing)->once();
});

it('reads the files once when asked twice within 20 seconds, and again after', function () {
    Log::spy();
    $file = $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000011');

    expect(liveCards())->toHaveCount(1);
    // Gone from disk, yet the next read inside 20 s still has it: it came from the cache.
    File::delete($file);
    $this->travel(15)->seconds();
    expect(liveCards())->toHaveCount(1);
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_read')->once();

    // Past 20 s the next read scans again.
    $this->travel(6)->seconds();
    expect(liveCards())->toBe([]);
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_read')->twice();
});

it('logs board.sessions_read with the file count, the live count and the time taken', function () {
    Log::spy();
    $this->sessions->session($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'aaaaaaaa-0000-0000-0000-000000000012', 2);
    $this->sessions->session($this->repo->project, 'main', 'aaaaaaaa-0000-0000-0000-000000000013', 30);

    app(SessionReader::class)->live();

    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.sessions_read' && $c['files'] === 2 && $c['live'] === 1 && is_int($c['ms']))->once();
});

it('skips a session file that resolves outside the root, and logs it', function () {
    Log::spy();
    $outside = sys_get_temp_dir().'/story-board-sessions/outside-'.bin2hex(random_bytes(4)).'.jsonl';
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, SessionFixture::line($this->set42, 'feat/SET-42-lock-a-coin-in-its-slot', 'x')."\n");
    File::ensureDirectoryExists($this->sessions->root.'/-code-coins');
    symlink($outside, $this->sessions->root.'/-code-coins/cccccccc-0000-0000-0000-000000000001.jsonl');

    try {
        expect(liveCards())->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.session_unreadable' && $c['reason'] === 'outside_root')->once();
    } finally {
        File::delete($outside);
    }
});

/*
 * SB-25: a live card links only the stories its session is working on. Untracked rows carry the branch of the
 * checkout they sit in, so the SB-11 branch fallback used to hand every untracked story in the main checkout to
 * every session on `main`.
 */

it('links no story for a session on main in the main checkout with untracked stories in that checkout', function () {
    untrackedRow($this->coins, 'ADMIN-16', $this->repo->project, 'main');
    untrackedRow($this->coins, 'BRAND-10', $this->repo->project, 'main');
    $this->sessions->session($this->repo->project, 'main', 'dddddddd-0000-0000-0000-000000000001');

    expect(linkedIds())->toBe([]);
});

it('links no story for a session on main in a worktree with untracked stories in the main checkout', function () {
    untrackedRow($this->coins, 'ADMIN-16', $this->repo->project, 'main');
    $this->sessions->session($this->repo->project.'/.claude/worktrees/prf7ch', 'main', 'dddddddd-0000-0000-0000-000000000002');

    expect(linkedIds())->toBe([]);
});

it('links an untracked story in worktree W to a session on feat/x (no ID) in W', function () {
    $w = $this->repo->project.'/.claude/worktrees/w';
    untrackedRow($this->coins, 'ADMIN-23', $w, 'feat/x');
    $this->sessions->session($w.'/app', 'feat/x', 'dddddddd-0000-0000-0000-000000000003');

    expect(linkedIds())->toBe(['ADMIN-23']);
});

it('does not link an untracked story in a different checkout also tagged feat/x to a session on feat/x (no ID) in worktree W', function () {
    $w = $this->repo->project.'/.claude/worktrees/w';
    untrackedRow($this->coins, 'ADMIN-23', $w, 'feat/x');
    // A sibling whose path shares W's prefix: `/…/w` must not match `/…/wx`.
    untrackedRow($this->coins, 'ADMIN-24', $w.'x', 'feat/x');
    untrackedRow($this->coins, 'BRAND-9', $this->repo->project, 'feat/x');
    $this->sessions->session($w, 'feat/x', 'dddddddd-0000-0000-0000-000000000004');

    expect(linkedIds())->toBe(['ADMIN-23']);
});

it('links a branch row (not untracked) on feat/x to a session on feat/x as before', function () {
    Story::factory()->for($this->coins)->create([
        'story_id' => 'BUL-71', 'title' => 'Bullion branch row', 'status' => 'draft',
        'location_kind' => Story::KIND_BRANCH, 'location' => 'branch feat/x', 'branch' => 'feat/x',
    ]);
    // Anywhere in the project: branch and worktree rows are not tied to the session's folder.
    $this->sessions->session($this->repo->project.'/.claude/worktrees/elsewhere', 'feat/x', 'dddddddd-0000-0000-0000-000000000005');

    expect(linkedIds())->toBe(['BUL-71']);
});

it('still applies rule 1 to a session on main whose branch name contains no ID when the project\'s ref is origin/main', function (string $ref) {
    $this->coins->update(['ref' => $ref]);
    Story::factory()->for($this->coins)->create([
        'story_id' => 'BUL-72', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch main', 'branch' => 'main',
    ]);
    untrackedRow($this->coins, 'ADMIN-16', $this->repo->project, 'main');
    $this->sessions->session($this->repo->project, 'main', 'dddddddd-0000-0000-0000-000000000006');

    expect(linkedIds())->toBe([]);
})->with(['origin/main', 'main']);

it('links SB-9 by step 1, unchanged, for a session on feat/SB-9-thing', function () {
    $this->repo->story('SB-9', 'approved', 'board')->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    // An untracked row elsewhere on the same branch must not replace or join the step-1 link.
    untrackedRow($this->coins, 'ADMIN-16', $this->repo->project, 'feat/SB-9-thing');
    $this->sessions->session($this->repo->project.'/.claude/worktrees/sb9', 'feat/SB-9-thing', 'dddddddd-0000-0000-0000-000000000007');

    expect(linkedIds())->toBe(['SB-9']);
});

it('links SB-9 by step 1 on the default branch too', function () {
    $this->repo->story('SB-9', 'approved', 'board')->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
    // A branch literally named after the ref's branch plus an ID still names its work.
    $this->coins->update(['ref' => 'origin/SB-9']);
    $this->sessions->session($this->repo->project, 'SB-9', 'dddddddd-0000-0000-0000-000000000008');

    expect(linkedIds())->toBe(['SB-9']);
});

it('logs board.session_links_filtered at debug with project, branch and the count dropped, and no story content, when rule 1 or rule 2 drops rows', function () {
    Log::spy();
    untrackedRow($this->coins, 'ADMIN-16', $this->repo->project, 'main');
    untrackedRow($this->coins, 'BRAND-10', $this->repo->project, 'main');
    $w = $this->repo->project.'/.claude/worktrees/w';
    untrackedRow($this->coins, 'ADMIN-23', $w, 'feat/x');
    untrackedRow($this->coins, 'ADMIN-24', $this->repo->project, 'feat/x');
    $this->sessions->session($this->repo->project, 'main', 'dddddddd-0000-0000-0000-000000000009');
    $this->sessions->session($w, 'feat/x', 'dddddddd-0000-0000-0000-000000000010');

    liveCards();

    $metadataOnly = fn (array $c) => array_keys($c) === ['project', 'branch', 'rule', 'dropped']
        && ! str_contains((string) json_encode($c), 'ADMIN') && ! str_contains((string) json_encode($c), 'Untracked');
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.session_links_filtered' && $metadataOnly($c)
        && $c['project'] === 'coins' && $c['branch'] === 'main' && $c['rule'] === 'default_branch' && $c['dropped'] === 2)->once();
    Log::shouldHaveReceived('debug')->withArgs(fn ($e, $c = []) => $e === 'board.session_links_filtered' && $metadataOnly($c)
        && $c['project'] === 'coins' && $c['branch'] === 'feat/x' && $c['rule'] === 'untracked_checkout' && $c['dropped'] === 1)->once();
});

it('does not log board.session_links_filtered when no row is dropped', function () {
    Log::spy();
    $this->sessions->session($this->repo->project, 'main', 'dddddddd-0000-0000-0000-000000000011');

    expect(linkedIds())->toBe([]);

    Log::shouldNotHaveReceived('debug', fn ($e, $c = []) => $e === 'board.session_links_filtered');
});
