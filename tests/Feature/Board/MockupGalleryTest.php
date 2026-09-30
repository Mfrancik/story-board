<?php

use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

/**
 * SB-21: the mockup gallery at /mockups, the full-screen viewer and its file
 * route. Two fixture projects with docs/mockups/ committed; nothing here writes.
 */
function galleryProject(string $name, callable $build): array
{
    $fixture = new GitFixture;
    $build($fixture);
    $fixture->commitAndPush();
    $project = Project::factory()->create(['name' => $name, 'path' => $fixture->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);

    return [$fixture, $project];
}

function gate(string $chosen, string $why): string
{
    return "## Design mockup gate\n- Mockups: see docs/mockups\n- Chosen option: {$chosen}\n- Why I chose it: {$why}\n\n"
        ."## Data & interfaces\n- Schema/migrations: none.\n- Routes: `GET /widgets` (`widgets`), `GET /widgets/{id}`.\n";
}

function options(GitFixture $fixture, string $id, array $letters): void
{
    foreach ($letters as $letter) {
        $fixture->write("docs/mockups/{$id}/option-{$letter}.html", "<html><body><p>{$id} option {$letter}</p></body></html>");
    }
}

beforeEach(function () {
    [$this->alpha, $this->alphaProject] = galleryProject('alpha', function (GitFixture $f) {
        $f->story('AL-1', 'built', 'demo', gate('b', 'owner pick 2026-09-01, the calmer table'));
        options($f, 'AL-1', ['a', 'b']);
        $f->story('AL-2', 'draft', 'demo', gate('_(filled AFTER the owner picks; no build before this)_', '_pending_'));
        options($f, 'AL-2', ['a', 'b', 'c']);
        $f->write('docs/mockups/AL-2/index.html', '<html><head><meta name="description" content="Three ways to lay out the widget list."></head></html>');
        $f->write('docs/mockups/AL-2/shots/01.png', "\x89PNG\r\n\x1a\nfake");
        $f->write('.env', 'APP_KEY=secret-from-the-project');
        $f->story('AL-3', 'draft', 'demo', "## Why\nNo mockups.\n");
    });
    [$this->beta, $this->betaProject] = galleryProject('beta', function (GitFixture $f) {
        $f->story('BE-7', 'approved', 'demo', gate('a', 'first try'));
        options($f, 'BE-7', ['a', 'b']);
    });
    $this->artisan('board:refresh')->assertSuccessful();
});

afterEach(function () {
    $this->alpha->destroy();
    $this->beta->destroy();
    ($this->gamma ?? null)?->destroy();
});

it('lists both projects\' mockup sets grouped by project with story ID, title and option count', function () {
    $this->get('/mockups')->assertOk()
        ->assertSeeInOrder(['data-mockup-project="alpha"', 'AL-2', 'AL-1', 'data-mockup-project="beta"', 'BE-7'], false)
        ->assertSee('Story AL-1')
        ->assertSee('Story BE-7')
        ->assertSee('3 options')
        ->assertSee('2 options')
        ->assertDontSee('AL-3');
});

it('shows a set whose gate has no chosen option as awaiting pick and lists it first', function () {
    $this->get('/mockups')->assertOk()
        ->assertSeeInOrder(['data-set="alpha/AL-2"', 'data-state="awaiting"', 'Awaiting pick', 'data-set="alpha/AL-1"'], false);
});

it('shows a set with Chosen option b and a reason as picked b with the reason', function () {
    $this->get('/mockups')->assertOk()
        ->assertSeeInOrder(['data-set="alpha/AL-1"', 'data-state="picked"', 'Picked B', 'the calmer table'], false);
});

it('opens the viewer with the ID, title, description, Where and option a in a sandboxed iframe, switching options in Alpine', function () {
    $response = $this->get('/mockups/alpha/AL-2')->assertOk()
        ->assertSee('AL-2')
        ->assertSee('Story AL-2')
        ->assertSee('Three ways to lay out the widget list.')
        ->assertSeeInOrder(['Where', '/widgets'])
        ->assertSee('data-viewer-frame', false)
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false);

    // Option a is shown first; b and c are one Alpine switch away (their URLs are in the page, no reload).
    $html = $response->getContent();
    $frame = fn (string $o) => route('mockups.frame', ['project' => 'alpha', 'story' => 'AL-2', 'file' => "option-{$o}.html"]);
    expect($html)->toMatch('#<iframe data-viewer-frame src="'.preg_quote($frame('a'), '#').'"[^>]*sandbox="allow-scripts"#')
        ->and($html)->toContain('data-option-tab="c" data-src="'.$frame('c').'"');
});

it('falls back to the story\'s Story line when the set has no index.html', function () {
    $this->get('/mockups/beta/BE-7')->assertOk()->assertSee('As a tester.');
});

it('renders compare as two panes side by side that stack with a toggle below 768px', function () {
    $html = $this->get('/mockups/alpha/AL-2')->assertOk()->getContent();

    expect($html)->toContain('data-compare-toggle')
        ->and($html)->toContain('data-compare-pane="left"')
        ->and($html)->toContain('data-compare-pane="right"')
        // Stacked by default, two columns from md (768px); the swap toggle shows below md.
        ->and($html)->toContain('md:grid-cols-2')
        ->and($html)->toContain('data-compare-swap');
});

