<?php

use App\Actions\Board\ListWhatNeedsMe;
use App\Livewire\Board\Home;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-5's visible half: the "Not on main" section on the home page, the banner on
 * the story page, and branch-only mockups served from the branch's commit.
 */
beforeEach(function () {
    $this->fixture = new GitFixture;
    $this->fixture->story('FX-56', 'approved', 'mobile', "## Design mockup gate\n- Chosen option: _pending_\n")
        ->write('docs/mockups/FX-56/option-a.html', '<p>main A</p>')
        ->write('docs/mockups/FX-56/option-d.html', '<p>main D</p>')
        ->story('FX-2', 'draft')
        ->commitAndPush();
    // The pick exists only on an unmerged branch.
    $this->fixture->story('FX-56', 'approved', 'mobile', "## Design mockup gate\n- Chosen option: d\n")
        ->pushBranch('docs/FX-56-pick');
    // A story written only on a branch, with a mockup only there.
    $this->fixture->story('FX-9', 'draft')->write('docs/mockups/FX-9/option-a.html', '<p>branch-only A</p>')
        ->pushBranch('docs/FX-9-story');
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
});

afterEach(function () {
    $this->fixture->destroy();
});

it('shows the pick made on an unmerged branch as a banner on the story page', function () {
    $this->get('/p/fx/s/FX-56')
        ->assertOk()
        ->assertSeeHtml('data-offmain-banner')
        ->assertSee('Picked D on branch origin/docs/FX-56-pick — not on main');
});

it('lists a branch-only story under Not on main with its branch, and not in the groups', function () {
    Livewire::test(Home::class)
        ->assertSeeHtml('data-section="offmain"')
        ->call('toggleSection', 'offmain')
        ->assertSeeHtml('data-offmain-row="FX-9"')
        ->assertSee('branch origin/docs/FX-9-story')
        ->assertDontSeeHtml('data-group="approval" data-row="FX-9"');

    expect(app(ListWhatNeedsMe::class)->handle()['approval']->pluck('story_id')->all())->toBe(['FX-2']);
});

it('lists an untracked mockup directory with where it lives', function () {
    $this->fixture->syncProject()->untracked($this->fixture->project, 'docs/mockups/XY-1/option-a.html', 'x');
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();

    Livewire::test(Home::class)->call('toggleSection', 'offmain')
        ->assertSeeHtml('data-offmain-row="XY-1"')
        ->assertSee('untracked in '.realpath($this->fixture->project));
});

it('opens a branch-only story page from its branch, with its mockups served from that commit', function () {
    $row = Story::offMain()->where('story_id', 'FX-9')->sole();

    $this->get("/p/fx/s/FX-9?v={$row->id}")
        ->assertOk()
        ->assertSee('On branch origin/docs/FX-9-story')
        ->assertSeeHtml('src="'.route('mockups.file', ['project' => 'fx', 'storyId' => 'FX-9', 'file' => 'option-a.html', 'v' => $row->id]).'"');

    $this->get(route('mockups.file', ['project' => 'fx', 'storyId' => 'FX-9', 'file' => 'option-a.html', 'v' => $row->id]))
        ->assertOk()->assertSee('branch-only A', false);
});

it('does not serve untracked mockups or another story\'s version through ?v=', function () {
    $this->fixture->syncProject()->untracked($this->fixture->project, 'docs/mockups/XY-1/option-a.html', 'x');
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $untracked = Story::offMain()->where('story_id', 'XY-1')->sole();
    $fx9 = Story::offMain()->where('story_id', 'FX-9')->sole();

    $this->get(route('mockups.file', ['project' => 'fx', 'storyId' => 'XY-1', 'file' => 'option-a.html', 'v' => $untracked->id]))->assertNotFound();
    $this->get(route('mockups.file', ['project' => 'fx', 'storyId' => 'FX-56', 'file' => 'option-a.html', 'v' => $fx9->id]))->assertNotFound();
});

it('opens a story that exists only off main from its first off-main version', function () {
    $this->get('/p/fx/s/FX-9')->assertOk()->assertSee('On branch origin/docs/FX-9-story');
});

it('labels a branch version with its branch and commit, not the project ref', function () {
    $row = Story::offMain()->where('story_id', 'FX-9')->sole();

    $this->get("/p/fx/s/FX-9?v={$row->id}")
        ->assertSee('@ origin/docs/FX-9-story '.substr($row->sha, 0, 8))
        ->assertDontSee('@ origin/main');
});

it('labels a mockup-only row as mockups only, not as a missing status', function () {
    $this->fixture->syncProject()->untracked($this->fixture->project, 'docs/mockups/XY-1/option-a.html', 'x');
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();

    Livewire::test(Home::class)->call('toggleSection', 'offmain')
        ->assertSee('mockups only')
        ->assertDontSeeHtml('data-status-chip=""');
});

it('refuses and logs a v that is not a row id, on the page and the mockup route', function (string $url) {
    Log::spy();

    $this->get($url)->assertNotFound();

    Log::shouldHaveReceived('warning')->withArgs(fn ($event) => $event === 'board.version_rejected')->once();
})->with(['/p/fx/s/FX-9?v=1x', '/p/fx/m/FX-9/option-a.html?v=../1', '/p/fx/s/FX-9?v=-1']);

it('refuses a version that belongs to another project', function () {
    $other = new GitFixture;
    $other->story('FX-9', 'draft')->pushBranch('docs/other');
    $otherProject = Project::factory()->create(['name' => 'other', 'path' => $other->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'other'])->assertSuccessful();
    $foreign = Story::offMain()->where('project_id', $otherProject->id)->where('story_id', 'FX-9')->sole();

    $this->get("/p/fx/s/FX-9?v={$foreign->id}")->assertNotFound();
    $this->get(route('mockups.file', ['project' => 'fx', 'storyId' => 'FX-9', 'file' => 'option-a.html', 'v' => $foreign->id]))->assertNotFound();

    $other->destroy();
});

it('shows an untracked version without reading its text', function () {
    $this->fixture->syncProject()->untracked($this->fixture->project, 'stories/demo/FX-40-new.md', "# FX-40 — Untracked story\nStatus: draft\n\nSECRET-BODY\n");
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $row = Story::offMain()->where('story_id', 'FX-40')->sole();

    $this->get("/p/fx/s/FX-40?v={$row->id}")
        ->assertOk()
        ->assertSee('Untracked in')
        ->assertSee('not in git')
        ->assertDontSee('SECRET-BODY');
});

it('labels a version on a branch checked out in a worktree by that worktree', function () {
    $worktree = $this->fixture->addWorktree('fx-wt', 'docs/FX-2-local');
    file_put_contents($worktree.'/stories/demo/FX-2-story.md', str_replace('Status: draft', 'Status: approved', file_get_contents($worktree.'/stories/demo/FX-2-story.md')));
    $this->fixture->git($worktree, 'commit', '--quiet', '-am', 'docs(FX-2): approve');
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();

    $this->get('/p/fx/s/FX-2')->assertSee('Approved in worktree '.realpath($worktree).' — not on main');
});
