<?php

use App\Actions\Board\RefreshProject;
use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

beforeEach(function () {
    $this->fixture = new GitFixture;
});

afterEach(function () {
    $this->fixture->destroy();
});

it('stores one row per story on origin/main with the ref SHA', function () {
    $this->fixture->story('FX-1', 'draft')->story('FX-2', 'approved')->story('FX-3', 'built')
        ->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);

    $this->artisan('board:refresh')->assertSuccessful();

    $project->refresh();
    expect($project->stories()->orderBy('story_id')->pluck('status', 'story_id')->all())
        ->toBe(['FX-1' => 'draft', 'FX-2' => 'approved', 'FX-3' => 'built'])
        ->and($project->sha)->toBe($this->fixture->originSha())
        ->and($project->state)->toBe(Project::STATE_OK)
        ->and($project->indexed_at)->not->toBeNull()
        ->and(Story::where('sha', $this->fixture->originSha())->count())->toBe(3);
});

it('reads statuses from origin/main, not from a working tree that is behind it', function () {
    $this->fixture->story('FX-1', 'draft')->commitAndPush()->syncProject();
    // origin moves on; the registered clone's working tree still says draft.
    $this->fixture->story('FX-1', 'built')->commitAndPush('feat(FX-1): build');
    $project = Project::factory()->create(['path' => $this->fixture->project]);

    $this->artisan('board:refresh')->assertSuccessful();

    expect(file_get_contents($this->fixture->project.'/stories/demo/FX-1-story.md'))->toContain('Status: draft')
        ->and($project->stories()->value('status'))->toBe('built')
        ->and($project->refresh()->sha)->toBe($this->fixture->originSha());
});

it('marks a path that is no longer a git repo unreachable and still refreshes the others', function () {
    Log::spy();
    $this->fixture->story('FX-1', 'draft')->commitAndPush();
    $good = Project::factory()->create(['name' => 'good', 'path' => $this->fixture->project]);
    $gone = Project::factory()->create(['name' => 'gone', 'path' => $this->fixture->root.'/not-a-repo']);
    mkdir($gone->path);

    $this->artisan('board:refresh')->assertSuccessful();

    expect($gone->refresh()->state)->toBe(Project::STATE_UNREACHABLE)
        ->and($good->refresh()->state)->toBe(Project::STATE_OK)
        ->and($good->stories()->count())->toBe(1);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($event, $context) => $event === 'board.project_unreachable' && $context['project'] === 'gone')
        ->once();
});

it('keeps the last snapshot and marks it stale when git fetch fails', function () {
    Log::spy();
    $this->fixture->story('FX-1', 'draft')->story('FX-2', 'draft')->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);
    $this->artisan('board:refresh')->assertSuccessful();
    $sha = $project->refresh()->sha;

    // The remote disappears: the same failure as being offline, without a network.
    $this->fixture->story('FX-3', 'draft')->commitAndPush();
    File::deleteDirectory($this->fixture->origin);
    $this->artisan('board:refresh')->assertSuccessful();

    $project->refresh();
    expect($project->state)->toBe(Project::STATE_STALE)
        ->and($project->sha)->toBe($sha)
        ->and($project->stories()->count())->toBe(2)
        ->and($project->last_error)->not->toBeEmpty();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($event) => $event === 'board.fetch_failed')
        ->once();
});

it('stores a story with parse errors instead of dropping it', function () {
    $this->fixture->story('FX-1', 'wip')->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);

    $this->artisan('board:refresh')->assertSuccessful();

    $story = $project->stories()->sole();
    expect($story->status)->toBe('wip')
        ->and($story->parse_errors)->toHaveCount(1)
        ->and($story->parse_errors[0])->toContain("status 'wip'");
});

it('leaves every project working tree byte-identical', function () {
    $this->fixture->story('FX-1', 'draft')->commitAndPush()->syncProject();
    $this->fixture->story('FX-2', 'approved')->commitAndPush();
    // A dirty checkout: one untracked file and one modified tracked file.
    file_put_contents($this->fixture->project.'/scratch.txt', 'mine');
    file_put_contents($this->fixture->project.'/stories/demo/FX-1-story.md', "edited\n", FILE_APPEND);
    Project::factory()->create(['path' => $this->fixture->project]);
    $status = fn () => $this->fixture->git($this->fixture->project, 'status', '--porcelain');
    $head = fn () => $this->fixture->git($this->fixture->project, 'rev-parse', 'HEAD');
    [$statusBefore, $headBefore] = [$status(), $head()];

    $this->artisan('board:refresh')->assertSuccessful();

    expect($status())->toBe($statusBefore)->not->toBe('')
        ->and($head())->toBe($headBefore);
});

