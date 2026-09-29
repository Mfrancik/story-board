<?php

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

beforeEach(function () {
    $this->fixture = new GitFixture;
    $this->fixture->story('FX-1', 'approved', 'demo', "## Design mockup gate\n- Chosen option: b\n- Why I chose it: clearer\n")
        ->write('docs/mockups/FX-1/option-a.html', '<html><body><img src="shots/01.png"><script>parent.document.title = "pwned"</script></body></html>')
        ->write('docs/mockups/FX-1/option-b.html', '<html><body>B</body></html>')
        ->write('docs/mockups/FX-1/shots/01.png', "\x89PNG\r\n\x1a\nfake")
        ->write('.env', 'APP_KEY=secret-from-the-project')
        ->story('FX-2', 'approved')
        ->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh')->assertSuccessful();
});

afterEach(function () {
    $this->fixture->destroy();
});

it('serves a mockup option from the ref as html', function () {
    $this->get('/p/fx/m/FX-1/option-b.html')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('B', false);
});

it('serves a relative asset from the mockup directory at the ref', function () {
    $response = $this->get('/p/fx/m/FX-1/shots/01.png')->assertOk()->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toStartWith("\x89PNG");
});

it('sandboxes every mockup response so its scripts cannot reach the board', function () {
    $csp = $this->get('/p/fx/m/FX-1/option-a.html')->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)->toContain('sandbox allow-scripts')
        ->not->toContain('allow-same-origin')
        ->not->toContain('allow-top-navigation');
    $this->get('/p/fx/m/FX-1/option-a.html')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('reads the mockup from the ref, not from the working tree', function () {
    // The registered clone never pulled, so its working tree has no docs/mockups at all.
    expect(file_exists($this->fixture->project.'/docs/mockups/FX-1/option-a.html'))->toBeFalse();

    $this->get('/p/fx/m/FX-1/option-a.html')->assertOk();
});

it('refuses path traversal out of the mockup directory', function (string $file) {
    Log::spy();

    $this->get('/p/fx/m/FX-1/'.$file)->assertNotFound()->assertDontSee('secret-from-the-project');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event) => $event === 'board.mockup_path_rejected')->once();
})->with([
    '../../../.env',
    'shots/../../../../.env',
    '..%2F..%2F..%2F.env',
    '%2e%2e/%2e%2e/%2e%2e/.env',
    './option-a.html',
    'shots//01.png',
    '.hidden',
]);

it('returns 404 for a file that is not at the ref', function () {
    $this->get('/p/fx/m/FX-1/option-z.html')->assertNotFound();
});

it('returns 404 for an unknown story, a story without mockups, or an unknown project', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    '/p/fx/m/NOPE-1/option-a.html',
    '/p/fx/m/FX-2/option-a.html',
    '/p/nope/m/FX-1/option-a.html',
]);

it('cannot be pointed at another story\'s directory', function () {
    $this->get('/p/fx/m/FX-2/../FX-1/option-a.html')->assertNotFound();
});
