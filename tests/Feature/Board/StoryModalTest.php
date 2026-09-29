<?php

use App\Livewire\Board\StoryModal;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-8 acceptance criteria: the story modal, addressed by `?story=<project>/<ID>`.
 * The fixtures reproduce the real shapes the story names (checked 2026-09-29):
 * coins ACQ-20 (built, two dependencies, mockups a/b/c, its bold `**A — …**` pick read
 * as a), client-dashboard CT-1 (only on a branch checked out in a worktree), and
 * coins MOB-65's bold `**B** — …` pick that the kit parser stores as no pick (F-1).
 */
beforeEach(function () {
    $this->coinsRepo = new GitFixture;
    $this->coinsRepo
        ->write('stories/acquisition/ACQ-20-see-what-missed-your-rules.md', implode("\n", [
            '# ACQ-20 — Open "didn\'t meet your rules" and see them',
            'Status: built          Journey: shop-my-want-list (completes steps 3 and 6)',
            'Source: braindump',
            '',
            '## Story',
            'As the owner, I want to open a lot that **missed my rules**.',
            '<script>alert(1)</script>',
            '',
            '## Design mockup gate',
            '- Mockups: docs/mockups/ACQ-20/option-{a,b,c}.html',
            '- Chosen option: **A — the chip takes the verdict\'s seat.**',
            '',
            '## Links',
            'Journey: shop-my-want-list · Depends on: ACQ-19 (all data), ACQ-17 (typed reasons)',
            '',
        ]))
        ->write('docs/mockups/ACQ-20/option-a.html', '<p>A</p>')
        ->write('docs/mockups/ACQ-20/option-b.html', '<p>B</p>')
        ->write('docs/mockups/ACQ-20/option-c.html', '<p>C</p>')
        ->story('ACQ-19', 'built', 'acquisition', "## Why\nThe engine keeps its near misses.\n")
        ->story('ACQ-17', 'built', 'acquisition')
        ->story('MOB-65', 'approved', 'mobile', "## Design mockup gate\n- Mockups: docs/mockups/MOB-65/option-{a,b}.html\n- Chosen option: **B** — the product row opens in place (2026-09-29).\n")
        ->write('docs/mockups/MOB-65/option-a.html', '<p>A</p>')
        ->write('docs/mockups/MOB-65/option-b.html', '<p>B</p>')
        ->commitAndPush();
    $this->coins = Project::factory()->create(['name' => 'coins', 'path' => $this->coinsRepo->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();
});

afterEach(function () {
    $this->coinsRepo->destroy();
    isset($this->cdRepo) && $this->cdRepo->destroy();
});

function refusedWith(string $reason): void
{
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.story_modal_refused' && $ctx['reason'] === $reason)->once();
}

it('opens coins ACQ-20 from ?story with its title, text, dependency chips and three thumbnails with A chosen', function () {
    $acq20 = Story::onRef()->where('story_id', 'ACQ-20')->sole();
    // The real shapes the story names, as the kit parser stored them.
    expect($acq20->status)->toBe('built')
        ->and($acq20->depends_on)->toBe(['ACQ-19', 'ACQ-17'])
        ->and($acq20->mockups)->toEqual(['dir' => 'docs/mockups/ACQ-20', 'options' => ['a', 'b', 'c'], 'chosen' => 'a']);

    $html = $this->get('/?story=coins/ACQ-20')->assertOk()
        ->assertSeeHtml('role="dialog"')
        ->assertSee('Open "didn\'t meet your rules" and see them')
        ->assertSeeHtml('<strong>missed my rules</strong>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('data-dep="ACQ-19"')
        ->assertSeeHtml('data-dep="ACQ-17"')
        ->assertSeeHtml('aria-label="Close"')
        ->assertSeeHtml('href="'.route('stories.show', ['project' => 'coins', 'storyId' => 'ACQ-20']).'"')
        ->getContent();

    expect(substr_count($html, 'data-thumb='))->toBe(3)
        ->and($html)->toMatch('/data-thumb="a"\s+data-chosen/')
        ->and($html)->not->toMatch('/data-thumb="[bc]"\s+data-chosen/')
        ->and($html)->toContain('src="'.route('mockups.file', ['project' => 'coins', 'storyId' => 'ACQ-20', 'file' => 'option-a.html']).'"');
});

it('opens a same-project dependency in the modal and moves the URL to it', function () {
    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)
        ->assertSee('Open "didn\'t meet your rules" and see them')
        ->call('open', 'coins/ACQ-19')
        ->assertSet('story', 'coins/ACQ-19')
        ->assertSee('Story ACQ-19')
        ->assertSee('The engine keeps its near misses.')
        ->assertDontSee('Open "didn\'t meet your rules" and see them');
});

it('shows a dependency that is not in the project as plain text', function () {
    Story::onRef()->where('story_id', 'ACQ-17')->delete();

    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)
        ->assertSeeHtml('data-dep="ACQ-19"')
        ->assertDontSeeHtml('data-dep="ACQ-17"')
        ->assertSeeHtml('data-dep-missing="ACQ-17"');
});

it('opens client-dashboard CT-1, which exists only in a worktree, with a not-on-main banner', function () {
    $this->cdRepo = new GitFixture;
    $worktree = $this->cdRepo->addWorktree('client-dashboard-wt', 'story/CT-1');
    mkdir($worktree.'/stories/call-types', 0777, true);
    file_put_contents($worktree.'/stories/call-types/CT-1-split-keyzero-call-data-by-source.md',
        "# CT-1 — Split Key-0 call data by source (IPR / KSLT / KSLP)\nStatus: draft          Journey: none\nSource: fixture\n\n## Story\nSplit it by source.\n");
    $this->cdRepo->git($worktree, 'add', '-A');
    $this->cdRepo->git($worktree, 'commit', '--quiet', '-m', 'docs(CT-1): story');
    Project::factory()->create(['name' => 'client-dashboard', 'path' => $this->cdRepo->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'client-dashboard'])->assertSuccessful();

    $row = Story::where('story_id', 'CT-1')->sole();
    expect($row->location_kind)->toBe(Story::KIND_WORKTREE);

    Livewire::withQueryParams(['story' => 'client-dashboard/CT-1'])->test(StoryModal::class)
        ->assertSet('rowId', $row->id)
        ->assertSee('Split Key-0 call data by source')
        ->assertSee('Split it by source.')
        ->assertSeeHtml('data-offmain-shown')
        ->assertSee('Not on main — worktree '.realpath($worktree));
});

it('refuses a well-formed story that is not on the board, says so and logs unknown', function () {
    Log::spy();

    Livewire::withQueryParams(['story' => 'coins/NOPE-1'])->test(StoryModal::class)
        ->assertSet('rowId', null)
        ->assertDontSeeHtml('data-story-open')
        ->assertSee('No story coins/NOPE-1 on the board');

    refusedWith('unknown');
});

it('refuses a story in a project that is not registered and logs unknown', function () {
    Log::spy();

    Livewire::withQueryParams(['story' => 'nope/AB-1'])->test(StoryModal::class)
        ->assertSet('rowId', null)
        ->assertSee('No story nope/AB-1 on the board');

    refusedWith('unknown');
});

it('refuses a malformed ?story without calling git and logs malformed', function (string $value) {
    Log::spy();
    $this->mock(GitReader::class)->shouldNotReceive('show', 'run');

    Livewire::withQueryParams(['story' => $value])->test(StoryModal::class)
        ->assertSet('rowId', null)
        ->assertDontSeeHtml('data-story-open')
        ->assertSee('is not a story link');

    refusedWith('malformed');
})->with(['coins/../x', 'coins/acq-20', '../coins/ACQ-20', 'coins', 'coins/ACQ-20/x', '/ACQ-20', 'co ins/ACQ-20', '.env/AB-1']);

it('refuses a story in a disabled project and logs disabled', function () {
    Log::spy();
    $off = Project::factory()->disabled()->create(['name' => 'acme-site']);
    Story::factory()->for($off)->create(['story_id' => 'AS-1']);

    Livewire::withQueryParams(['story' => 'acme-site/AS-1'])->test(StoryModal::class)
        ->assertSet('rowId', null)
        ->assertSee('No story acme-site/AS-1 on the board');

    refusedWith('disabled');
});

it('refuses a malformed value opened from the page, not only from the URL', function () {
    Log::spy();

    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)
        ->call('open', 'coins/../../etc')
        ->assertSet('rowId', null);

    refusedWith('malformed');
});

