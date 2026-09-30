<?php

use App\Exceptions\StoryPickRefusedException;
use App\Livewire\Board\MockupViewer;
use App\Models\Project;
use App\Models\Story;
use App\Services\StoryPickWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-21 picks. Every write here lands in a throwaway GitFixture clone — never a
 * real project. The registered clone is synced so its working tree holds the
 * story file the pick rewrites.
 */
beforeEach(function () {
    $this->fixture = new GitFixture;
    $this->fixture->story('FX-1', 'draft', 'demo', "## Design mockup gate (visual stories only — else \"n/a — non-visual\")\n"
        ."- Mockups: docs/mockups/FX-1/option-{a,b,c}.html\n"
        ."- Chosen option: _(filled AFTER the owner picks; no build before this)_\n"
        ."- Why I chose it: _pending_\n\n## Do NOT touch\n- nothing\n")
        ->story('FX-2', 'approved', 'demo', "## Design mockup gate\n- Mockups: docs/mockups/FX-2/\n- Chosen option: a\n- Why I chose it: owner pick, calmer\n")
        ->story('FX-3', 'draft', 'demo', "## Why\nNo gate here.\n")
        ->story('FX-4', 'draft', 'demo', "## Design mockup gate\n- Mockups: docs/mockups/FX-4/\n- Why I chose it: _pending_\n");
    foreach (['FX-1' => ['a', 'b', 'c'], 'FX-2' => ['a', 'b'], 'FX-3' => ['a', 'b'], 'FX-4' => ['a', 'b']] as $id => $options) {
        foreach ($options as $o) {
            $this->fixture->write("docs/mockups/{$id}/option-{$o}.html", "<p>{$id} {$o}</p>");
        }
    }
    $this->fixture->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh')->assertSuccessful();
    $this->fixture->syncProject();
    // The pick commits as whoever the checkout is configured as; the fixture clone has no identity of its own.
    $this->fixture->git($this->fixture->project, 'config', 'user.name', 'Fixture Owner');
    $this->fixture->git($this->fixture->project, 'config', 'user.email', 'owner@example.test');
    $this->storyFile = $this->fixture->project.'/stories/demo/FX-1-story.md';
    $this->head = fn () => trim($this->fixture->git($this->fixture->project, 'rev-parse', 'HEAD'));
});

afterEach(function () {
    $this->fixture->destroy();
});

it('records pick b with its reason in one docs commit touching only the story file, and pushes nothing', function () {
    Log::spy();
    $before = ($this->head)();
    $originBefore = $this->fixture->originSha();

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])
        ->call('pick', 'b', 'cleaner table')
        ->assertHasNoErrors()
        ->assertSet('refusal', null);

    $text = File::get($this->storyFile);
    expect($text)->toContain("- Chosen option: b\n")
        ->and($text)->toMatch('/^- Why I chose it: owner pick \d{4}-\d{2}-\d{2} \(board\): cleaner table$/m')
        // Only the two lines changed: the rest of the gate and the next section are as they were.
        ->and($text)->toContain("- Mockups: docs/mockups/FX-1/option-{a,b,c}.html\n")
        ->and($text)->toContain("## Do NOT touch\n- nothing\n");

    $log = trim($this->fixture->git($this->fixture->project, 'log', '--format=%s', "{$before}..HEAD"));
    expect($log)->toBe('docs(FX-1): record mockup pick b');
    $files = trim($this->fixture->git($this->fixture->project, 'show', '--name-only', '--format=', 'HEAD'));
    expect($files)->toBe('stories/demo/FX-1-story.md')
        ->and(trim($this->fixture->git($this->fixture->project, 'rev-parse', '--abbrev-ref', 'HEAD')))->toBe('main')
        ->and($this->fixture->originSha())->toBe($originBefore)
        ->and(trim($this->fixture->git($this->fixture->project, 'status', '--porcelain')))->toBe('');

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_picked'
        && $context['project'] === 'fx' && $context['story'] === 'FX-1' && $context['option'] === 'b'
        && $context['commit'] === ($this->head)())->once();
});

