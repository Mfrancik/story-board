<?php

use App\Actions\Board\ListWhatNeedsMe;
use App\Actions\Board\ReadProjectProgress;
use App\Jobs\RefreshProjectJob;
use App\Livewire\Board\ProjectPage;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-10 acceptance criteria: `/p/{project}` as that project's dashboard — header with a
 * one-project refresh, What needs me scoped, Progress by initiative and Not on main. The
 * fixtures mirror the dev data checked on 2026-09-29: coins 57 initiatives with the parked
 * `import` group and off-main branch 154 / untracked 93 / worktree 15; asset-track nothing off
 * main; rent-track one initiative of draft 11, `in` 1 and `done` 3.
 */
beforeEach(function () {
    // Never a real refresh: every job the page queues is caught here.
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => 'abcdef1234567890abcdef1234567890abcdef12'];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->cd = Project::factory()->create(['name' => 'client-dashboard', ...$fresh]);
    $this->asset = Project::factory()->create(['name' => 'asset-track', ...$fresh]);
    $this->rent = Project::factory()->create(['name' => 'rent-track', ...$fresh]);
});

/**
 * Give a project `$n` initiatives `init-01`…, each with one built story, so none has open work.
 */
function seedInitiatives(Project $project, int $n, int $from = 1): void
{
    foreach (range($from, $from + $n - 1) as $i) {
        $name = sprintf('init-%02d', $i);
        Story::factory()->for($project)->create(['story_id' => 'IN-'.$i, 'status' => 'built', 'initiative' => $name, 'path' => "stories/{$name}/IN-{$i}.md"]);
    }
}

/**
 * Add open work (drafts and approved) to one initiative.
 */
function openWork(Project $project, string $initiative, int $drafts, int $approved = 0): void
{
    Story::factory()->for($project)->count($drafts)->create(['status' => 'draft', 'initiative' => $initiative]);
    Story::factory()->for($project)->count($approved)->create(['status' => 'approved', 'initiative' => $initiative]);
}

/**
 * Add `$n` off-main rows of one kind to a project.
 */
function addOffMain(Project $project, string $kind, int $n, string $branch = 'feat/x'): void
{
    Story::factory()->for($project)->count($n)->create([
        'location_kind' => $kind,
        'location' => $kind === Story::KIND_UNTRACKED ? 'untracked in /code/coins' : "{$kind} {$branch}",
        'branch' => $kind === Story::KIND_UNTRACKED ? null : $branch,
    ]);
}

/**
 * The initiative rows of a page, in page order, with whether each is behind "Show all".
 *
 * @return list<array{name: string, hidden: bool, tag: string}>
 */
function initiativeRows(string $html): array
{
    preg_match_all('/<li\b[^>]*data-initiative="([^"]*)"[^>]*>/', $html, $m, PREG_SET_ORDER);

    return array_map(fn ($row) => ['name' => $row[1], 'hidden' => str_contains($row[0], 'data-beyond'), 'tag' => $row[0]], $m);
}

/**
 * One initiative row's markup, open tag to close.
 */
function initiativeRow(string $html, string $name): string
{
    preg_match('/<li\b[^>]*data-initiative="'.preg_quote($name, '/').'".*?<\/li>/s', $html, $m);

    return $m[0] ?? '';
}

/**
 * The Not on main panel's markup.
 */
function offMainPanel(string $html): string
{
    preg_match('/<section\b[^>]*data-offmain-panel.*?<\/section>/s', $html, $m);

    return $m[0] ?? '';
}

it('shows 8 initiative rows ordered by open work on /p/coins with 57 initiatives, and "Show all 57" reveals the rest', function () {
    seedInitiatives($this->coins, 57);
    // Open work (draft + approved) picks the order; built work does not count.
    openWork($this->coins, 'init-50', 4, 3);
    openWork($this->coins, 'init-03', 6);
    openWork($this->coins, 'init-40', 0, 5);
    openWork($this->coins, 'init-12', 2, 2);
    openWork($this->coins, 'init-33', 3);
    openWork($this->coins, 'init-21', 1, 1);
    openWork($this->coins, 'init-09', 2);
    openWork($this->coins, 'init-02', 0, 1);
    Story::factory()->for($this->coins)->count(10)->create(['status' => 'built', 'initiative' => 'init-57']);

    $rows = initiativeRows($this->get('/p/coins')->assertOk()->assertSee('Show all 57')->getContent());
    $shown = array_values(array_filter($rows, fn ($r) => ! $r['hidden']));

    expect($rows)->toHaveCount(57)
        ->and(array_column($shown, 'name'))->toBe(['init-50', 'init-03', 'init-40', 'init-12', 'init-33', 'init-09', 'init-21', 'init-02'])
        // The rest are rendered, hidden until "Show all 57" (pure UI, so Alpine, no round trip).
        ->and($rows[8]['tag'])->toContain('x-show="all"')->toContain('x-cloak');
});

