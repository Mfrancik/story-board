<?php

use App\Actions\Board\ReadStoryOutline;
use App\Livewire\Board\StoryPage;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * RD-3: the short story page at `/p/{project}/s/{id}` in the RD-2 shell — tags
 * and title, one summary line, one primary action, the mockups, and every other
 * detail folded away. Every pick lands in a throwaway GitFixture clone, never a
 * real project. The SB-4 guards (404s, sandbox, unreadable git, quoted choices)
 * carry over from the page this one replaced.
 */

/** A story file laid out the way the kit writes one, with the sections the Details fold. */
function shortStory(string $id, string $status, string $story, string $gate, string $extra = ''): string
{
    return "# {$id} — Story {$id}\nStatus: {$status}         Journey: none\n{$extra}Source: fixture\n\n"
        ."## Story\n{$story}\n\n## Acceptance criteria (executable — these become the Pest test names)\n"
        ."- Given one, then `one` shows.\n- Given two, then two shows.\n- Given three, then three\n  shows on two lines.\n\n"
        ."## Design mockup gate\n{$gate}\n\n## Do NOT touch\n- `config/`\n- `app/Services/GitReader.php`\n\n"
        ."## Links\nJourney: none · Depends on: FX-3\n";
}

beforeEach(function () {
    $pending = "- Mockups: docs/mockups/FX-1/\n- Chosen option: _pending_\n- Why I chose it: _pending_";
    $this->fixture = new GitFixture;
    $this->fixture
        ->write('stories/demo/FX-1-story.md', shortStory('FX-1', 'draft', 'As the owner, I want the long text folded. So that I decide fast.', $pending
            ."\n\n## Data & interfaces\n- Routes: `GET /widgets`.", "Summary: One line that says what FX-1 is.\n"))
        ->write('stories/demo/FX-2-story.md', shortStory('FX-2', 'approved', 'As the owner, I want '.str_repeat('a very long sentence with many words ', 10).'that never seems to end. Then more.', 'n/a — non-visual'))
        ->write('stories/demo/FX-3-story.md', shortStory('FX-3', 'built', 'As the owner, I want a built story.', 'n/a — non-visual'))
        ->write('stories/demo/FX-4-story.md', "# FX-4 — No story section\nStatus: draft\n\n## Why\nNothing to summarise.\n")
        ->story('FX-5', 'built', 'demo', "## Design mockup gate\n- Chosen option: **D** (revised hybrid) — the head over the sections\n");
    foreach (['a', 'b', 'c'] as $o) {
        $this->fixture->write("docs/mockups/FX-1/option-{$o}.html", "<p>FX-1 {$o}</p>");
    }
    $this->fixture->write('docs/mockups/FX-5/option-c.html', 'c')->write('docs/mockups/FX-5/option-d.html', 'd')->commitAndPush();
    $this->project = Project::factory()->create(['name' => 'fx', 'path' => $this->fixture->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh', ['project' => 'fx'])->assertSuccessful();
    // The registered clone's working tree must hold the story the pick rewrites, under an identity that can commit.
    $this->fixture->syncProject();
    $this->fixture->git($this->fixture->project, 'config', 'user.name', 'Fixture Owner');
    $this->fixture->git($this->fixture->project, 'config', 'user.email', 'owner@example.test');
    $this->storyFile = $this->fixture->project.'/stories/demo/FX-1-story.md';
    $this->head = fn () => trim($this->fixture->git($this->fixture->project, 'rev-parse', 'HEAD'));
});

afterEach(function () {
    $this->fixture->destroy();
});

/** A journey shot of `/widgets`, so FX-1 has a "Today" to compare with. */
function widgetsShot(string $project): void
{
    $dir = "{$project}/storage/app/journey-shots/widgets";
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/manifest.json", json_encode([
        ['step' => 'open', 'route' => '/widgets', 'story' => 'FX-0', 'captured_at' => now()->subDay()->toIso8601String(), 'commit' => 'abc1234', 'file' => '01-widgets.png'],
    ]));
    File::put("{$dir}/01-widgets.png", "\x89PNG\r\n\x1a\nwidgets");
}

it('Given a story with a Summary: line, then the page shows that line under the title', function () {
    $this->get('/p/fx/s/FX-1')->assertOk()
        ->assertSeeInOrder(['<h1', 'Story FX-1', '</h1>', 'data-summary', 'One line that says what FX-1 is.'], false)
        ->assertDontSee('As the owner, I want the long text folded.</p>', false);
});

