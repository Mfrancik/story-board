<?php

use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

/**
 * SB-7 acceptance criteria: the sidebar shell, the single-project page at
 * `/p/{project}`, its refusals, and the old `/?project=` filter URL.
 */
beforeEach(function () {
    // Fresh snapshots, so mounting a page does not queue refreshes.
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->cd = Project::factory()->create(['name' => 'client-dashboard', ...$fresh]);
    $this->rent = Project::factory()->create(['name' => 'rent-track', ...$fresh]);
    $this->asset = Project::factory()->create(['name' => 'asset-track', ...$fresh]);
});

/**
 * The opening tag of one sidebar entry, so attribute assertions stay on that entry.
 */
function sidebarEntry(string $html, string $name): string
{
    preg_match('/<a\b[^>]*data-sidebar-project="'.preg_quote($name, '/').'"[^>]*>/', $html, $m);

    return $m[0] ?? '';
}

it('lists the enabled projects alphabetically with their story counts, and not the disabled one', function () {
    Project::factory()->disabled()->create(['name' => 'acme-site', 'indexed_at' => now()]);
    Story::factory()->count(3)->for($this->coins)->create();
    Story::factory()->count(2)->for($this->cd)->create();
    Story::factory()->for($this->rent)->create();
    // Off-main versions are not on the ref, so they are not counted.
    Story::factory()->for($this->coins)->create(['location_kind' => Story::KIND_BRANCH, 'location' => 'branch feat/x', 'branch' => 'feat/x']);

    $html = $this->get('/')->assertOk()
        ->assertSeeInOrder([
            'data-sidebar-project="asset-track"',
            'data-sidebar-project="client-dashboard"',
            'data-sidebar-project="coins"',
            'data-sidebar-project="rent-track"',
        ], false)
        ->assertDontSeeHtml('data-sidebar-project="acme-site"')
        ->getContent();

    expect(sidebarEntry($html, 'coins'))->toContain('data-story-count="3"')
        ->and(sidebarEntry($html, 'client-dashboard'))->toContain('data-story-count="2"')
        ->and(sidebarEntry($html, 'rent-track'))->toContain('data-story-count="1"')
        ->and(sidebarEntry($html, 'asset-track'))->toContain('data-story-count="0"')
        ->and($html)->toMatch('/<a\b[^>]*data-sidebar-all[^>]*aria-current="page"/');
});

it('marks coins current on /p/coins and shows only coins stories', function () {
    Log::spy();
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-15', 'status' => 'draft']);

    $html = $this->get('/p/coins')->assertOk()
        ->assertSee('AUC-17')
        ->assertDontSee('SS-15')
        ->getContent();

    expect(sidebarEntry($html, 'coins'))->toContain('aria-current="page"')
        ->and(sidebarEntry($html, 'client-dashboard'))->not->toContain('aria-current')
        ->and($html)->not->toMatch('/<a\b[^>]*data-sidebar-all[^>]*aria-current="page"/')
        ->and(sidebarEntry($html, 'coins'))->toContain('wire:navigate');
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_viewed' && $c['project'] === 'coins')->once();
});

it('404s /p/nope and logs board.project_page_refused with reason unknown', function () {
    Log::spy();

    $this->get('/p/nope')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused'
        && $c === ['project' => 'nope', 'reason' => 'unknown'])->once();
});

it('404s a disabled project page and logs board.project_page_refused with reason disabled', function () {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get('/p/acme-site')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused'
        && $c === ['project' => 'acme-site', 'reason' => 'disabled'])->once();
});

it('refuses the story page of an unknown or disabled project in the same place', function (string $url, string $reason) {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get($url)->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c['reason'] === $reason)->once();
})->with([
    'unknown' => ['/p/nope/s/AB-1', 'unknown'],
    'disabled' => ['/p/acme-site/s/AB-1', 'disabled'],
]);

it('redirects /?project=coins to /p/coins with a 301, and /?project=nope to /', function () {
    Log::spy();

    $this->get('/?project=coins')->assertStatus(301)->assertRedirect('/p/coins');
    $this->get('/?project=nope')->assertRedirect('/');

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_filter_redirected'
        && $c['project'] === 'nope' && $c['reason'] === 'unknown')->once();
});

it('keeps the other filters when it redirects the old project URL', function () {
    $this->get('/?project=coins&initiative=mobile')->assertRedirect('/p/coins?initiative=mobile');
});

it('redirects the old URL of a disabled project to / rather than to a 404', function () {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get('/?project=acme-site')->assertRedirect('/');

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_filter_redirected' && $c['reason'] === 'disabled')->once();
});

it('gives a stale project a warning-tone dot with a screen-reader label', function () {
    $this->rent->update(['state' => Project::STATE_STALE]);

    $html = $this->get('/')->getContent();

    expect($html)->toMatch('/data-sidebar-project="rent-track".*?<span[^>]*data-state-dot="stale"[^>]*class="[^"]*\bbg-warning\b/s')
        ->and($html)->toMatch('/data-sidebar-project="rent-track".*?<span class="sr-only">stale<\/span>/s');
});

it('keeps /p/coins on coins whatever the query string says, with no project dropdown', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-15', 'status' => 'draft']);

    // SB-10: the page is its own ProjectPage, which has no project filter to move.
    $this->get('/p/coins?project=client-dashboard')->assertSee('AUC-17')->assertDontSee('SS-15')
        ->assertDontSeeHtml('id="f-project"');
});

it('shows an empty sidebar that links to Manage projects to add a project', function () {
    Project::query()->delete();

    // SB-12 registered projects.manage, so the empty state points at the page, not the command.
    $this->get('/')->assertOk()->assertSee('No projects yet')->assertSee('Add a project')
        ->assertSee(route('projects.manage'));
});

it('shows the Manage projects slot now that its page exists (SB-12)', function () {
    $this->get('/')->assertSee('Manage projects');
});

it('renders the story page for coins MOB-65 inside the shell with coins current', function () {
    $fixture = new GitFixture;
    $fixture->story('MOB-65', 'approved', 'mobile')->commitAndPush();
    $this->coins->update(['path' => $fixture->project]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();

    $html = $this->get('/p/coins/s/MOB-65')->assertOk()->assertSee('Story MOB-65')->getContent();

    expect(sidebarEntry($html, 'coins'))->toContain('aria-current="page"')
        ->and($html)->toContain('aria-label="Open projects"')
        ->and($html)->toContain('href="'.route('projects.show', ['project' => 'coins']).'"');

    $fixture->destroy();
});
