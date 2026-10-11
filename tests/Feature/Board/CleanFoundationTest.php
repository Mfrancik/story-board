<?php

use App\Actions\Board\ReadMockupSets;
use App\Exceptions\StoryPickRefusedException;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Livewire\Board\MockupViewer;
use App\Livewire\Board\ProjectAppMap;
use App\Livewire\Board\ProjectHandbook;
use App\Livewire\Board\ProjectPreflight;
use App\Livewire\Board\ProjectStories;
use App\Models\Project;
use App\Models\Story;
use App\Services\MockupGateParser;
use App\Services\StoryPickWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * RD-1: the starter kit is gone, every board route still answers, and the three
 * consolidations (status order, gate parser, project-page trait) behave as the
 * code they replaced. One fixture project with a story awaiting a mockup pick.
 */
beforeEach(function () {
    Queue::fake();
    $this->fixture = new GitFixture;
    $this->fixture->story('RD-1', 'draft', 'demo', "## Design mockup gate\n- Mockups: docs/mockups/RD-1/\n"
        ."- Chosen option: _(filled AFTER the owner picks; no build before this)_\n- Why I chose it: _pending_\n\n## Links\n");
    foreach (['a', 'b'] as $letter) {
        $this->fixture->write("docs/mockups/RD-1/option-{$letter}.html", "<p>RD-1 {$letter}</p>");
    }
    $this->fixture->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh')->assertSuccessful();
    $this->fixture->syncProject();
});

afterEach(function () {
    $this->fixture->destroy();
});

it('returns 404 for /dashboard, /settings/profile, /login and /register', function (string $url) {
    $this->get($url)->assertNotFound();
})->with(['/dashboard', '/settings/profile', '/login', '/register']);

it('still returns 200 for each existing board route', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    '/',
    '/p/fx/handbook',
    '/p/fx/stories',
    '/p/fx/preflight',
    '/p/fx/map',
    '/p/fx/s/RD-1',
    '/p/fx/m/RD-1/option-a.html',
    '/projects',
    '/mockups',
    '/mockups/fx/RD-1',
    '/mockups/fx/RD-1/file/option-a.html',
]);

it('serves each mockup file route with the type its extension names', function (string $url) {
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
})->with(['/p/fx/m/RD-1/option-a.html', '/mockups/fx/RD-1/file/option-a.html']);

it('finds a chosen option written in bold, and ReadMockupSets and StoryPickWriter give that same answer', function () {
    // A pick made by hand on the checkout's branch, in bold, not pushed: the ref still reads "awaiting".
    $file = $this->fixture->project.'/stories/demo/RD-1-story.md';
    File::put($file, str_replace('_(filled AFTER the owner picks; no build before this)_', '**B**', File::get($file)));
    $this->fixture->git($this->fixture->project, 'config', 'user.name', 'Fixture Owner');
    $this->fixture->git($this->fixture->project, 'config', 'user.email', 'owner@example.test');
    $this->fixture->git($this->fixture->project, 'commit', '--quiet', '-am', 'docs(RD-1): pick B by hand');
    $markdown = File::get($file);

    $parser = new MockupGateParser;
    expect($parser->pickedLetter($markdown))->toBe('b')
        ->and($parser->refusalFor($markdown))->toBe('this story already has a pick: B');

    $set = app(ReadMockupSets::class)->find($this->project, 'RD-1');
    expect($set['state'])->toBe(ReadMockupSets::PICKED)
        ->and($set['picked'])->toBe('b')
        ->and($set['pushed'])->toBeFalse();

    $row = Story::onRef()->where('story_id', 'RD-1')->firstOrFail();
    expect(fn () => app(StoryPickWriter::class)->pick($this->project, $row, ['a', 'b'], 'a', 'x'))
        ->toThrow(StoryPickRefusedException::class, 'this story already has a pick: B');
});

it('redirects as before when a component using the project-page trait hydrates for a disabled project', function (string $component, array $params, string $route) {
    expect(class_uses_recursive($component))->toContain(GuardsProjectPage::class);

    $page = Livewire::test($component, ['project' => $this->project, ...$params]);
    $this->project->update(['is_enabled' => false]);

    $page->call('$refresh')->assertRedirect(route($route));
})->with([
    'stories' => [ProjectStories::class, [], 'home'],
    'preflight' => [ProjectPreflight::class, [], 'home'],
    'handbook' => [ProjectHandbook::class, [], 'home'],
    'app map' => [ProjectAppMap::class, [], 'home'],
    'mockup viewer' => [MockupViewer::class, ['story' => 'RD-1'], 'mockups'],
]);
