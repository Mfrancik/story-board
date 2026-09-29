<?php

use App\Actions\Board\ReadProjectProgress;
use App\Actions\Board\ReadProjectStories;
use App\Livewire\Board\ProjectStories;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * SB-15 acceptance criteria: `/p/{project}/stories` lists a project's on-ref stories grouped by
 * initiative, most open work first (ReadProjectProgress's order), each story tagged with its status.
 * The shapes mirror the dev data checked on 2026-09-29: coins' branding (7 built, 3 draft), its
 * cancelled acquisition stories, rent-track's off-list `in` and `done`, coins at 946 stories in 57
 * initiatives. The client-side half (selection, filters, Expand all) is in tests/Browser.
 */
beforeEach(function () {
    Queue::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => 'abcdef1234567890abcdef1234567890abcdef12'];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->rent = Project::factory()->create(['name' => 'rent-track', ...$fresh]);
});

/**
 * One on-ref story of `$project` in `$initiative`, with an ID and a status.
 */
function storyIn(Project $project, ?string $initiative, string $id, ?string $status, array $extra = []): Story
{
    $dir = $initiative ?? '';

    return Story::factory()->for($project)->create([
        'story_id' => $id, 'title' => "Story {$id}", 'status' => $status, 'initiative' => $initiative,
        'path' => trim("stories/{$dir}/{$id}.md", '/'), ...$extra,
    ]);
}

/**
 * AC 1's fixture: branding 7 built + 3 draft (IDs out of order, BR-10 before BR-2 by insertion),
 * acquisition 2 built, 1 cancelled, 4 approved.
 */
function brandingAndAcquisition(Project $project): void
{
    foreach ([10, 2, 1, 3, 4, 5, 6] as $i) {
        storyIn($project, 'branding', "BR-{$i}", 'built');
    }
    foreach ([8, 7, 9] as $i) {
        storyIn($project, 'branding', "BR-{$i}", 'draft');
    }
    storyIn($project, 'acquisition', 'ACQ-20', 'built');
    storyIn($project, 'acquisition', 'ACQ-21', 'built');
    storyIn($project, 'acquisition', 'ACQ-22', 'cancelled');
    foreach ([25, 26, 27, 28] as $i) {
        storyIn($project, 'acquisition', "ACQ-{$i}", 'approved');
    }
}

/**
 * The page's initiative entries (left pane), in page order.
 *
 * @return list<string>
 */
function initiativeEntries(string $html): array
{
    preg_match_all('/data-initiative-entry="([^"]*)"/', $html, $m);

    return $m[1];
}

/**
 * One initiative's group (right pane), open tag to its closing `</section>`.
 */
function storyGroup(string $html, string $name): string
{
    preg_match('/<section\b[^>]*data-group="'.preg_quote($name, '/').'"[^>]*>.*?<\/section>/s', $html, $m);

    return $m[0] ?? '';
}

/**
 * The story IDs of one group, in page order.
 *
 * @return list<string>
 */
function groupIds(string $group): array
{
    preg_match_all('/data-story-row="([^"]+)"/', $group, $m);

    return $m[1];
}

/**
 * One story row's markup, its `<li>` open tag to close.
 */
function storyRowHtml(string $html, string $id): string
{
    preg_match('/<li\b[^>]*data-story-row="'.preg_quote($id, '/').'".*?<\/li>/s', $html, $m);

    return $m[0] ?? '';
}

it('Given a project with initiatives branding (7 built, 3 draft) and acquisition (2 built, 1 cancelled, 4 approved), when /p/{project}/stories loads, then the left pane lists both, acquisition first (more open work), branding shows its counts "3 open / 10", and acquisition\'s stories fill the right pane', function () {
    brandingAndAcquisition($this->coins);

    $html = $this->get('/p/coins/stories')->assertOk()->getContent();

    expect(initiativeEntries($html))->toBe(['acquisition', 'branding']);
    preg_match('/data-initiative-entry="branding".*?data-initiative-counts[^>]*>(.*?)<\/span>\s*<\/span>/s', $html, $counts);
    expect(trim(preg_replace('/\s+/', ' ', strip_tags($counts[1] ?? ''))))->toBe('3 open / 10');

    // The first initiative is the one shown on arrival; every other group waits hidden for Alpine.
    $acq = storyGroup($html, 'acquisition');
    $branding = storyGroup($html, 'branding');
    expect($acq)->not->toBe('')
        ->and(Str::before($acq, '>'))->not->toContain('display: none')
        ->and(Str::before($branding, '>'))->toContain('display: none')
        ->and(groupIds($acq))->toBe(['ACQ-20', 'ACQ-21', 'ACQ-22', 'ACQ-25', 'ACQ-26', 'ACQ-27', 'ACQ-28']);
});