it('quotes an unparsed choice in the rail and marks no thumbnail chosen', function () {
    $mob65 = Story::onRef()->where('story_id', 'MOB-65')->sole();
    // F-1: the kit parser cannot read a bold letter followed by text.
    expect($mob65->mockups['chosen'])->toBeNull()->and($mob65->mockups['options'])->toBe(['a', 'b']);

    $html = Livewire::withQueryParams(['story' => 'coins/MOB-65'])->test(StoryModal::class)
        ->assertSeeHtml('data-chosen-text')
        ->assertSee('B — the product row opens in place (2026-09-29).')
        ->html();

    expect(substr_count($html, 'data-thumb='))->toBe(2)
        ->and($html)->not->toMatch('/data-thumb="\w"\s+data-chosen/');
});

it('lists the story\'s versions not on main with where they live', function () {
    $this->coinsRepo->write('stories/acquisition/ACQ-20-see-what-missed-your-rules.md', "# ACQ-20 — Open\nStatus: draft\n")
        ->pushBranch('design/ACQ-20-mockups');
    $this->artisan('board:refresh', ['project' => 'coins'])->assertSuccessful();

    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)
        ->assertSeeHtml('data-versions')
        ->assertSee('Draft on branch origin/design/ACQ-20-mockups — not on main');
});

it('logs the opened story and which version it showed', function () {
    Log::spy();

    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class);

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.story_modal_opened'
        && $ctx === ['project' => 'coins', 'story' => 'ACQ-20', 'version' => 'ref'])->once();
});

it('closes, clearing the story from the URL', function () {
    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)
        ->call('close')
        ->assertSet('story', '')
        ->assertSet('rowId', null)
        ->assertDontSee('Open "didn\'t meet your rules" and see them');
});

it('closes when the browser sets the story back to empty (the Back button)', function () {
    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)
        ->set('story', '')
        ->assertSet('rowId', null)
        ->set('story', 'coins/ACQ-19')
        ->assertSee('Story ACQ-19');
});

it('refuses browser edits to the rendered body and the shown row', function (string $property) {
    Livewire::withQueryParams(['story' => 'coins/ACQ-20'])->test(StoryModal::class)->set($property, '<script>alert(1)</script>');
})->with(['body', 'rowId'])->throws(CannotUpdateLockedPropertyException::class);

it('opens the modal from ?story on the project page too', function () {
    $this->get('/p/coins?story=coins/ACQ-20')->assertOk()
        ->assertSee('Open "didn\'t meet your rules" and see them')
        ->assertSeeHtml('data-dep="ACQ-19"');
});

it('renders every openable row as a button that opens the modal, with no expand in place', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toMatch('/<button[^>]*data-row="MOB-65"/')
        ->and($html)->toContain('data-story-link="coins/MOB-65"')
        ->and($html)->not->toContain('$wire.expand')
        ->and($html)->not->toContain("x-text=\"open ? '▾' : '▸'\"");
});