it('Given a story without one, then it shows the first sentence of ## Story, cut to 200 characters at a word', function () {
    Log::spy();

    $html = $this->get('/p/fx/s/FX-2')->assertOk()->getContent();

    preg_match('/data-summary[^>]*>([^<]*)</', $html, $m);
    $summary = html_entity_decode(trim($m[1] ?? ''));
    expect($summary)->toStartWith('As the owner, I want a very long sentence')
        ->toEndWith('…')
        ->and(mb_strlen($summary))->toBeLessThanOrEqual(ReadStoryOutline::SUMMARY_MAX)
        // Cut at a word: what precedes the ellipsis is a whole word of the sentence.
        ->and(mb_substr($summary, 0, -1))->toMatch('/(a|very|long|sentence|with|many|words)$/')
        ->and($summary)->not->toContain('Then more');
    Log::shouldHaveReceived('debug')->withArgs(fn ($event, $ctx = []) => $event === 'board.story_summary_fallback' && $ctx['story'] === 'FX-2')->once();
});

it('Given a story awaiting a pick with options A–C, then the mockup buttons show Today, A, B and C, and the primary action reads "Pick option A" until another option is selected', function () {
    widgetsShot($this->fixture->project);

    $html = $this->get('/p/fx/s/FX-1')->assertOk()
        ->assertSeeInOrder(['data-mockup-option="today"', 'data-mockup-option="a"', 'data-mockup-option="b"', 'data-mockup-option="c"'], false)
        ->assertSeeHtml('data-current-pane="shot"')
        ->getContent();

    expect($html)->toMatch('/data-primary-action[^>]*>.*?Pick option <span[^>]*x-text="choice\.toUpperCase\(\)"[^>]*>A<\/span>/s')
        // The selected option starts at A and follows the option buttons, never Today.
        ->and($html)->toContain("choice: 'a'")
        ->and($html)->toMatch('/data-mockup-option="b"[^>]*x-on:click="view = \'b\'; choice = \'b\'"/');
});

it('Given option B selected and a reason typed, when Pick is pressed, then the story file gets B and the reason, and the page shows it as picked', function () {
    $before = ($this->head)();

    Livewire::test(StoryPage::class, ['project' => $this->project, 'storyId' => 'FX-1'])
        ->assertSeeHtml('data-primary-action')
        ->call('pick', 'b', 'calmer layout')
        ->assertSet('refusal', null)
        ->assertSeeHtml('data-pick-done')
        ->assertSee('Picked B')
        ->assertDontSeeHtml('data-primary-action');

    $text = File::get($this->storyFile);
    expect($text)->toContain("- Chosen option: b\n")
        ->and($text)->toMatch('/^- Why I chose it: owner pick \d{4}-\d{2}-\d{2} \(board\): calmer layout$/m')
        ->and(trim($this->fixture->git($this->fixture->project, 'log', '--format=%s', "{$before}..HEAD")))->toBe('docs(FX-1): record mockup pick b');
});

it('Given the pick is refused (for example, a dirty checkout), then the refusal message shows and nothing is written', function () {
    File::append($this->storyFile, "\nan unsaved edit\n");
    $edited = File::get($this->storyFile);
    $before = ($this->head)();

    Livewire::test(StoryPage::class, ['project' => $this->project, 'storyId' => 'FX-1'])
        ->call('pick', 'b', 'calmer layout')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'this story has unsaved changes'))
        ->assertSeeHtml('data-pick-refusal')
        ->assertSee('this story has unsaved changes')
        ->assertDontSeeHtml('data-pick-done');

    expect(File::get($this->storyFile))->toBe($edited)->and(($this->head)())->toBe($before);
});

it('Given a built story with no mockups, then no Mockups section and no primary action show', function () {
    $this->get('/p/fx/s/FX-3')->assertOk()
        ->assertSee('Story FX-3')
        ->assertDontSeeHtml('data-story-mockups')
        ->assertDontSeeHtml('data-primary-action')
        ->assertDontSee('Mockups</h2>', false);
});

it('Given the page loads, then every Details section is collapsed and its header shows its count or summary', function () {
    $html = $this->get('/p/fx/s/FX-1')->assertOk()->getContent();

    preg_match_all('/<details\b[^>]*>/', $html, $details);
    expect($details[0])->toHaveCount(5)
        ->and(implode('', $details[0]))->not->toContain(' open');
    expect($html)->toMatch('/data-detail="criteria".*?<summary.*?3 criteria.*?<\/summary>/s')
        ->toMatch('/data-detail="depends".*?<summary.*?FX-3.*?<\/summary>/s')
        ->toMatch('/data-detail="untouchable".*?<summary.*?2 items.*?<\/summary>/s')
        ->toMatch('/data-detail="versions".*?<summary.*?None.*?<\/summary>/s')
        ->toMatch('/data-detail="text".*?<summary.*?stories\/demo\/FX-1-story\.md · \d+ lines.*?<\/summary>/s');
    // The criteria list keeps a wrapped item whole and renders its inline code.
    expect($html)->toContain('<code>one</code>')->toContain('Given three, then three shows on two lines.');
});