it('Given branding is selected, then its 10 stories are listed in natural ID order in the right pane, each with its tag', function () {
    brandingAndAcquisition($this->coins);

    $branding = storyGroup($this->get('/p/coins/stories')->assertOk()->getContent(), 'branding');

    expect(groupIds($branding))->toBe(['BR-1', 'BR-2', 'BR-3', 'BR-4', 'BR-5', 'BR-6', 'BR-7', 'BR-8', 'BR-9', 'BR-10'])
        ->and(substr_count($branding, 'data-tag="built"'))->toBe(7)
        ->and(substr_count($branding, 'data-tag="draft"'))->toBe(3);
    expect(storyRowHtml($branding, 'BR-10'))->toMatch('/data-tag="built"[^>]*>\s*Built\s*</')
        ->and(storyRowHtml($branding, 'BR-8'))->toMatch('/data-tag="draft"[^>]*>\s*Draft\s*</');
});

it('Given a cancelled story, then its row is struck through and tagged "Cancelled"', function () {
    brandingAndAcquisition($this->coins);

    $row = storyRowHtml($this->get('/p/coins/stories')->getContent(), 'ACQ-22');

    expect($row)->toContain('data-kind="cancelled"')
        ->and($row)->toMatch('/<span[^>]*line-through[^>]*>ACQ-22<\/span>/')
        ->and($row)->toMatch('/<span[^>]*line-through[^>]*>\s*Story ACQ-22\s*<\/span>/')
        ->and($row)->toMatch('/data-tag="cancelled"[^>]*>\s*Cancelled\s*</');
});

it('Given an approved story, then it is tagged "To do"', function () {
    brandingAndAcquisition($this->coins);

    $row = storyRowHtml($this->get('/p/coins/stories')->getContent(), 'ACQ-25');

    expect($row)->toMatch('/data-tag="approved"[^>]*>\s*To do\s*</')
        ->and($row)->not->toContain('line-through');
});

it('Given stories with the status done and with no status, then they show grey tags reading "done" and "no status", and neither is counted as Built', function () {
    storyIn($this->rent, 'multi-tenancy', 'RT-1', 'built');
    storyIn($this->rent, 'multi-tenancy', 'RT-2', 'done');
    storyIn($this->rent, 'multi-tenancy', 'RT-3', null);

    $html = $this->get('/p/rent-track/stories')->assertOk()->getContent();

    expect(storyRowHtml($html, 'RT-2'))->toMatch('/data-tag="other"[^>]*>\s*done\s*</')
        ->and(storyRowHtml($html, 'RT-3'))->toMatch('/data-tag="other"[^>]*>\s*no status\s*</')
        ->and(storyRowHtml($html, 'RT-2'))->not->toContain('bg-danger');
    // The Built chip's tally and the group's built count see one story, not three.
    expect($html)->toMatch('/data-filter="built".*?data-tally[^>]*>\s*1\s*</s')
        ->and($html)->toMatch('/data-filter="other".*?data-tally[^>]*>\s*2\s*</s');

    $groups = app(ReadProjectStories::class)->handle($this->rent)['initiatives'];
    expect($groups[0]['kinds'])->toBe(['built' => 1, 'approved' => 0, 'draft' => 0, 'cancelled' => 0, 'other' => 2]);
});

it('keeps the status chip\'s danger tone for off-list values everywhere but this page', function () {
    $chip = fn (array $props) => Blade::render('<x-board.status-chip :status="$status" :variant="$variant" />', [...['variant' => null], ...$props]);

    expect($chip(['status' => 'done']))->toContain('bg-danger/10')->toContain('done')
        ->and($chip(['status' => 'done', 'variant' => 'tag']))->not->toContain('danger')
        ->and($chip(['status' => 'cancelled']))->toContain('bg-cancelled/15')
        ->and($chip(['status' => 'cancelled', 'variant' => 'tag']))->toContain('Cancelled')->not->toContain('bg-cancelled/15');
});