it('shows the set as picked in the gallery once the pick is committed', function () {
    $this->get('/mockups')->assertSeeInOrder(['data-set="fx/FX-1"', 'data-state="awaiting"'], false);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'cleaner table');

    $this->get('/mockups')->assertOk()->assertSeeInOrder(['data-set="fx/FX-1"', 'data-state="picked"', 'Picked B'], false)
        ->assertSee('cleaner table');
});

it('leaves another staged file staged and out of the pick commit', function () {
    File::put($this->fixture->project.'/notes.md', "wip\n");
    $this->fixture->git($this->fixture->project, 'add', 'notes.md');

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'cleaner table')
        ->assertSet('refusal', null);

    expect(trim($this->fixture->git($this->fixture->project, 'show', '--name-only', '--format=', 'HEAD')))->toBe('stories/demo/FX-1-story.md')
        ->and(trim($this->fixture->git($this->fixture->project, 'diff', '--cached', '--name-only')))->toBe('notes.md');
});

it('refuses the pick with "this story has unsaved changes" when the story file has uncommitted edits', function () {
    Log::spy();
    File::append($this->storyFile, "\nan unsaved edit\n");
    $edited = File::get($this->storyFile);
    $before = ($this->head)();

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'cleaner table')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'this story has unsaved changes'));

    expect(File::get($this->storyFile))->toBe($edited)->and(($this->head)())->toBe($before);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_pick_refused'
        && $context['project'] === 'fx' && $context['story'] === 'FX-1' && str_contains($context['reason'], 'unsaved changes'))->once();
});

it('refuses the pick naming both branches when the checkout is on a different branch than the board reads', function () {
    $this->fixture->git($this->fixture->project, 'checkout', '--quiet', '-b', 'feature/elsewhere');
    $unchanged = File::get($this->storyFile);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'cleaner table')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'feature/elsewhere') && str_contains($r, 'main'));

    expect(File::get($this->storyFile))->toBe($unchanged);
});

it('shows no Pick button for a story that already has a pick, and refuses a forged pick request', function () {
    Log::spy();
    $file = $this->fixture->project.'/stories/demo/FX-2-story.md';
    $unchanged = File::get($file);

    $this->get('/mockups/fx/FX-2')->assertOk()->assertDontSee('data-pick=', false)->assertSee('Picked A');
    $this->get('/mockups/fx/FX-1')->assertOk()->assertSee('data-pick="b"', false);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-2'])->call('pick', 'b', 'forged')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'already has a pick'));

    expect(File::get($file))->toBe($unchanged);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_pick_refused' && $context['story'] === 'FX-2')->once();
});

it('writes a reason with newlines or over 200 characters as one line, truncated', function () {
    $reason = "first line\nsecond line\r\n".str_repeat('x', 300);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'c', $reason)
        ->assertSet('refusal', null);

    preg_match('/^- Why I chose it: owner pick \d{4}-\d{2}-\d{2} \(board\): (.*)$/m', File::get($this->storyFile), $m);
    expect($m[1])->toStartWith('first line second line x')
        ->and(mb_strlen($m[1]))->toBe(200)
        ->and(File::get($this->storyFile))->toContain("- Chosen option: c\n");
});

it('refuses a pick when the story has no mockup gate section or no Chosen option line', function (string $story, string $why) {
    $file = $this->fixture->project."/stories/demo/{$story}-story.md";
    $unchanged = File::get($file);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => $story])->call('pick', 'a', 'x')
        ->assertSet('refusal', fn ($r) => str_contains($r, $why));

    expect(File::get($file))->toBe($unchanged);
})->with([
    'no gate section' => ['FX-3', 'no Design mockup gate section'],
    'no Chosen option line' => ['FX-4', 'no “Chosen option:” line'],
]);

