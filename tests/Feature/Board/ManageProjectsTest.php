<?php

use App\Jobs\RefreshProjectJob;
use App\Livewire\Board\ManageProjects;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\GitFixture;

/**
 * SB-12 acceptance criteria: the Manage projects page at `/projects` — switch a
 * project off and on, add one by its folder, remove one through a confirmation —
 * and `board:project enable`. Every refusal has a test and a log line (L-5).
 */
beforeEach(function () {
    // Never a real refresh: enabling and adding queue one, and the tests only count them.
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    foreach (['client-dashboard', 'rent-track', 'asset-track'] as $name) {
        Project::factory()->create(['name' => $name, ...$fresh]);
    }
    $this->acme = Project::factory()->disabled()->create(['name' => 'acme-site', ...$fresh]);
});

/**
 * Whether `event` was logged at `level` with context containing `expected`.
 *
 * @param  array<string, mixed>  $expected
 */
function loggedWith(string $level, string $event, array $expected): void
{
    Log::shouldHaveReceived($level)->withArgs(fn ($e, $c = []) => $e === $event
        && array_intersect_assoc(array_map('strval', $c), array_map('strval', $expected)) == array_map('strval', $expected))->once();
}

/**
 * The opening tag of one project's switch on the page.
 */
function switchFor(string $html, string $name): string
{
    preg_match('/<button\b[^>]*data-project-switch="'.preg_quote($name, '/').'"[^>]*>/', $html, $m);

    return $m[0] ?? '';
}

it('lists all 5 projects with acme-site switched off, given 4 enabled and a disabled acme-site', function () {
    Story::factory()->count(3)->for($this->coins)->create();

    $html = $this->get('/projects')->assertOk()
        ->assertSee('Manage projects')
        ->assertSeeInOrder(['acme-site', 'asset-track', 'client-dashboard', 'coins', 'rent-track'])
        ->getContent();

    expect(substr_count($html, 'data-project-switch='))->toBe(5)
        ->and(switchFor($html, 'acme-site'))->toContain('aria-checked="false"')
        ->and(switchFor($html, 'coins'))->toContain('aria-checked="true"')
        ->and($html)->toMatch('/data-project-row="coins".*?data-project-stories="3"/s')
        ->and($html)->toContain('origin/main')
        ->and($html)->toContain('Refreshed');
});

it('links Manage projects from the sidebar', function () {
    $this->get('/')->assertOk()->assertSee('Manage projects')->assertSee(route('projects.manage'));
});

it('switches coins off: is_enabled false, gone from the sidebar and /, stories kept, board.project_disabled logged with source=ui', function () {
    Log::spy();
    Story::factory()->count(2)->for($this->coins)->create();

    Livewire::test(ManageProjects::class)
        ->call('setEnabled', $this->coins->id, false)
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'coins switched off');

    expect($this->coins->fresh()->is_enabled)->toBeFalse()
        ->and(Story::where('project_id', $this->coins->id)->count())->toBe(2);
    $this->get('/')->assertOk()->assertDontSeeHtml('data-sidebar-project="coins"');
    loggedWith('info', 'board.project_disabled', ['project' => 'coins', 'source' => 'ui']);
    Queue::assertNothingPushed();
});

it('switches coins on: is_enabled true, one RefreshProjectJob for coins queued, board.project_enabled logged', function () {
    Log::spy();
    $this->coins->update(['is_enabled' => false]);

    Livewire::test(ManageProjects::class)
        ->call('setEnabled', $this->coins->id, true)
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'coins switched on');

    expect($this->coins->fresh()->is_enabled)->toBeTrue();
    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn ($job) => $job->project->is($this->coins));
    loggedWith('info', 'board.project_enabled', ['project' => 'coins', 'source' => 'ui']);
});