it('Given the Built chip is turned off, then every count still shows the full numbers (the counts are rendered once, never filtered)', function () {
    brandingAndAcquisition($this->coins);
    storyIn($this->coins, 'done-work', 'DW-1', 'built');

    $html = $this->get('/p/coins/stories')->assertOk()->getContent();

    // Each filter chip toggles one CSS class on the list; the rows carry the class that hides them.
    expect($html)->toContain('data-filter="built"')->toContain('data-filter="approved"')->toContain('data-filter="draft"')
        ->toContain('data-filter="cancelled"')->toContain('data-filter="other"')
        ->and(storyRowHtml($html, 'BR-1'))->toContain('[.hide-built_&amp;]:hidden');
    // Alpine knows each initiative's counts by kind, so an all-built initiative can drop out of the list.
    expect(collect(app(ReadProjectStories::class)->handle($this->coins)['initiatives'])->firstWhere('name', 'done-work')['kinds'])
        ->toBe(['built' => 1, 'approved' => 0, 'draft' => 0, 'cancelled' => 0, 'other' => 0]);
    preg_match('/data-initiatives-shown[^>]*>(.*?)<\/p>/s', $html, $shown);
    expect(trim(strip_tags($shown[1] ?? '')))->toBe('3 of 3 initiatives');
    // The counts beside the chips and on the left are plain markup: no filter is bound to them.
    preg_match('/data-initiative-entry="branding".*?data-initiative-counts([^>]*)>/s', $html, $counts);
    expect($counts[1] ?? null)->not->toBeNull()->not->toContain('x-');
});

it('Given stories with no initiative, then they appear under an entry named "No initiative"', function () {
    storyIn($this->coins, null, 'LOOSE-1', 'draft');
    storyIn($this->coins, 'branding', 'BR-1', 'built');

    $html = $this->get('/p/coins/stories')->assertOk()->getContent();

    expect(initiativeEntries($html))->toBe(['', 'branding'])
        ->and($html)->toMatch('/data-initiative-entry=""[^>]*>.*?No initiative/s')
        ->and(groupIds(storyGroup($html, '')))->toBe(['LOOSE-1']);
});

it('Given a parked initiative, then its left-pane entry carries the "parked" tag', function () {
    storyIn($this->coins, 'import', 'IMP-1', 'draft', ['is_parked' => true]);
    storyIn($this->coins, 'branding', 'BR-1', 'draft');

    $html = $this->get('/p/coins/stories')->assertOk()->getContent();

    preg_match('/<button\b[^>]*data-initiative-entry="import".*?<\/button>/s', $html, $import);
    preg_match('/<button\b[^>]*data-initiative-entry="branding".*?<\/button>/s', $html, $branding);
    expect($import[0] ?? '')->toContain('data-parked')->toContain('parked')
        ->and($branding[0] ?? '')->not->toContain('data-parked');
});

it('Given an off-main story row for the same project, then it is not listed', function () {
    storyIn($this->coins, 'branding', 'BR-1', 'built');
    storyIn($this->coins, 'branding', 'BR-99', 'draft', ['location_kind' => Story::KIND_BRANCH, 'location' => 'branch feat/x', 'branch' => 'feat/x']);

    $html = $this->get('/p/coins/stories')->assertOk()->getContent();

    expect(groupIds(storyGroup($html, 'branding')))->toBe(['BR-1'])
        ->and($html)->not->toContain('BR-99');
});

it('Given a project with no on-ref stories, then the page shows the empty state and returns 200', function () {
    storyIn($this->coins, 'branding', 'BR-99', 'draft', ['location_kind' => Story::KIND_UNTRACKED, 'location' => 'untracked in /x']);

    $this->get('/p/coins/stories')->assertOk()
        ->assertSee('coins has no stories on origin/main yet.')
        ->assertDontSee('data-initiative-entry', false);
});

it('Given a disabled or unknown project, then /p/{project}/stories returns 404 (the same CheckProjectShown rule as SB-10 and SB-14)', function () {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get('/p/nope/stories')->assertNotFound();
    $this->get('/p/acme-site/stories')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c === ['project' => 'nope', 'reason' => 'unknown'])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c === ['project' => 'acme-site', 'reason' => 'disabled'])->once();
});

