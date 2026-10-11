<?php

use App\Actions\Board\ReadMockupSets;
use App\Jobs\RefreshProjectJob;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SessionFixture;

/**
 * RD-2 acceptance criteria: the dark command-center shell (layouts/shell) and its sidebar
 * (shell/sidebar), with the Preflight page as its first user, and the refresh-on-load
 * middleware on the board's page routes. The 375 px drawer is in tests/Browser/DarkShellTest.php.
 * The Preflight page reads a temp Claude projects root, never the real ~/.claude.
 */
beforeEach(function () {
    Queue::fake();
    $this->claude = new SessionFixture;
    config(['board.sessions_path' => $this->claude->root]);

    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now()];
    $this->coins = Project::factory()->create(['name' => 'coins', 'state' => Project::STATE_OK, ...$fresh]);
    $this->cd = Project::factory()->create(['name' => 'client-dashboard', 'state' => Project::STATE_STALE, ...$fresh]);
});

afterEach(function () {
    $this->claude->destroy();
});

/**
 * The opening tag of one project's sidebar link, so attribute assertions stay on that entry.
 */
function shellEntry(string $html, string $name): string
{
    preg_match('/<a\b[^>]*data-nav-project="'.preg_quote($name, '/').'"[^>]*>/', $html, $m);

    return $m[0] ?? '';
}

it('Given two enabled projects and one disabled, when a board page renders in the shell, then the sidebar lists the two enabled ones with their state, and the disabled one is not listed', function () {
    Project::factory()->disabled()->create(['name' => 'acme-site', 'indexed_at' => now(), 'refresh_attempted_at' => now()]);

    $html = $this->get('/p/coins/preflight')->assertOk()
        ->assertSeeHtml('data-shell-sidebar')
        ->assertSeeInOrder(['data-nav-project="client-dashboard"', 'data-nav-project="coins"'], false)
        ->assertDontSeeHtml('data-nav-project="acme-site"')
        ->getContent();

    expect($html)->toMatch('/data-nav-project="coins".*?data-state-dot="ok"/s')
        ->and($html)->toMatch('/data-nav-project="client-dashboard".*?data-state-dot="stale"/s')
        // Each entry opens the project's Stories page.
        ->and(shellEntry($html, 'coins'))->toContain('href="'.route('projects.stories', 'coins').'"');
});

it('Given three mockup sets awaiting a pick, then the Mockups item shows 3; with none, then it shows no count', function () {
    $this->partialMock(ReadMockupSets::class)->shouldReceive('awaitingCount')->andReturn(3, 0);

    $this->get('/p/coins/preflight')->assertOk()
        ->assertSeeHtml('data-nav="mockups"')
        ->assertSeeHtml('data-mockups-count="3"');

    $this->get('/p/coins/preflight')->assertOk()
        ->assertSeeHtml('data-nav="mockups"')
        ->assertDontSeeHtml('data-mockups-count');
});

it('Given the Preflight page, then its sidebar entry for that project is marked current', function () {
    $html = $this->get('/p/coins/preflight')->assertOk()->getContent();

    expect(shellEntry($html, 'coins'))->toContain('aria-current="page"')
        ->and(shellEntry($html, 'client-dashboard'))->not->toContain('aria-current')
        ->and($html)->not->toMatch('/<a\b[^>]*data-nav="home"[^>]*aria-current/');
});

it('Given a project that needs a refresh, when any board page loads, then exactly one refresh job is queued for it; given a fresh project, then none is', function (string $url) {
    Log::spy();
    $this->coins->update(['refresh_attempted_at' => now()->subHour(), 'indexed_at' => now()->subHour()]);

    $this->get($url)->assertOk();

    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn (RefreshProjectJob $job) => $job->project->is($this->coins));
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.stale_refresh_dispatched'
        && $ctx === ['project' => 'coins'])->once();
})->with(['/p/coins/preflight', '/', '/p/coins', '/mockups', '/projects']);

it('queues nothing on a board page load when every project is fresh, and never for a disabled one', function () {
    Project::factory()->disabled()->create(['name' => 'acme-site', 'indexed_at' => null, 'refresh_attempted_at' => null]);

    $this->get('/p/coins/preflight')->assertOk();

    Queue::assertNothingPushed();
});

it('Given no enabled projects, then the sidebar shows "No projects yet" and a link to /projects', function () {
    Project::query()->update(['is_enabled' => false]);

    $this->blade('<x-shell.sidebar />')
        ->assertSee('No projects yet')
        ->assertSee('href="'.url('/projects').'"', false)
        ->assertDontSee('data-nav-project', false);
});

it('shows each project\'s story ID prefix, its most used one, and none for a project without stories', function () {
    Story::factory()->count(3)->for($this->coins)->sequence(['story_id' => 'AUC-1'], ['story_id' => 'AUC-2'], ['story_id' => 'MOB-1'])->create();

    $html = $this->get('/p/coins/preflight')->assertOk()->getContent();

    preg_match('/<a\b[^>]*data-nav-project="client-dashboard".*?<\/a>/s', $html, $cd);

    expect($html)->toMatch('/data-nav-project="coins".*?data-id-prefix>AUC</s')
        ->and($cd[0] ?? '')->toContain('client-dashboard')->not->toContain('data-id-prefix');
});

it('renders the Preflight page dark-only with self-hosted Geist and no runtime web fonts or theme switch', function () {
    $this->get('/p/coins/preflight')->assertOk()
        ->assertSeeHtml('class="shell')
        ->assertDontSeeHtml('fonts.googleapis.com')
        ->assertDontSeeHtml('fonts.bunny.net')
        ->assertDontSeeHtml('flux.appearance');
});