it('logs start and finish with count, sha and duration for each project', function () {
    Log::spy();
    $this->fixture->story('FX-1', 'draft')->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project]);

    $this->artisan('board:refresh')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn ($event) => $event === 'board.refresh_started')->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context) => $event === 'board.refresh_finished'
        && $context['project'] === 'fx'
        && $context['count'] === 1
        && $context['sha'] === $this->fixture->originSha()
        && is_int($context['ms']))->once();
});

it('refreshes only the named project, and skips disabled ones', function () {
    $this->fixture->story('FX-1', 'draft')->commitAndPush();
    $named = Project::factory()->create(['name' => 'named', 'path' => $this->fixture->project]);
    $other = Project::factory()->create(['name' => 'other', 'path' => $this->fixture->project]);
    $off = Project::factory()->disabled()->create(['name' => 'off', 'path' => $this->fixture->project]);

    $this->artisan('board:refresh', ['project' => 'named'])->assertSuccessful();
    expect($named->refresh()->indexed_at)->not->toBeNull()
        ->and($other->refresh()->indexed_at)->toBeNull();

    $this->artisan('board:refresh')->assertSuccessful();
    expect($other->refresh()->indexed_at)->not->toBeNull()
        ->and($off->refresh()->indexed_at)->toBeNull();
});

it('replaces the previous snapshot rather than appending to it', function () {
    $this->fixture->story('FX-1', 'draft')->story('FX-2', 'draft')->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);
    $this->artisan('board:refresh')->assertSuccessful();

    $this->fixture->git($this->fixture->author, 'rm', '--quiet', 'stories/demo/FX-2-story.md');
    $this->fixture->commitAndPush();
    $this->artisan('board:refresh')->assertSuccessful();

    expect($project->stories()->pluck('story_id')->all())->toBe(['FX-1']);
});

it('stands down when a refresh of the same project is already running', function () {
    Log::spy();
    $this->fixture->story('FX-1', 'draft')->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);
    $lock = Cache::lock("board:refresh:{$project->id}", 60);
    $lock->get();

    $this->artisan('board:refresh')->assertSuccessful();

    expect($project->refresh()->indexed_at)->toBeNull()
        ->and($project->stories()->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($event) => $event === 'board.refresh_skipped')->once();
    $lock->release();
});

it('keeps the snapshot when a stored ref is one git would read as an option', function () {
    Log::spy();
    $this->fixture->story('FX-1', 'draft')->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);
    $this->artisan('board:refresh')->assertSuccessful();
    $target = $this->fixture->root.'/written-by-git';
    $project->forceFill(['ref' => "--output={$target}"])->save();

    $this->artisan('board:refresh')->assertSuccessful();

    expect($project->refresh()->state)->toBe(Project::STATE_STALE)
        ->and($project->last_error)->toContain('invalid ref')
        ->and($project->stories()->count())->toBe(1)
        ->and(file_exists($target))->toBeFalse();
});

it('logs a refresh that crashes unexpectedly and rethrows', function () {
    Log::spy();
    $project = Project::factory()->create();
    $this->mock(RefreshProject::class)->shouldReceive('handle')->andThrow(new RuntimeException('db gone'));

    expect(fn () => app()->call([new RefreshProjectJob($project), 'handle']))->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('error')->withArgs(fn ($event, $ctx) => $event === 'board.refresh_crashed' && $ctx['exception'] === 'db gone')->once();
});

it('marks drafts in a parked draft group, and only a group whose README says so', function () {
    $this->fixture
        ->story('IMP-1', 'draft', 'import')
        ->write('stories/import/README.md', "# Import\n\nStatus: draft group — parked 2026-09-02\n")
        ->story('APP-1', 'draft', 'app-store-launch')
        ->write('stories/app-store-launch/README.md', "# App store\n\nStatus: release group — opened 2026-09-22\n")
        ->story('PAY-1', 'draft', 'payments')
        ->write('stories/payments/README.md', "# Payments\n\nNo status line here.\n")
        ->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);

    $this->artisan('board:refresh')->assertSuccessful();

    expect($project->stories()->where('is_parked', true)->pluck('story_id')->all())->toBe(['IMP-1']);
});

it('dates each story from the first date in its Source line', function () {
    $this->fixture->write('stories/demo/FX-1-story.md', "# FX-1 — Dated\nStatus: approved\nSource: owner 2026-07-02 (coins /story), revised 2026-09-01\n\n## Story\nx\n")
        ->story('FX-2', 'approved')
        ->commitAndPush();
    $project = Project::factory()->create(['path' => $this->fixture->project]);

    $this->artisan('board:refresh')->assertSuccessful();

    expect($project->stories()->orderBy('story_id')->get()->map(fn ($s) => $s->dated_on?->toDateString())->all())
        ->toBe(['2026-07-02', null]);
});
