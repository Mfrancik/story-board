<?php

use App\Jobs\RefreshProjectJob;
use App\Livewire\Board\Home;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-3 acceptance criteria, against the rendered page.
 */
beforeEach(function () {
    // Fresh snapshots, so mounting the page does not queue refreshes unless a test wants it.
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)]);
    $this->cd = Project::factory()->create(['name' => 'client-dashboard', 'indexed_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('b', 40)]);
});

function mockups(?string $chosen, array $options = ['a', 'b']): array
{
    return ['dir' => 'docs/mockups/X', 'options' => $options, 'chosen' => $chosen];
}

it('lists unparked drafts under Awaiting approval and hides parked ones', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft', 'title' => 'The auction page']);
    Story::factory()->for($this->coins)->create(['story_id' => 'IMP-1', 'status' => 'draft', 'title' => 'The door', 'is_parked' => true]);

    Livewire::test(Home::class)
        ->assertSeeInOrder(['Awaiting approval', 'AUC-17', 'Awaiting a mockup pick'])
        ->assertDontSeeHtml('data-row="IMP-1"')
        ->assertSee('1 draft in parked groups is hidden');
});

it('lists a story awaiting a mockup pick until its choice is on the ref', function () {
    $story = Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'approved', 'mockups' => mockups(null)]);

    Livewire::test(Home::class)->assertSeeHtml('data-group="pick"')->assertSeeHtml('data-pick-row="MOB-65"');

    $story->update(['mockups' => mockups('a')]);
    Livewire::test(Home::class)->assertDontSeeHtml('data-pick-row="MOB-65"');
});

it('lists approved stories across projects as ready to build, oldest first', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'PAY-20', 'status' => 'approved', 'dated_on' => '2026-09-20']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-6', 'status' => 'approved', 'dated_on' => '2026-07-02']);
    Story::factory()->for($this->coins)->create(['story_id' => 'LEGAL-1', 'status' => 'approved', 'dated_on' => null]);

    Livewire::test(Home::class)->assertSeeInOrder(['Ready to build', 'SS-6', 'PAY-20', 'LEGAL-1']);
});

it('keeps the project filter in the URL and shows only that project', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-15', 'status' => 'draft']);

    Livewire::withQueryParams(['project' => 'coins'])->test(Home::class)
        ->assertSet('project', 'coins')
        ->assertSeeHtml('data-row="AUC-17"')
        ->assertDontSeeHtml('data-row="SS-15"');

    // SB-7: the filter URL now redirects to the project page, which keeps the same promise.
    $this->followingRedirects()->get('/?project=coins')->assertOk()->assertSee('AUC-17')->assertDontSee('SS-15');
});

it('finds exactly one story when searching for its ID', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'approved']);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-650', 'status' => 'draft']);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-6', 'status' => 'approved']);

    Livewire::test(Home::class)->set('q', 'MOB-65')
        ->assertSeeHtml('data-row="MOB-65"')
        ->assertDontSeeHtml('data-row="MOB-650"')
        ->assertDontSeeHtml('data-row="MOB-6"');
});

it('offers the story page when a searched ID is in no visible group', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'BUL-17', 'status' => 'cancelled']);

    Livewire::test(Home::class)->set('q', 'BUL-17')
        ->assertSeeHtml('data-goto="BUL-17"')
        ->assertSee('cancelled');
});

it('shows a one-line empty state for every empty group', function () {
    Livewire::test(Home::class)
        ->assertSee('Nothing waiting for approval.')
        ->assertSee('No mockups waiting on a pick.')
        ->assertSee('Nothing approved and unbuilt.');
});

it('shows an unreachable project on its card and still renders the others', function () {
    $this->cd->update(['state' => Project::STATE_UNREACHABLE, 'last_error' => 'not a git repository: /x']);
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);

    Livewire::test(Home::class)
        ->assertSeeHtml('data-project-card="client-dashboard"')
        ->assertSee('Unreachable')
        ->assertSeeHtml('data-row="AUC-17"');
});

it('warns on the project card about parse errors and shows the raw status in the list', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'MT-1', 'status' => 'draft', 'parse_errors' => ["status 'draft' cannot be checked"]]);
    Story::factory()->for($this->coins)->create(['story_id' => 'MT-9', 'status' => 'done', 'parse_errors' => ["status 'done' is not in stories/README.md §Status"]]);

    Livewire::test(Home::class)
        ->assertSee('2 stories have parse errors')
        ->assertSeeHtml('data-row="MT-1"')
        ->assertSee('done');
});

