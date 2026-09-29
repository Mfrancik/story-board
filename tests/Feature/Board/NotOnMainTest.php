<?php

use App\Actions\Board\IndexOffMain;
use App\Actions\Board\ListWhatNeedsMe;
use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

beforeEach(function () {
    $this->fixture = new GitFixture;
    $this->fixture->story('FX-1', 'approved', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n")
        ->write('docs/mockups/FX-1/option-a.html', 'a')
        ->write('docs/mockups/FX-1/option-d.html', 'd')
        ->story('FX-2', 'draft')
        ->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project]);
});

afterEach(function () {
    $this->fixture->destroy();
});

function offMain(Project $project)
{
    return Story::where('project_id', $project->id)->whereNotNull('location')->orderBy('path')->get();
}

it('shows a pick made only on an unmerged branch', function () {
    $this->fixture->story('FX-1', 'approved', 'demo', "## Design mockup gate\n- Chosen option: d\n")
        ->pushBranch('docs/FX-1-pick');

    $this->artisan('board:refresh')->assertSuccessful();

    $row = offMain($this->project)->sole();
    expect($row->story_id)->toBe('FX-1')
        ->and($row->location_kind)->toBe('branch')
        ->and($row->branch)->toBe('origin/docs/FX-1-pick')
        ->and($row->location)->toBe('branch origin/docs/FX-1-pick')
        ->and($row->mockups['chosen'])->toBe('d')
        // The ref's own row still says pending, so the board can show both versions.
        ->and($this->project->stories()->onRef()->where('story_id', 'FX-1')->sole()->mockups['chosen'])->toBeNull();
});

it('lists a story that exists only on an unmerged branch, and keeps it out of the home groups', function () {
    $this->fixture->story('FX-9', 'draft')->pushBranch('docs/FX-9-story');

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project)->pluck('story_id')->all())->toBe(['FX-9'])
        ->and(collect(app(ListWhatNeedsMe::class)->handle()['approval'])->pluck('story_id')->all())->toBe(['FX-2']);
});

it('produces no rows for a branch whose stories match the ref', function () {
    // A code-only branch: it touches no story or mockup.
    $this->fixture->write('app/Thing.php', '<?php')->pushBranch('feat/FX-2-code');

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project))->toBeEmpty();
});

it('ignores a merged branch', function () {
    $this->fixture->story('FX-1', 'built')->pushBranch('feat/FX-1')->mergeBranch('feat/FX-1');

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project))->toBeEmpty();
});

it('lists an untracked mockup directory in a checkout with its location', function () {
    $this->fixture->syncProject();
    $this->fixture->untracked($this->fixture->project, 'docs/mockups/X-1/option-a.html', '<p>x</p>');

    $this->artisan('board:refresh')->assertSuccessful();

    $row = offMain($this->project)->sole();
    expect($row->story_id)->toBe('X-1')
        ->and($row->location_kind)->toBe('untracked')
        ->and($row->location)->toBe('untracked in '.realpath($this->fixture->project))
        ->and($row->mockups['options'])->toBe(['a']);
});

it('lists an untracked story in a worktree, parsed by the kit parser', function () {
    $worktree = $this->fixture->addWorktree('project-wt', 'docs/FX-7');
    $this->fixture->untracked($worktree, 'stories/demo/FX-7-new.md', "# FX-7 — Written in a worktree\nStatus: draft\n");

    $this->artisan('board:refresh')->assertSuccessful();

    $row = offMain($this->project)->sole();
    expect($row->story_id)->toBe('FX-7')
        ->and($row->title)->toBe('Written in a worktree')
        ->and($row->status)->toBe('draft')
        ->and($row->location)->toBe('untracked in '.realpath($worktree));
});

it('labels an unmerged branch checked out in a worktree by the worktree', function () {
    $worktree = $this->fixture->addWorktree('project-pick', 'docs/FX-1-local');
    // Commit on the worktree's branch without pushing: the pick exists only here.
    file_put_contents($worktree.'/stories/demo/FX-1-story.md', str_replace('_pending_', 'a', file_get_contents($worktree.'/stories/demo/FX-1-story.md')));
    $this->fixture->git($worktree, 'commit', '--quiet', '-am', 'docs(FX-1): pick a');

    $this->artisan('board:refresh')->assertSuccessful();

    $row = offMain($this->project)->sole();
    expect($row->location_kind)->toBe('worktree')
        ->and($row->branch)->toBe('docs/FX-1-local')
        ->and($row->location)->toBe('worktree '.realpath($worktree))
        ->and($row->mockups['chosen'])->toBe('a');
});

it('logs how many items each kind of location produced', function () {
    Log::spy();
    $this->fixture->story('FX-9', 'draft')->pushBranch('docs/FX-9');
    $this->fixture->syncProject()->untracked($this->fixture->project, 'docs/mockups/X-1/option-a.html', 'x');

    $this->artisan('board:refresh')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.offmain_indexed'
        && $ctx['branch'] === 1 && $ctx['untracked'] === 1 && $ctx['worktree'] === 0)->once();
});

