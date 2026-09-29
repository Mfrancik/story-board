<?php

use App\Livewire\Board\StoryPage;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-4 acceptance criteria: the story page (layout B, story above mockups) and
 * the full-screen compare overlay the owner asked for at the gate.
 */
beforeEach(function () {
    $this->fixture = new GitFixture;
    $this->fixture
        ->story('FX-65', 'approved', 'mobile', "## Why\nThe bullion room.\n## Design mockup gate\n- Chosen option: b\n- Why I chose it: clearer at a glance\n## Links\nJourney: none · Depends on: FX-56\n")
        ->write('docs/mockups/FX-65/option-a.html', '<p>A</p>')
        ->write('docs/mockups/FX-65/option-b.html', '<p>B</p><img src="shots/01.png">')
        ->write('docs/mockups/FX-65/option-c.html', '<p>C</p>')
        ->write('docs/mockups/FX-65/shots/01.png', "\x89PNG\r\n\x1a\nfake")
        ->story('FX-56', 'built')
        ->story('FX-70', 'draft', 'demo', "## Design mockup gate\nn/a — non-visual\n")
        ->story('FX-71', 'draft')
        ->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
});

afterEach(function () {
    $this->fixture->destroy();
});

it('renders the title, status chip and Story text from the ref', function () {
    $this->get('/p/fx/s/FX-65')
        ->assertOk()
        ->assertSee('Story FX-65')
        ->assertSeeHtml('data-status-chip="approved"')
        ->assertSee('As a tester.');
});

it('links depends-on to those stories', function () {
    $this->get('/p/fx/s/FX-65')->assertSeeHtml('href="'.route('stories.show', ['project' => 'fx', 'storyId' => 'FX-56']).'"');
});

it('renders one sandboxed frame per option and marks the chosen one with its reason', function () {
    $response = $this->get('/p/fx/s/FX-65')->assertOk();

    expect(substr_count($response->getContent(), 'data-mockup-frame='))->toBe(3)
        ->and(substr_count($response->getContent(), 'sandbox="allow-scripts"'))->toBeGreaterThanOrEqual(3);
    expect($response->getContent())->toMatch('/data-mockup-frame="b"\s+data-chosen/')
        ->not->toMatch('/data-mockup-frame="a"\s+data-chosen/');
    $response->assertSee('clearer at a glance');
});

it('never gives a mockup frame same-origin access', function () {
    expect($this->get('/p/fx/s/FX-65')->getContent())->not->toContain('allow-same-origin');
});

it('reads "No mockups" for a story without a mockup directory', function () {
    $this->get('/p/fx/s/FX-71')->assertOk()->assertSee('No mockups');
});

it('shows no mockup panel for a non-visual story', function () {
    $this->get('/p/fx/s/FX-70')->assertOk()->assertDontSee('No mockups')->assertDontSeeHtml('data-mockups');
});

it('returns 404 for an unknown story or project', function (string $url) {
    $this->get($url)->assertNotFound();
})->with(['/p/fx/s/NOPE-1', '/p/nope/s/FX-65', '/p/fx/s/..%2F..%2F.env']);

it('offers the /build command only for an approved story', function () {
    $this->get('/p/fx/s/FX-65')->assertSee('/build FX-65');
    $this->get('/p/fx/s/FX-71')->assertDontSee('/build FX-71');
});

it('opens the compare overlay on two options, the chosen one first', function () {
    Livewire::test(StoryPage::class, ['project' => $this->project, 'storyId' => 'FX-65'])
        ->assertSeeHtml('data-compare')
        ->assertSeeHtml("left: 'b'")
        ->assertSeeHtml("right: 'a'");
});

it('logs each story view', function () {
    Log::spy();

    $this->get('/p/fx/s/FX-65')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.story_viewed' && $ctx['story'] === 'FX-65')->once();
});

it('quotes a written choice the parser could not read, without marking a frame', function () {
    $this->fixture->story('FX-80', 'built', 'demo', "## Design mockup gate\n- Chosen option: **D** (revised hybrid) — the head over the sections\n")
        ->write('docs/mockups/FX-80/option-c.html', 'c')->write('docs/mockups/FX-80/option-d.html', 'd')
        ->commitAndPush();
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();

    $html = $this->get('/p/fx/s/FX-80')->assertOk()->assertSeeHtml('data-chosen-text')->assertSee('D (revised hybrid)')->getContent();

    expect($html)->not->toMatch('/data-mockup-frame="\w"\s+data-chosen/');
});

it('404s a story in a disabled project and a malformed ID', function (string $url) {
    $this->project->update(['is_enabled' => str_contains($url, 'FX-65') ? false : true]);

    $this->get($url)->assertNotFound();
})->with(['/p/fx/s/FX-65', '/p/fx/s/fx-65', '/p/fx/s/FX-65x1', '/p/fx/s/F-1']);

it('says so, and logs it, when the story cannot be read from git', function () {
    Log::spy();
    // The row points at a commit git does not have.
    Story::onRef()->where('story_id', 'FX-65')->update(['sha' => str_repeat('0', 40)]);

    $this->get('/p/fx/s/FX-65')->assertOk()->assertSee('could not be read from git');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event) => $event === 'board.story_read_failed')->once();
});

it('does not load the compare frames until the overlay opens', function () {
    $html = $this->get('/p/fx/s/FX-65')->getContent();

    expect($html)->toContain('<template x-if="compare">');
});