it('refuses to switch a project that is not on the board and logs why', function () {
    Log::spy();

    Livewire::test(ManageProjects::class)->call('setEnabled', 999999, false)->assertOk();

    loggedWith('info', 'board.project_switch_refused', ['id' => 999999, 'reason' => 'unknown']);
    Queue::assertNothingPushed();
});

it('adds a fixture repo with no name as a pending project named after its folder, queues a refresh and logs board.project_registered', function () {
    Log::spy();
    $fixture = new GitFixture;

    Livewire::test(ManageProjects::class)
        ->set('path', $fixture->project)
        ->set('ref', 'origin/main')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('path', '')
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'Project added');

    $project = Project::where('name', 'project')->sole();
    expect($project->state)->toBe(Project::STATE_PENDING)
        ->and($project->path)->toBe(realpath($fixture->project));
    Queue::assertPushed(RefreshProjectJob::class, fn ($job) => $job->project->is($project));
    loggedWith('info', 'board.project_registered', ['project' => 'project']);

    $fixture->destroy();
});

it('refuses a path that is not a git repository: no project, the path field says so, board.project_add_refused logged with reason not_a_repo', function () {
    Log::spy();
    $dir = sys_get_temp_dir().'/story-board-fixtures/not-a-repo-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    Livewire::test(ManageProjects::class)
        ->set('path', $dir)
        ->call('add')
        ->assertHasErrors(['path'])
        ->assertSee('That folder is not a git repository.');

    expect(Project::count())->toBe(5);
    loggedWith('info', 'board.project_add_refused', ['reason' => 'not_a_repo']);
    Queue::assertNothingPushed();
    rmdir($dir);
});

it('refuses a path already registered: the path field shows "Already on the board as <name>." and board.project_add_refused is logged with reason duplicate', function () {
    Log::spy();
    $fixture = new GitFixture;
    $this->coins->update(['path' => realpath($fixture->project)]);

    Livewire::test(ManageProjects::class)
        ->set('path', $fixture->project)
        ->set('name', 'something-else')
        ->call('add')
        ->assertHasErrors(['path'])
        ->assertHasNoErrors(['name'])
        ->assertSee('Already on the board as coins.');

    expect(Project::count())->toBe(5);
    loggedWith('info', 'board.project_add_refused', ['reason' => 'duplicate']);
    $fixture->destroy();
});

it('refuses a name already registered: the name field shows "Already on the board as <name>." and board.project_add_refused is logged with reason duplicate', function () {
    Log::spy();
    $fixture = new GitFixture;

    Livewire::test(ManageProjects::class)
        ->set('path', $fixture->project)
        ->set('name', 'coins')
        ->call('add')
        ->assertHasErrors(['name'])
        ->assertHasNoErrors(['path'])
        ->assertSee('Already on the board as coins.');

    expect(Project::count())->toBe(5);
    loggedWith('info', 'board.project_add_refused', ['reason' => 'duplicate']);
    $fixture->destroy();
});

it('refuses the ref -x or a..b: the ref field says "That is not a valid git ref." and board.project_add_refused is logged with reason bad_ref', function (string $ref) {
    Log::spy();
    $fixture = new GitFixture;

    Livewire::test(ManageProjects::class)
        ->set('path', $fixture->project)
        ->set('ref', $ref)
        ->call('add')
        ->assertHasErrors(['ref'])
        ->assertSee('That is not a valid git ref.');

    expect(Project::count())->toBe(5);
    loggedWith('info', 'board.project_add_refused', ['reason' => 'bad_ref']);
    $fixture->destroy();
})->with(['-x', 'a..b']);

it('refuses a blank or missing path with reason missing_path, expanding ~ first', function () {
    Log::spy();

    Livewire::test(ManageProjects::class)
        ->set('path', '  ')
        ->call('add')
        ->assertHasErrors(['path'])
        ->assertSee('Enter the folder of a git checkout.')
        ->set('path', '~/story-board-no-such-folder-sb12')
        ->call('add')
        ->assertHasErrors(['path'])
        ->assertSee('That folder does not exist.');

    expect(Project::count())->toBe(5);
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_add_refused'
        && $c['reason'] === 'missing_path')->twice();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_add_refused'
        && $c['path'] === $_SERVER['HOME'].'/story-board-no-such-folder-sb12')->once();
});