it('labels the initiative whose README says Status: draft group as parked', function () {
    $fixture = new GitFixture;
    $fixture->story('IMP-1', 'draft', 'import')
        ->write('stories/import/README.md', "# Import\n\nStatus: draft group — parked 2026-09-02\n")
        ->story('APP-1', 'draft', 'app-store-launch')
        ->write('stories/app-store-launch/README.md', "# App store\n\nStatus: release group — opened 2026-09-22\n")
        ->commitAndPush();
    $this->coins->update(['path' => $fixture->project]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();

    $html = $this->get('/p/coins')->assertOk()->getContent();

    expect(initiativeRow($html, 'import'))->toContain('data-parked')->toContain('parked')
        ->and(initiativeRow($html, 'app-store-launch'))->not->toContain('data-parked');

    $fixture->destroy();
});

it('shows coins off-main counts branch 154, untracked 93 and worktree 15, and expanding branch lists branch rows with their branch names', function () {
    addOffMain($this->coins, Story::KIND_BRANCH, 150, 'design/ACQ-20-mockups');
    addOffMain($this->coins, Story::KIND_BRANCH, 4, 'design/mob-collector');
    addOffMain($this->coins, Story::KIND_UNTRACKED, 93);
    addOffMain($this->coins, Story::KIND_WORKTREE, 15, 'feat/SET-42');
    // Another project's off-main rows are not coins'.
    addOffMain($this->cd, Story::KIND_BRANCH, 17, 'feat/CT-1');

    $component = Livewire::test(ProjectPage::class, ['project' => $this->coins]);
    $panel = offMainPanel($component->html());

    expect($panel)->toMatch('/data-offmain-kind="branch"[^>]*data-count="154"/')
        ->and($panel)->toMatch('/data-offmain-kind="untracked"[^>]*data-count="93"/')
        ->and($panel)->toMatch('/data-offmain-kind="worktree"[^>]*data-count="15"/')
        ->and($panel)->toContain('262')
        ->and($component->html())->not->toContain('data-offmain-rows=');

    $html = $component->call('toggleKind', 'branch')->html();
    preg_match('/<section\b[^>]*data-offmain-rows="branch".*?<\/section>/s', $html, $open);

    expect(substr_count($open[0] ?? '', 'data-offmain-row='))->toBe(154)
        ->and($open[0])->toContain('design/ACQ-20-mockups')->toContain('design/mob-collector')
        ->and($open[0])->not->toContain('untracked in')->not->toContain('feat/CT-1')
        ->and($html)->not->toContain('data-offmain-rows="untracked"');

    // A second click closes it again.
    expect($component->call('toggleKind', 'branch')->html())->not->toContain('data-offmain-rows=');
});

it('reads "Nothing off main" for asset-track, which has no off-main rows', function () {
    Story::factory()->for($this->asset)->count(3)->create(['status' => 'built']);
    addOffMain($this->coins, Story::KIND_BRANCH, 2);

    $panel = offMainPanel($this->get('/p/asset-track')->assertOk()->getContent());

    expect($panel)->toContain('Nothing off main')
        ->and($panel)->not->toContain('data-offmain-kind=');
});

it('queues exactly one RefreshProjectJob for coins when Refresh this project is clicked, shows "Refresh queued for coins" and logs board.refresh_requested with project', function () {
    Log::spy();

    Livewire::test(ProjectPage::class, ['project' => $this->coins])
        ->assertSee('Refresh this project')
        ->call('refresh')
        ->assertSee('Refresh queued for coins');

    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn (RefreshProjectJob $job) => $job->project->is($this->coins));
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.refresh_requested' && ($ctx['project'] ?? null) === 'coins')->once();
});