it('refuses a pick while a merge is in progress in the checkout', function () {
    File::put($this->fixture->project.'/.git/MERGE_HEAD', ($this->head)()."\n");
    $unchanged = File::get($this->storyFile);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'x')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'merge is in progress'));

    expect(File::get($this->storyFile))->toBe($unchanged);
});

it('refuses a pick of an option the set does not have', function () {
    $unchanged = File::get($this->storyFile);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'z', 'x')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'no option z'));

    expect(File::get($this->storyFile))->toBe($unchanged);
});

it('refuses a pick when the story file is not in the checkout', function () {
    File::delete($this->storyFile);
    $before = ($this->head)();

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'x')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'not in the checkout'));

    expect(($this->head)())->toBe($before);
});

it('restores the story file when git cannot commit the pick', function () {
    // A held index lock makes `git commit` fail after the file has been rewritten.
    File::put($this->fixture->project.'/.git/index.lock', '');
    $unchanged = File::get($this->storyFile);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'x')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'git could not commit'));

    expect(File::get($this->storyFile))->toBe($unchanged);
    File::delete($this->fixture->project.'/.git/index.lock');
});

it('refuses a pick for a story that is no longer draft or approved', function () {
    $this->fixture->story('FX-5', 'built', 'demo', "## Design mockup gate\n- Chosen option: _pending_\n- Why I chose it: _pending_\n")
        ->write('docs/mockups/FX-5/option-a.html', '<p>a</p>')->commitAndPush();
    $this->artisan('board:refresh')->assertSuccessful();
    $this->fixture->syncProject();
    $row = Story::onRef()->where('story_id', 'FX-5')->firstOrFail();

    expect(fn () => app(StoryPickWriter::class)->pick($this->project, $row, ['a'], 'a', 'x'))
        ->toThrow(StoryPickRefusedException::class, 'only draft or approved');
    expect(File::get($this->fixture->project.'/stories/demo/FX-5-story.md'))->toContain('Chosen option: _pending_');
});

it('refuses a pick for a project taken off the board', function () {
    $this->project->update(['is_enabled' => false]);
    $row = Story::onRef()->where('story_id', 'FX-1')->firstOrFail();

    expect(fn () => app(StoryPickWriter::class)->pick($this->project, $row, ['a', 'b', 'c'], 'b', 'x'))
        ->toThrow(StoryPickRefusedException::class, 'not on the board');
});

it('refuses to write through a story file that is a symlink', function () {
    $target = $this->fixture->root.'/outside.md';
    File::put($target, File::get($this->storyFile));
    File::delete($this->storyFile);
    symlink($target, $this->storyFile);
    $unchanged = File::get($target);

    Livewire::test(MockupViewer::class, ['project' => $this->project, 'story' => 'FX-1'])->call('pick', 'b', 'x')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'not in the checkout'));

    expect(File::get($target))->toBe($unchanged);
});

it('adds a Why I chose it line under Chosen option when the gate has none', function () {
    $this->fixture->story('FX-6', 'draft', 'demo', "## Design mockup gate\n- Mockups: docs/mockups/FX-6/\n- Chosen option: _pending_\n\n## Links\n")
        ->write('docs/mockups/FX-6/option-a.html', '<p>a</p>')->commitAndPush();
    $this->artisan('board:refresh')->assertSuccessful();
    $this->fixture->syncProject();

    Livewire::test(MockupViewer::class, ['project' => $this->project->fresh(), 'story' => 'FX-6'])->call('pick', 'a', 'only one')
        ->assertSet('refusal', null);

    expect(File::get($this->fixture->project.'/stories/demo/FX-6-story.md'))
        ->toMatch('/^- Chosen option: a\n- Why I chose it: owner pick \d{4}-\d{2}-\d{2} \(board\): only one\n\n## Links/m');
});