it('deletes nothing when Remove is clicked for acme-site until "Remove project" is confirmed in the modal; Cancel leaves it untouched', function () {
    $html = $this->get('/projects')->assertOk()->getContent();

    // The row's Remove only opens the Alpine modal; the one server call is on the modal's confirm button.
    preg_match('/<button\b[^>]*data-remove="acme-site"[^>]*>/', $html, $remove);
    expect($remove[0] ?? '')->not->toBe('')
        ->and($remove[0])->not->toContain('wire:click')
        ->and($html)->toContain('role="alertdialog"')
        ->and($html)->toContain('Remove project')
        ->and($html)->toContain('Cancel')
        ->and($html)->toContain('folder and git are not touched.');
    expect(Project::where('name', 'acme-site')->exists())->toBeTrue();
});

it('removes acme-site once confirmed: project, stories and locations gone, its folder\'s git status byte-identical, board.project_removed logged with project and stories', function () {
    Log::spy();
    $fixture = new GitFixture;
    $fixture->untracked($fixture->project, 'stories/demo/X-1-wip.md', "# X-1\n");
    $this->acme->update(['path' => $fixture->project]);
    Story::factory()->count(3)->for($this->acme)->create();
    ProjectLocation::create(['project_id' => $this->acme->id, 'kind' => 'alias', 'path' => '/tmp/acme-2']);
    $status = fn () => (new Process(['git', 'status', '--porcelain'], $fixture->project))->mustRun()->getOutput();
    $before = $status();

    Livewire::test(ManageProjects::class)
        ->call('remove', $this->acme->id)
        ->assertDispatched('toast-show', fn ($event, $params) => ($params['slots']['text'] ?? null) === 'Project removed');

    expect(Project::find($this->acme->id))->toBeNull()
        ->and(Story::where('project_id', $this->acme->id)->count())->toBe(0)
        ->and(ProjectLocation::where('project_id', $this->acme->id)->count())->toBe(0)
        ->and($status())->toBe($before)
        ->and(is_dir($fixture->project.'/.git'))->toBeTrue();
    loggedWith('warning', 'board.project_removed', ['project' => 'acme-site', 'stories' => 3]);
    $fixture->destroy();
});

it('refuses to remove a project that is not on the board and logs why', function () {
    Log::spy();

    Livewire::test(ManageProjects::class)->call('remove', 999999)->assertOk();

    expect(Project::count())->toBe(5);
    loggedWith('info', 'board.project_remove_refused', ['id' => 999999, 'reason' => 'unknown']);
});

it('enables acme-site with board:project enable and queues one refresh; enable nope exits non-zero with "No project named nope"', function () {
    Log::spy();

    $this->artisan('board:project', ['action' => 'enable', 'path' => 'acme-site'])->assertSuccessful();

    expect($this->acme->fresh()->is_enabled)->toBeTrue();
    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn ($job) => $job->project->is($this->acme));
    loggedWith('info', 'board.project_enabled', ['project' => 'acme-site', 'source' => 'cli']);

    $this->artisan('board:project', ['action' => 'enable', 'path' => 'nope'])
        ->expectsOutputToContain('No project named nope')
        ->assertFailed();
    loggedWith('info', 'board.project_switch_refused', ['project' => 'nope', 'reason' => 'unknown', 'source' => 'cli']);
});

it('logs board.project_disabled with source=cli from board:project disable', function () {
    Log::spy();

    $this->artisan('board:project', ['action' => 'disable', 'path' => 'coins'])->assertSuccessful();

    expect($this->coins->fresh()->is_enabled)->toBeFalse();
    loggedWith('info', 'board.project_disabled', ['project' => 'coins', 'source' => 'cli']);
});
