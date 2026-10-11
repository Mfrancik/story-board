<?php

use App\Actions\Board\AddProject;
use App\Actions\Board\RemoveProject;
use App\Actions\Board\SwitchProject;
use App\Exceptions\ProjectAddRefusedException;
use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Story;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * RD-1: AddProject, SwitchProject and RemoveProject called directly, so their
 * coverage does not depend on the Manage projects page RD-9 replaces. No page
 * is loaded here.
 */
beforeEach(function () {
    // Never a real refresh: the actions queue one, and the tests only count them.
    Queue::fake();
});

/**
 * The refusal AddProject throws for these arguments, or null when it adds.
 */
function rdAddRefusal(string $path, ?string $name = null, ?string $ref = null): ?ProjectAddRefusedException
{
    try {
        app(AddProject::class)->handle($path, $name, $ref);
    } catch (ProjectAddRefusedException $e) {
        return $e;
    }

    return null;
}

it('adds a git checkout as a pending project named after its folder and queues its first refresh', function () {
    Log::spy();
    $fixture = new GitFixture;

    // The row's state is a column default, so it is read back rather than taken from the returned model.
    $project = app(AddProject::class)->handle("  {$fixture->project}  ", ' ', '')->fresh();

    expect($project->name)->toBe('project')
        ->and($project->ref)->toBe('origin/main')
        ->and($project->state)->toBe(Project::STATE_PENDING)
        ->and($project->path)->toBe(realpath($fixture->project));
    Queue::assertPushed(RefreshProjectJob::class, fn ($job) => $job->project->is($project));
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_registered' && $c['project'] === 'project')->once();
    $fixture->destroy();
});

it('adds a project under the name and ref it is given', function () {
    $fixture = new GitFixture;

    $project = app(AddProject::class)->handle($fixture->project, 'named', 'origin/main');

    expect($project->name)->toBe('named')->and($project->ref)->toBe('origin/main');
    $fixture->destroy();
});

it('refuses each bad input with its field and reason, logs why, and adds nothing', function () {
    Log::spy();
    $fixture = new GitFixture;
    $notRepo = sys_get_temp_dir().'/story-board-rd1-not-a-repo';
    File::ensureDirectoryExists($notRepo);
    Project::factory()->create(['name' => 'taken', 'path' => realpath($fixture->project)]);
    $other = new GitFixture;

    $cases = [
        // A blank path would be realpath('') — the board's own checkout — so it is refused before RegisterProject.
        [rdAddRefusal('  '), 'path', ProjectAddRefusedException::MISSING_PATH, 'Enter the folder of a git checkout.'],
        [rdAddRefusal('~/story-board-no-such-folder-rd1'), 'path', ProjectAddRefusedException::MISSING_PATH, 'That folder does not exist.'],
        [rdAddRefusal($notRepo), 'path', ProjectAddRefusedException::NOT_A_REPO, 'That folder is not a git repository.'],
        [rdAddRefusal($fixture->project), 'path', ProjectAddRefusedException::DUPLICATE, 'Already on the board as taken.'],
        [rdAddRefusal($other->project, 'taken'), 'name', ProjectAddRefusedException::DUPLICATE, 'Already on the board as taken.'],
        [rdAddRefusal($other->project, null, '-x'), 'ref', ProjectAddRefusedException::BAD_REF, 'That is not a valid git ref.'],
    ];

    foreach ($cases as [$refusal, $field, $reason, $message]) {
        expect($refusal)->toBeInstanceOf(ProjectAddRefusedException::class)
            ->and($refusal->field)->toBe($field)
            ->and($refusal->reason)->toBe($reason)
            ->and($refusal->getMessage())->toBe($message);
    }
    expect(Project::count())->toBe(1);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_add_refused')->times(6);
    // `~` is the home folder, as in a shell, before the path is checked or logged.
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_add_refused'
        && $c['path'] === AddProject::home().'/story-board-no-such-folder-rd1')->once();

    File::deleteDirectory($notRepo);
    $fixture->destroy();
    $other->destroy();
});

it('switches a project off: hidden, rows kept, logged with its source, nothing queued', function () {
    Log::spy();
    $project = Project::factory()->create(['name' => 'coins']);
    Story::factory()->count(2)->for($project)->create();

    app(SwitchProject::class)->handle($project, false, SwitchProject::SOURCE_UI);

    expect($project->fresh()->is_enabled)->toBeFalse()
        ->and(Story::where('project_id', $project->id)->count())->toBe(2);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->with('board.project_disabled', ['project' => 'coins', 'source' => 'ui'])->once();
});

it('switches a project on: shown, one refresh queued, logged with its source', function () {
    Log::spy();
    $project = Project::factory()->disabled()->create(['name' => 'acme']);

    app(SwitchProject::class)->handle($project, true, SwitchProject::SOURCE_CLI);

    expect($project->fresh()->is_enabled)->toBeTrue();
    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn ($job) => $job->project->is($project));
    Log::shouldHaveReceived('info')->with('board.project_enabled', ['project' => 'acme', 'source' => 'cli'])->once();
});

it('removes a project with its stories and locations, returns the story count and logs a warning', function () {
    Log::spy();
    $project = Project::factory()->create(['name' => 'acme']);
    Story::factory()->count(3)->for($project)->create();
    ProjectLocation::create(['project_id' => $project->id, 'kind' => 'alias', 'path' => '/tmp/acme-rd1']);
    $kept = Project::factory()->create(['name' => 'kept']);
    Story::factory()->for($kept)->create();

    expect(app(RemoveProject::class)->handle($project))->toBe(3);

    expect(Project::find($project->id))->toBeNull()
        ->and(Story::where('project_id', $project->id)->count())->toBe(0)
        ->and(ProjectLocation::where('project_id', $project->id)->count())->toBe(0)
        ->and(Story::where('project_id', $kept->id)->count())->toBe(1);
    Log::shouldHaveReceived('warning')->with('board.project_removed', ['project' => 'acme', 'stories' => 3])->once();
});