it('Given an unknown story ID, then the page is 404', function (string $url) {
    $this->get($url)->assertNotFound();
})->with(['/p/fx/s/NOPE-1', '/p/nope/s/FX-1', '/p/fx/s/..%2F..%2F.env']);

it('shows no summary line for a story with no ## Story section and no Summary: line', function () {
    $this->get('/p/fx/s/FX-4')->assertOk()->assertSee('No story section')->assertDontSeeHtml('data-summary');
});

it('renders the page in the RD-2 shell with the tags, and links Depends on to that story', function () {
    $html = $this->get('/p/fx/s/FX-1')->assertOk()
        ->assertSeeHtml('class="shell"')
        ->assertSeeInOrder(['data-tag="id"', 'FX-1', 'data-tag="status"', 'draft · awaiting your pick', 'data-tag="initiative"', 'demo'], false)
        ->getContent();

    expect($html)->toMatch('/data-nav-project="fx"\s+aria-current="page"/')
        ->toContain('href="'.route('stories.show', ['project' => 'fx', 'storyId' => 'FX-3']).'"')
        // Ported from SB-7's AppShellTest (RD-9): the shell's 375 px menu, and the breadcrumb back to Stories.
        ->toContain('aria-label="Open menu"')
        ->toContain('href="'.route('projects.stories', ['project' => 'fx']).'"');
});

// Ported from SB-7's AppShellTest (RD-9): EnsureProjectIsShown refuses the story page before binding, and says why.
it('refuses the story page of an unknown or disabled project and logs board.project_page_refused with the reason', function (string $url, string $reason) {
    Log::spy();
    Project::factory()->disabled()->create(['name' => 'acme-site']);

    $this->get($url)->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c = []) => $e === 'board.project_page_refused' && $c['reason'] === $reason)->once();
})->with([
    'unknown' => ['/p/nope/s/AB-1', 'unknown'],
    'disabled' => ['/p/acme-site/s/AB-1', 'disabled'],
]);

it('shows one sandboxed frame of the selected option, never with same-origin access, and links full screen and side by side', function () {
    $html = $this->get('/p/fx/s/FX-1')->assertOk()->getContent();

    expect(substr_count($html, '<iframe'))->toBe(1)
        ->and($html)->toContain('sandbox="allow-scripts"')
        ->and($html)->not->toContain('allow-same-origin')
        ->and($html)->toContain('src="'.route('mockups.file', ['project' => 'fx', 'storyId' => 'FX-1', 'file' => 'option-a.html']).'"')
        ->and($html)->toContain('href="'.route('mockups.show', ['project' => 'fx', 'story' => 'FX-1']).'"')
        ->and($html)->toContain('data-full-screen');
});

it('offers no Today button when no journey shot shows the page', function () {
    $this->get('/p/fx/s/FX-1')->assertOk()->assertDontSeeHtml('data-mockup-option="today"')->assertSeeHtml('data-mockup-option="a"');
});

it('quotes a written choice the parser could not read, with no primary action', function () {
    $this->get('/p/fx/s/FX-5')->assertOk()
        ->assertSeeHtml('data-chosen-text')
        ->assertSee('D (revised hybrid)')
        ->assertDontSeeHtml('data-primary-action');
});

it('refuses and logs a pick for a story with no mockup set, writing nothing', function () {
    Log::spy();
    $before = ($this->head)();

    Livewire::test(StoryPage::class, ['project' => $this->project, 'storyId' => 'FX-3'])
        ->call('pick', 'a', 'forged')
        ->assertSet('refusal', fn ($r) => str_contains($r, 'no mockup set'));

    expect(($this->head)())->toBe($before);
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $ctx = []) => $event === 'board.mockup_pick_refused' && $ctx['story'] === 'FX-3')->once();
});

it('logs each story view', function () {
    Log::spy();

    $this->get('/p/fx/s/FX-1')->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn ($event, $ctx = []) => $event === 'board.story_viewed' && $ctx['story'] === 'FX-1')->once();
});

it('404s a story in a disabled project and a malformed ID', function (string $url) {
    $this->project->update(['is_enabled' => ! str_contains($url, 'FX-1')]);

    $this->get($url)->assertNotFound();
})->with(['/p/fx/s/FX-1', '/p/fx/s/fx-1', '/p/fx/s/FX-1x1', '/p/fx/s/F-1']);

it('says so, and logs it, when the story cannot be read from git', function () {
    Log::spy();
    // The row points at a commit git does not have.
    Story::onRef()->where('story_id', 'FX-3')->update(['sha' => str_repeat('0', 40)]);

    $this->get('/p/fx/s/FX-3')->assertOk()->assertSee('could not be read from git')->assertDontSeeHtml('data-summary');

    Log::shouldHaveReceived('warning')->withArgs(fn ($event) => $event === 'board.story_read_failed')->once();
});