it('keeps Built and Parked drafts closed until opened, then lists them', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-1', 'status' => 'built', 'title' => 'Shipped thing']);
    Story::factory()->for($this->coins)->create(['story_id' => 'IMP-1', 'status' => 'draft', 'is_parked' => true]);

    Livewire::test(Home::class)
        ->assertSeeHtml('data-section="built"')
        ->assertSee('Built')
        ->assertDontSeeHtml('data-row="MOB-1"')
        ->call('toggleSection', 'built')
        ->assertSeeHtml('data-row="MOB-1"')
        ->call('toggleSection', 'parked')
        ->assertSeeHtml('data-row="IMP-1"');
});

it('shows ten rows per group, then the rest on request', function () {
    Story::factory()->for($this->coins)->count(12)->sequence(fn ($s) => ['story_id' => 'AP-'.($s->index + 1), 'status' => 'draft', 'path' => sprintf('stories/demo/AP-%02d.md', $s->index + 1)])->create();

    Livewire::test(Home::class)
        ->assertSee('Show 2 more')
        ->assertDontSeeHtml('data-row="AP-12"')
        ->call('showAll', 'approval')
        ->assertSeeHtml('data-row="AP-12"');
});

it('expands a row in place with the story text from the ref and its mockup options', function () {
    $fixture = new GitFixture;
    $fixture->story('FX-1', 'approved', 'demo', "## Why\nBecause **reasons**.\n<script>alert(1)</script>\n")
        ->write('docs/mockups/FX-1/option-a.html', 'a')->write('docs/mockups/FX-1/option-b.html', 'b')
        ->commitAndPush();
    $project = Project::factory()->create(['name' => 'fx', 'path' => $fixture->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    $story = $project->stories()->onRef()->where('story_id', 'FX-1')->sole();

    Livewire::test(Home::class)
        ->call('expand', $story->id)
        ->assertSeeHtml('<strong>reasons</strong>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('sandbox="allow-scripts"')
        ->assertSeeHtml('src="'.route('mockups.file', ['project' => 'fx', 'storyId' => 'FX-1', 'file' => 'option-b.html']).'"');

    $fixture->destroy();
});

it('refreshes every enabled project on the queue when Refresh is pressed', function () {
    Bus::fake();
    Log::spy();

    Livewire::test(Home::class)->call('refresh');

    Bus::assertDispatched(RefreshProjectJob::class, 2);
    Log::shouldHaveReceived('info')->withArgs(fn ($event) => $event === 'board.refresh_requested')->once();
});

it('logs each view with its filters', function () {
    Log::spy();

    Livewire::withQueryParams(['project' => 'coins', 'q' => 'MOB'])->test(Home::class);

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.home_viewed'
        && $ctx['project'] === 'coins' && $ctx['q'] === 'MOB')->once();
});

it('queues a refresh on page load for a stale project only', function () {
    Bus::fake();
    $this->coins->update(['indexed_at' => now()->subMinutes(10), 'refresh_attempted_at' => null]);

    $this->get('/')->assertOk();

    Bus::assertDispatched(RefreshProjectJob::class, 1);
    Bus::assertDispatched(RefreshProjectJob::class, fn ($job) => $job->project->is($this->coins));
});

it('tags every response and the refresh job with a request id', function () {
    Bus::fake();
    $this->coins->update(['indexed_at' => null]);

    $response = $this->get('/', ['X-Request-Id' => 'req-123']);

    $response->assertHeader('X-Request-Id', 'req-123');
    Bus::assertDispatched(RefreshProjectJob::class, fn ($job) => $job->requestId === 'req-123');
    expect($this->get('/')->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('queues one refresh per project however many times the page loads', function () {
    Queue::fake();
    $this->coins->update(['indexed_at' => null]);

    $this->get('/');
    $this->get('/');
    Livewire::test(Home::class)->call('refresh');

    Queue::assertPushed(RefreshProjectJob::class, fn ($job) => $job->project->is($this->coins));
    expect(collect(Queue::pushedJobs()[RefreshProjectJob::class] ?? [])->filter(fn ($p) => $p['job']->project->is($this->coins)))->toHaveCount(1);
});

it('refuses browser edits to the expanded bodies and open sections', function () {
    Livewire::test(Home::class)->set('bodies', [1 => '<script>alert(1)</script>']);
})->throws(CannotUpdateLockedPropertyException::class);

it('confirms a refresh in the button\'s own verb', function () {
    Bus::fake();

    Livewire::test(Home::class)->call('refresh')->assertSee('Refresh queued for 2 projects');
});

it('does not expand a story from a disabled project', function () {
    $off = Project::factory()->disabled()->create();
    $story = Story::factory()->for($off)->create();

    Livewire::test(Home::class)->call('expand', $story->id)->assertSet("bodies.{$story->id}", '');
});

it('labels each project card with its own ref', function () {
    $this->cd->update(['ref' => 'origin/develop']);

    Livewire::test(Home::class)->assertSee('origin/develop @ bbbbbbbb');
});