it('leaves every checkout\'s git status unchanged', function () {
    $worktree = $this->fixture->addWorktree('project-wt', 'docs/FX-7');
    $this->fixture->untracked($worktree, 'stories/demo/FX-7-new.md', "# FX-7 — New\nStatus: draft\n");
    $this->fixture->syncProject()->untracked($this->fixture->project, 'docs/mockups/X-1/option-a.html', 'x');
    $this->fixture->story('FX-9', 'draft')->pushBranch('docs/FX-9');
    $status = fn ($dir) => $this->fixture->git($dir, 'status', '--porcelain', '--untracked-files=all')
        .$this->fixture->git($dir, 'rev-parse', 'HEAD');
    [$main, $wt] = [$status($this->fixture->project), $status($worktree)];
    $localBranches = fn () => $this->fixture->git($this->fixture->project, 'for-each-ref', 'refs/heads');
    $branches = $localBranches();

    $this->artisan('board:refresh')->assertSuccessful();

    // fetch may add remote-tracking refs; local branches, HEADs and working trees must not change.
    expect($status($this->fixture->project))->toBe($main)
        ->and($status($worktree))->toBe($wt)
        ->and($localBranches())->toBe($branches);
});

it('keeps the ref snapshot when the off-main scan fails', function () {
    Log::spy();
    $this->mock(IndexOffMain::class)->shouldReceive('handle')->andThrow(new GitReaderException('boom'));

    $this->artisan('board:refresh')->assertSuccessful();

    expect($this->project->refresh()->state)->toBe(Project::STATE_OK)
        ->and($this->project->stories()->onRef()->count())->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event) => $event === 'board.offmain_failed')->once();
});

it('registers a sibling checkout as an alias and scans it too', function () {
    $alias = $this->fixture->root.'/sibling';
    $this->fixture->git($this->fixture->root, 'clone', '--quiet', $this->fixture->origin, $alias);
    $this->fixture->untracked($alias, 'docs/mockups/X-2/option-b.html', 'b');

    $this->artisan('board:project', ['action' => 'alias', 'path' => $alias, '--name' => 'fx'])->assertSuccessful();
    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project)->pluck('location', 'story_id')->all())->toBe(['X-2' => 'untracked in '.realpath($alias)]);
});

it('does not report what main changed after an old branch forked', function () {
    // The branch forks, touches only code; then main builds FX-2. The branch's older
    // copy of FX-2 (draft) differs from main but is not work on the branch.
    $this->fixture->write('app/Thing.php', '<?php')->pushBranch('feat/old-branch');
    $this->fixture->story('FX-2', 'built')->commitAndPush('feat(FX-2): build');

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project))->toBeEmpty();
});

it('skips a branch with no shared history and still indexes the others', function () {
    Log::spy();
    $this->fixture->story('FX-9', 'draft')->pushBranch('docs/FX-9');
    $this->fixture->git($this->fixture->author, 'checkout', '--quiet', '--orphan', 'gh-pages');
    $this->fixture->git($this->fixture->author, 'rm', '-rf', '--quiet', '.');
    $this->fixture->write('index.html', 'site')->write('stories/x/ZZ-1-orphan.md', "# ZZ-1 — Orphan\nStatus: draft\n");
    $this->fixture->git($this->fixture->author, 'add', '-A');
    $this->fixture->git($this->fixture->author, 'commit', '--quiet', '-m', 'site');
    $this->fixture->git($this->fixture->author, 'push', '--quiet', 'origin', 'gh-pages');

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project)->pluck('story_id')->all())->toBe(['FX-9']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $ctx = []) => $event === 'board.offmain_branch_skipped' && $ctx['branch'] === 'origin/gh-pages')->once();
});

it('does not list a branch story that reached the ref under another path', function () {
    // The branch adds FX-5 flat; main gets the same story in its initiative folder.
    $this->fixture->write('stories/FX-5-flat.md', "# FX-5 — Moved\nStatus: built\n")->pushBranch('feat/FX-5');
    $this->fixture->write('stories/demo/FX-5-moved.md', "# FX-5 — Moved\nStatus: built\n")->commitAndPush();

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project))->toBeEmpty();
});

it('lists a story once when stacked branches carry the same version of it', function () {
    $this->fixture->story('FX-9', 'draft')->pushBranch('feat/FX-9-base');
    $this->fixture->git($this->fixture->author, 'checkout', '--quiet', 'feat/FX-9-base');
    $this->fixture->write('app/More.php', '<?php')->pushBranch('feat/FX-9-more');

    $this->artisan('board:refresh')->assertSuccessful();

    expect(offMain($this->project)->pluck('branch', 'story_id')->all())->toBe(['FX-9' => 'origin/feat/FX-9-base']);
});
