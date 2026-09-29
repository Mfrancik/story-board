<?php

use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\Story;
use Database\Seeders\ProjectSeeder;
use Illuminate\Support\Facades\Bus;
use Tests\Support\GitFixture;

it('registers a project from its path, named after its directory', function () {
    $fixture = new GitFixture;

    $this->artisan('board:project', ['action' => 'add', 'path' => $fixture->project])
        ->expectsOutputToContain('Registered project')
        ->assertSuccessful();

    $project = Project::sole();
    expect($project->name)->toBe('project')
        ->and($project->path)->toBe(realpath($fixture->project))
        ->and($project->ref)->toBe('origin/main')
        ->and($project->is_enabled)->toBeTrue();

    $fixture->destroy();
});

it('refuses to register a path that does not exist', function () {
    $this->artisan('board:project', ['action' => 'add', 'path' => '/definitely/not/here'])
        ->assertFailed();

    expect(Project::count())->toBe(0);
});

it('refuses to register the same path twice', function () {
    $fixture = new GitFixture;
    $this->artisan('board:project', ['action' => 'add', 'path' => $fixture->project])->assertSuccessful();

    $this->artisan('board:project', ['action' => 'add', 'path' => $fixture->project])->assertFailed();

    expect(Project::count())->toBe(1);
    $fixture->destroy();
});

it('lists and disables projects by name', function () {
    Project::factory()->create(['name' => 'coins']);

    $this->artisan('board:project', ['action' => 'list'])
        ->expectsOutputToContain('coins')
        ->assertSuccessful();
    $this->artisan('board:project', ['action' => 'disable', 'path' => 'coins'])->assertSuccessful();

    expect(Project::sole()->is_enabled)->toBeFalse();
});

it('seeds the four kit projects from the home directory', function () {
    $this->seed(ProjectSeeder::class);
    $this->seed(ProjectSeeder::class); // idempotent

    expect(Project::orderBy('name')->pluck('path', 'name')->all())->toBe([
        'asset-track' => $_SERVER['HOME'].'/Code/asset-track',
        'client-dashboard' => $_SERVER['HOME'].'/Code/client-dashboard',
        'coins' => $_SERVER['HOME'].'/Code/coins',
        'rent-track' => $_SERVER['HOME'].'/Code/rent-track',
    ]);
});

it('lists every project with its story count on the home page', function () {
    $project = Project::factory()->create(['name' => 'coins', 'indexed_at' => now()]);
    Story::factory()->count(3)->for($project)->create();

    $this->get('/')
        ->assertOk()
        ->assertSeeText('coins')
        ->assertSee('data-story-count="3"', false);
});

it('refreshes a project on page load once its snapshot is older than five minutes', function () {
    Bus::fake();
    $stale = Project::factory()->create(['name' => 'stale', 'indexed_at' => now()->subMinutes(6)]);
    $never = Project::factory()->create(['name' => 'never', 'indexed_at' => null]);
    Project::factory()->create(['name' => 'fresh', 'indexed_at' => now()->subMinutes(4)]);
    Project::factory()->disabled()->create(['name' => 'off', 'indexed_at' => null]);

    $this->get('/')->assertOk();

    Bus::assertDispatched(RefreshProjectJob::class, 2);
    Bus::assertDispatched(RefreshProjectJob::class, fn ($job) => $job->project->is($stale));
    Bus::assertDispatched(RefreshProjectJob::class, fn ($job) => $job->project->is($never));
});

it('refuses to register a project with a ref git would read as an option', function () {
    $fixture = new GitFixture;

    $this->artisan('board:project', ['action' => 'add', 'path' => $fixture->project, '--ref' => '--output=/tmp/x'])
        ->expectsOutputToContain('Invalid ref')
        ->assertFailed();

    expect(Project::count())->toBe(0);
    $fixture->destroy();
});

it('does not retry a failing project on every page load', function () {
    Bus::fake();
    Project::factory()->create(['state' => Project::STATE_STALE, 'indexed_at' => now()->subHour(), 'refresh_attempted_at' => now()->subMinute()]);
    $due = Project::factory()->create(['state' => Project::STATE_STALE, 'indexed_at' => now()->subHour(), 'refresh_attempted_at' => now()->subMinutes(6)]);

    $this->get('/')->assertOk();

    Bus::assertDispatched(RefreshProjectJob::class, 1);
    Bus::assertDispatched(RefreshProjectJob::class, fn ($job) => $job->project->is($due));
});