it('and hydrate() re-checks it on every request: a project switched off mid-visit sends the owner home, logged, without reading its stories', function (string $how, string $reason) {
    storyIn($this->coins, 'secret-initiative', 'SEC-1', 'draft');
    $page = Livewire::test(ProjectStories::class, ['project' => $this->coins]);
    $how === 'disable' ? $this->coins->update(['is_enabled' => false]) : $this->coins->delete();
    Log::spy();
    $this->mock(ReadProjectStories::class, fn ($mock) => $mock->shouldNotReceive('handle'));

    // The mock is the proof nothing of the project is read or rendered once it is off the board.
    $page->call('$refresh')->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused'
        && $c === ['project' => 'coins', 'reason' => $reason, 'request' => 'update'])->once();
})->with([
    'switched off' => ['disable', 'disabled'],
    'removed' => ['delete', 'unknown'],
]);

it('Given the page loads, then board.stories_viewed is logged with project and stories (count)', function () {
    brandingAndAcquisition($this->coins);
    Log::spy();

    $this->get('/p/coins/stories')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.stories_viewed' && $c === ['project' => 'coins', 'stories' => 17])->once();
});

it('reads every on-ref story in one query at coins\' size (946 stories, 57 initiatives)', function () {
    $pageQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/p/coins/stories')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    storyIn($this->coins, 'init-00', 'IN-1', 'draft');
    $before = $pageQueries();

    // 945 more, spread over 57 initiatives, with every kind of status and a parked initiative.
    $statuses = ['built', 'built', 'built', 'approved', 'draft', 'cancelled', 'done', null];
    $rows = [];
    foreach (range(2, 946) as $i) {
        $initiative = sprintf('init-%02d', $i % 57);
        $rows[] = [
            'project_id' => $this->coins->id, 'story_id' => "IN-{$i}", 'title' => "Story {$i}", 'status' => $statuses[$i % 8],
            'initiative' => $initiative, 'is_parked' => $initiative === 'init-07', 'path' => "stories/{$initiative}/IN-{$i}.md",
            'depends_on' => '[]', 'mockups' => '{"dir":null,"options":[],"chosen":null}', 'parse_errors' => '[]', 'sha' => str_repeat('a', 40),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        Story::insert($chunk);
    }
    expect(Story::onRef()->where('project_id', $this->coins->id)->count())->toBe(946);

    // The page as a whole does not grow with the stories (the sidebar's own counts are part of both runs)…
    expect($pageQueries())->toBe($before);

    // …and the stories themselves are one query.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = app(ReadProjectStories::class)->handle($this->coins);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($log)->toHaveCount(1)
        ->and($data['stories'])->toBe(946)
        ->and($data['initiatives'])->toHaveCount(57);
});

it('orders the initiatives exactly as ReadProjectProgress does, and counts them the same', function () {
    brandingAndAcquisition($this->coins);
    storyIn($this->coins, 'zeta', 'Z-1', 'draft');
    storyIn($this->coins, 'alpha', 'A-1', 'draft');
    storyIn($this->coins, null, 'N-1', 'draft');
    storyIn($this->coins, 'mixed', 'M-1', null);
    storyIn($this->coins, 'mixed', 'M-2', '(none)');

    $progress = app(ReadProjectProgress::class)->handle($this->coins->id)['initiatives'];
    $stories = app(ReadProjectStories::class)->handle($this->coins)['initiatives'];

    expect(array_column($stories, 'name'))->toBe(array_column($progress, 'name'))
        ->and(array_map(fn ($g) => [$g['counts'], $g['total'], $g['open'], $g['parked']], $stories))
        ->toBe(array_map(fn ($g) => [$g['counts'], $g['total'], $g['open'], $g['parked']], $progress));
});

it('opens each well-formed story row in the SB-8 modal and reaches the page through a Stories tab', function () {
    brandingAndAcquisition($this->coins);
    storyIn($this->coins, 'branding', 'odd_mockup_dir', 'draft');

    $html = $this->get('/p/coins/stories')->assertOk()->assertSeeLivewire('board.story-modal')->getContent();

    expect(storyRowHtml($html, 'BR-1'))->toContain('data-story-link="coins/BR-1"')
        ->and(storyRowHtml($html, 'odd_mockup_dir'))->not->toContain('data-story-link');
    $this->get('/p/coins')->assertOk()->assertSee(route('projects.stories', 'coins'), false);
    $this->get('/p/coins/handbook')->assertSee(route('projects.stories', 'coins'), false);
    expect($html)->toMatch('/data-project-tab="stories"[^>]*aria-current="page"/');
});