it('tones rent-track\'s in and done segments as danger on its initiative row', function () {
    $oov = ['status is not in stories/README.md §Status'];
    Story::factory()->for($this->rent)->count(11)->create(['status' => 'draft', 'initiative' => 'multi-tenancy', 'parse_errors' => $oov]);
    Story::factory()->for($this->rent)->create(['status' => 'in', 'initiative' => 'multi-tenancy', 'parse_errors' => $oov]);
    Story::factory()->for($this->rent)->count(3)->create(['status' => 'done', 'initiative' => 'multi-tenancy', 'parse_errors' => $oov]);

    $row = initiativeRow($this->get('/p/rent-track')->assertOk()->getContent(), 'multi-tenancy');

    expect($row)->toMatch('/data-segment="in"[^>]*data-tone="danger"/')
        ->and($row)->toMatch('/data-segment="done"[^>]*data-tone="danger"/')
        ->and($row)->toMatch('/data-segment="draft"[^>]*data-tone="draft"/');
});

it('runs the same number of queries whatever the number of initiatives', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/p/coins')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    seedInitiatives($this->coins, 3);
    // Non-empty cards from the start: an empty card skips its eager load, which is not growth.
    openWork($this->coins, 'init-01', 1, 1);
    addOffMain($this->coins, Story::KIND_BRANCH, 1);
    $before = $count();

    seedInitiatives($this->coins, 40, from: 10);
    openWork($this->coins, 'init-20', 2, 1);
    Story::factory()->for($this->coins)->create(['status' => 'in', 'initiative' => 'init-30', 'is_parked' => true]);

    expect($count())->toBe($before);
});

it('heads the page with the project name, state, ref and short SHA and how long ago it was refreshed', function () {
    $this->coins->update(['indexed_at' => now()->subMinutes(7)]);
    seedInitiatives($this->coins, 2);

    $html = $this->get('/p/coins')->assertOk()
        ->assertSeeInOrder(['All projects', 'coins', 'origin/main @ abcdef12', '2 stories', '2 initiatives', 'Refreshed 7 minutes ago', 'Refresh this project'])
        ->getContent();

    preg_match('/<main\b.*?<\/main>/s', $html, $main);
    preg_match('/<h1\b[^>]*>(.*?)<\/h1>/s', $main[0] ?? '', $h1);

    expect(trim(strip_tags($h1[1] ?? '')))->toStartWith('coins')
        ->and($h1[1] ?? '')->toContain('data-state-dot="ok"')->toContain('bg-ok');
});

it('says the snapshot is stale in the header when the project state is not ok', function () {
    $this->coins->update(['state' => Project::STATE_STALE, 'last_error' => "fetch failed\nsecond line"]);

    $this->get('/p/coins')->assertOk()->assertSeeHtml('data-state="stale"')->assertSee('Stale')->assertSee('fetch failed')->assertDontSee('second line');
});

it('scopes the What needs me cards to the project and opens every story row in the SB-8 modal', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'approved']);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'draft',
        'mockups' => ['dir' => 'docs/mockups/MOB-65', 'options' => ['a', 'b'], 'chosen' => null]]);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-12', 'status' => 'approved']);
    addOffMain($this->coins, Story::KIND_WORKTREE, 1);

    $component = Livewire::test(ProjectPage::class, ['project' => $this->coins])->call('toggleKind', 'worktree');
    $html = $component->html();

    expect($html)->toMatch('/data-card="build".*?data-count="1"/s')
        ->and($html)->toContain('data-story-link="coins/AUC-17"')
        ->and($html)->toContain('data-story-link="coins/MOB-65"')
        ->and($html)->not->toContain('SS-12')
        ->and($html)->toContain('wire:id')
        ->and($html)->toContain('story-modal');

    // Every story row on the page — card rows and off-main rows — is a button that asks the modal for it.
    preg_match_all('/<button\b[^>]*data-row="[^"]+"[^>]*>/', $html, $buttons);
    expect($buttons[0])->not->toBeEmpty();
    foreach ($buttons[0] as $button) {
        expect($button)->toContain('data-story-link="coins/')->toContain("\$dispatch('board-story'");
    }
});

it('shows every card row once Show all is pressed on a card', function () {
    Story::factory()->for($this->coins)->count(7)->sequence(fn ($s) => ['story_id' => 'AP-'.($s->index + 1), 'path' => sprintf('stories/demo/AP-%02d.md', $s->index + 1)])->create(['status' => 'approved']);

    Livewire::test(ProjectPage::class, ['project' => $this->coins])
        ->assertSee('Show all 7')
        ->assertDontSeeHtml('data-row="AP-6"')
        ->call('showAll', 'build')
        ->assertSeeHtml('data-row="AP-7"');
});

it('shows stories with no initiative as their own row', function () {
    Story::factory()->for($this->cd)->count(2)->create(['status' => 'draft', 'initiative' => null]);
    Story::factory()->for($this->cd)->create(['status' => 'built', 'initiative' => 'reports']);

    $html = $this->get('/p/client-dashboard')->assertOk()->getContent();

    expect(array_column(initiativeRows($html), 'name'))->toBe(['', 'reports'])
        ->and(initiativeRow($html, ''))->toContain('No initiative')
        ->and($html)->toContain('2 initiatives');
});