it('returns 404 and reads nothing for a mockup file outside docs/mockups/<ID>/', function (string $file) {
    Log::spy();
    // Real git for the listing, but the file's bytes must never be read.
    $this->partialMock(GitReader::class, fn ($mock) => $mock->shouldNotReceive('show'));

    $this->get('/mockups/alpha/AL-2/file/'.$file)->assertNotFound()->assertDontSee('secret-from-the-project');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_file_refused'
        && $context['project'] === 'alpha')->once();
})->with([
    '../../.env',
    'shots/../../../.env',
    '%2e%2e/%2e%2e/.env',
    'option-z.html',
    '.env',
]);

it('serves a listed mockup file and its assets sandboxed', function () {
    $response = $this->get('/mockups/alpha/AL-2/file/option-b.html')->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('AL-2 option b');
    expect($response->headers->get('Content-Security-Policy'))->toStartWith('sandbox allow-scripts;')
        ->not->toContain('allow-same-origin');

    $this->get('/mockups/alpha/AL-2/file/shots/01.png')->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('does not list a disabled or unknown project\'s sets and 404s its viewer and files', function () {
    $this->betaProject->update(['is_enabled' => false]);

    $this->get('/mockups')->assertOk()->assertDontSee('BE-7')->assertSee('AL-2');
    $this->get('/mockups/beta/BE-7')->assertNotFound();
    $this->get('/mockups/beta/BE-7/file/option-a.html')->assertNotFound();
    $this->get('/mockups/nope/BE-7')->assertNotFound();
    $this->get('/mockups/nope/BE-7/file/option-a.html')->assertNotFound();
});

it('404s the viewer for a story without a mockup set', function (string $url) {
    $this->get($url)->assertNotFound();
})->with(['/mockups/alpha/AL-3', '/mockups/alpha/NOPE-1', '/mockups/alpha/not-an-id']);

it('shows the empty state with where mockups come from and returns 200 when no project has mockups', function () {
    $this->alphaProject->update(['is_enabled' => false]);
    $this->betaProject->update(['is_enabled' => false]);

    $this->get('/mockups')->assertOk()->assertSee('No mockups yet')->assertSee('docs/mockups/&lt;ID&gt;/', false);
});

it('logs board.mockups_viewed on the gallery and board.mockup_viewed on the viewer', function () {
    Log::spy();

    $this->get('/mockups')->assertOk();
    $this->get('/mockups/alpha/AL-2')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.mockups_viewed'
        && $context['sets'] === 2 + 1 && $context['awaiting'] === 1)->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_viewed'
        && $context['project'] === 'alpha' && $context['story'] === 'AL-2' && $context['option'] === 'a' && $context['compare'] === false)->once();
});

it('puts Mockups in the sidebar with the awaiting count', function () {
    $this->get('/')->assertOk()->assertSee('data-sidebar-mockups', false)->assertSee('data-awaiting-count="1"', false);
});

it('links a story\'s modal and its project page to the gallery', function () {
    $this->get('/p/alpha?story=alpha/AL-2')->assertOk()
        ->assertSee('data-open-gallery', false)
        ->assertSee(route('mockups.show', ['project' => 'alpha', 'story' => 'AL-2']), false);
    $this->get('/p/alpha')->assertOk()->assertSee('data-project-mockups', false)
        ->assertSee(route('mockups', ['project' => 'alpha']), false);
});

it('shows no Mockups button on a project page without sets', function () {
    [$this->gamma] = galleryProject('gamma', fn (GitFixture $f) => $f->story('GA-1', 'draft'));
    $this->artisan('board:refresh', ['project' => 'gamma'])->assertSuccessful();

    $this->get('/p/gamma')->assertOk()->assertDontSee('data-project-mockups', false);
});

it('still lists a project\'s sets, without reasons, when git cannot read its checkout, and logs why', function () {
    Log::spy();
    File::deleteDirectory($this->beta->project);

    $this->get('/mockups')->assertOk()->assertSee('BE-7');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_sets_unreadable' && $context['project'] === 'beta');
});

it('logs board.mockup_not_found before the viewer 404s a story without a set', function () {
    Log::spy();

    $this->get('/mockups/alpha/AL-3')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'board.mockup_not_found' && $context['story'] === 'AL-3')->once();
});

it('reads several files at a ref in one batch, with null for a path that is not there', function () {
    $read = app(GitReader::class)->showMany($this->alpha->project, 'origin/main', [
        'docs/mockups/AL-2/option-a.html', 'docs/mockups/AL-2/nope.html', 'docs/mockups/AL-2/shots/01.png',
    ]);

    expect($read['docs/mockups/AL-2/option-a.html'])->toContain('AL-2 option a')
        ->and($read['docs/mockups/AL-2/nope.html'])->toBeNull()
        ->and($read['docs/mockups/AL-2/shots/01.png'])->toBe("\x89PNG\r\n\x1a\nfake");
});
