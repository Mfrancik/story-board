<?php

use App\Exceptions\GitReaderException;
use App\Livewire\Board\Home;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * L-5: every guard that refuses input says why in the log. One test per guard
 * the phase-1 full sweep found silent.
 */
beforeEach(fn () => Log::spy());

function logged(string $level, string $event, ?callable $context = null): void
{
    Log::shouldHaveReceived($level)->withArgs(fn ($e, $ctx = []) => $e === $event && ($context === null || $context($ctx)))->atLeast()->once();
}

it('logs a story page request for a disabled project', function () {
    Project::factory()->disabled()->create(['name' => 'off']);

    $this->get('/p/off/s/AB-1')->assertNotFound();

    logged('info', 'board.story_not_found', fn ($c) => $c['reason'] === 'project disabled');
});

it('logs a story page request for an unknown story', function () {
    Project::factory()->create(['name' => 'on']);

    $this->get('/p/on/s/AB-1')->assertNotFound();

    logged('info', 'board.story_not_found', fn ($c) => $c['reason'] === 'no such story or version');
});

it('logs a mockup request for a disabled project', function () {
    Project::factory()->disabled()->create(['name' => 'off']);

    $this->get('/p/off/m/AB-1/option-a.html')->assertNotFound();

    logged('info', 'board.mockup_not_found', fn ($c) => $c['reason'] === 'project disabled');
});

it('logs an expand of a row that is gone', function () {
    Livewire::test(Home::class)->call('expand', 999999);

    logged('info', 'board.story_not_found', fn ($c) => $c['row'] === 999999);
});

it('logs a refused project registration', function () {
    $this->artisan('board:project', ['action' => 'add', 'path' => '/definitely/not/here'])->assertFailed();

    logged('warning', 'board.project_registration_refused');
});

it('logs a refused alias registration', function () {
    $this->artisan('board:project', ['action' => 'alias', 'path' => '/tmp', '--name' => 'nope'])->assertFailed();

    logged('warning', 'board.alias_registration_refused');
});

it('logs a git command the read-only rule refuses', function () {
    expect(fn () => app(GitReader::class)->run('/tmp', ['checkout', 'main']))->toThrow(GitReaderException::class);

    logged('warning', 'board.git_refused', fn ($c) => $c['subcommand'] === 'checkout');
});