it('gives a project with no stories designed empty states instead of blank panels', function () {
    $html = $this->get('/p/asset-track')->assertOk()->getContent();

    expect($html)->toContain('No stories on origin/main yet')
        ->and(offMainPanel($html))->toContain('Nothing off main')
        ->and(initiativeRows($html))->toBe([]);
});

it('renders the Live now panel (SB-11) between What needs me and Progress by initiative', function () {
    $this->get('/p/coins')->assertOk()->assertSeeInOrder(['Awaiting a pick', 'Live now', 'Progress by initiative']);
});

it('keeps /p/coins on coins whatever the query string says', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-15', 'status' => 'draft']);

    $this->get('/p/coins?project=client-dashboard')->assertSee('AUC-17')->assertDontSee('SS-15');
});

it('queues a refresh of this project only when its snapshot is stale', function () {
    $this->coins->update(['refresh_attempted_at' => now()->subHour(), 'indexed_at' => now()->subHour()]);
    $this->cd->update(['refresh_attempted_at' => now()->subHour(), 'indexed_at' => now()->subHour()]);

    $this->get('/p/coins')->assertOk();

    Queue::assertPushed(RefreshProjectJob::class, 1);
    Queue::assertPushed(RefreshProjectJob::class, fn (RefreshProjectJob $job) => $job->project->is($this->coins));
});

// L-5 guards: each refusal has a test and a log line.

it('refuses to refresh a project taken off the board since the page loaded, logs why and goes home', function () {
    Log::spy();
    $component = Livewire::test(ProjectPage::class, ['project' => $this->coins]);
    $this->coins->update(['is_enabled' => false]);

    $component->call('refresh')->assertRedirect(route('home'));

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $ctx = []) => $event === 'board.refresh_refused'
        && $ctx['project'] === 'coins' && $ctx['reason'] === 'disabled')->once();
});

it('ignores and logs a Show all or expand request for something the page does not have', function (string $method, string $value) {
    Log::spy();

    $component = Livewire::test(ProjectPage::class, ['project' => $this->coins])->call($method, $value);

    expect($component->get('expandedGroups'))->toBe([])
        ->and($component->get('openKinds'))->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $ctx = []) => $event === 'board.project_action_refused'
        && $ctx['project'] === 'coins' && $ctx['action'] === $method && $ctx['value'] === $value)->once();
})->with([
    'showAll' => ['showAll', 'built'],
    'toggleKind' => ['toggleKind', 'mainline'],
]);

it('sends the owner home and logs why when the project is switched off mid-visit, without rendering its rollup', function () {
    Log::spy();
    openWork($this->coins, 'secret-rollup', 2);
    $component = Livewire::test(ProjectPage::class, ['project' => $this->coins]);
    $this->coins->update(['is_enabled' => false]);
    // The rollup of a project off the board must not even be read on the next request.
    $this->mock(ReadProjectProgress::class, fn ($mock) => $mock->shouldNotReceive('handle'));

    $component->call('toggleKind', Story::KIND_BRANCH)->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.project_page_refused'
        && $ctx['project'] === 'coins' && $ctx['reason'] === 'disabled' && $ctx['request'] === 'update')->once();
});

it('sends the owner home and logs why when the project is removed mid-visit, instead of an unlogged 404', function () {
    Log::spy();
    $component = Livewire::test(ProjectPage::class, ['project' => $this->coins]);
    $this->coins->delete();

    $component->call('showAll', 'build')->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.project_page_refused'
        && $ctx['project'] === 'coins' && $ctx['reason'] === 'unknown' && $ctx['request'] === 'update')->once();
});

it('sums a null status and a literal "(none)" status instead of dropping one', function () {
    Story::factory()->for($this->rent)->create(['status' => null, 'initiative' => 'mixed']);
    Story::factory()->for($this->rent)->create(['status' => '(none)', 'initiative' => 'mixed']);

    $progress = app(ReadProjectProgress::class)->handle($this->rent->id);
    $row = collect($progress['initiatives'])->firstWhere('name', 'mixed');
    $tile = app(ListWhatNeedsMe::class)->handle('rent-track')['projects'][0];

    expect(array_sum($row['counts']))->toBe(2)
        ->and($tile['counts']['(none)'] ?? 0)->toBe(2);
});
