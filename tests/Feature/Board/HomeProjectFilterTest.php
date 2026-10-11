<?php

use App\Livewire\Board\Overview;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Tests\Support\SessionFixture;

/**
 * RD-6 acceptance criteria: the home overview narrows to the projects named in `?projects=`. Three enabled
 * projects and one disabled one, each with its own Needs me stories, live sessions and preflight runs, so every
 * panel's numbers say which projects it covered:
 *
 *   story-board: needs 2, in flight 1, live 1, preflight 1
 *   coins:       needs 3, in flight 2, live 2, preflight 2
 *   ledger-app:  needs 4, in flight 3, live 1, preflight 4
 */
beforeEach(function () {
    Bus::fake();
    $this->sessions = new SessionFixture;
    config(['board.sessions_path' => $this->sessions->root]);

    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $header = 'ts,project,branch,mode,wall_s,turns,tool_calls,tokens_in,tokens_out,subagent_tokens,pack_bytes,audit_model';
    $run = fn (int $i) => now()->subDays(1)->subMinutes($i)->format('Y-m-d\TH:i:s\Z').',x,main,diff,60,5,10,1000,100,0,2048,sonnet';
    $session = 0;

    $plan = [
        'story-board' => ['SB', 1, 1, 1, 1],
        'coins' => ['CO', 1, 2, 2, 2],
        'ledger-app' => ['LA', 1, 3, 1, 4],
    ];
    foreach ($plan as $name => [$prefix, $drafts, $approved, $live, $runs]) {
        $project = Project::factory()->create(['name' => $name, 'path' => "/work/{$name}", ...$fresh]);
        $n = 0;
        foreach (array_merge(array_fill(0, $drafts, 'draft'), array_fill(0, $approved, 'approved')) as $status) {
            $n++;
            Story::factory()->for($project)->create(['story_id' => "{$prefix}-{$n}", 'status' => $status, 'dated_on' => '2026-10-01']);
        }
        for ($i = 0; $i < $live; $i++) {
            $session++;
            $this->sessions->session("/work/{$name}", 'main', sprintf('bbbbbbbb-0000-0000-0000-%012d', $session), 1);
        }
        $this->sessions->preflightCsv(SessionFixture::folderFor("/work/{$name}"), [$header, ...array_map($run, range(1, $runs))]);
    }

    // Switched off: its story, session and runs must never count, whatever the URL says.
    $acme = Project::factory()->disabled()->create(['name' => 'acme', 'path' => '/work/acme', ...$fresh]);
    Story::factory()->for($acme)->create(['story_id' => 'AC-1', 'status' => 'approved']);
    $this->sessions->session('/work/acme', 'main', 'bbbbbbbb-0000-0000-0000-999999999999', 1);
});

afterEach(function () {
    $this->sessions->destroy();
});

/**
 * What each panel of the rendered overview covers: the four figures' values, and the projects of the Needs me
 * rows and the Live now cards (sorted, unique).
 *
 * @return array{live: string, needs: string, preflight: string, in_flight: string, needs_projects: list<string>, live_projects: list<string>}
 */
function filteredHome(string $html): array
{
    $value = function (string $key) use ($html) {
        preg_match('/data-figure="'.$key.'".*?data-figure-value="([^"]*)"/s', $html, $m);

        return $m[1] ?? 'missing';
    };
    $projects = function (string $pattern) use ($html) {
        preg_match_all($pattern, $html, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    };

    return [
        'live' => $value('live'),
        'needs' => $value('needs'),
        'preflight' => $value('preflight'),
        'in_flight' => $value('in-flight'),
        'needs_projects' => $projects('/data-needs-story="([^"\/]+)\//'),
        'live_projects' => $projects('/data-live-card="([^"]+)"/'),
    ];
}

/** Every enabled project, as filteredHome() reads it. */
const HOME_ALL = ['live' => '4', 'needs' => '9', 'preflight' => '7', 'in_flight' => '6',
    'needs_projects' => ['coins', 'ledger-app', 'story-board'], 'live_projects' => ['coins', 'ledger-app', 'story-board']];

/** story-board alone. */
const HOME_STORY_BOARD = ['live' => '1', 'needs' => '2', 'preflight' => '1', 'in_flight' => '1',
    'needs_projects' => ['story-board'], 'live_projects' => ['story-board']];

/** story-board and coins. */
const HOME_TWO = ['live' => '3', 'needs' => '5', 'preflight' => '3', 'in_flight' => '3',
    'needs_projects' => ['coins', 'story-board'], 'live_projects' => ['coins', 'story-board']];

it('given no projects parameter, then every panel covers every enabled project', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(filteredHome($html))->toBe(HOME_ALL);
    expect($html)->toContain('All projects')->not->toContain('data-filter-chip');
});

it('given ?projects=story-board, then Needs me, Live now and the headline figures count only story-board', function () {
    $html = $this->get('/?projects=story-board')->assertOk()->getContent();

    expect(filteredHome($html))->toBe(HOME_STORY_BOARD);
    expect($html)->toContain('data-filter-chip="story-board"');
});

it('given two projects chosen, then each panel covers exactly those two', function () {
    $html = $this->get('/?projects=story-board,coins')->assertOk()->getContent();

    expect(filteredHome($html))->toBe(HOME_TWO);
    expect(substr_count($html, 'data-filter-chip='))->toBe(2);
});

it('given story-board chosen, when its chip is removed, then the URL drops it and the panels widen again', function () {
    $component = Livewire::withQueryParams(['projects' => 'story-board'])->test(Overview::class);
    expect(filteredHome($component->html()))->toBe(HOME_STORY_BOARD);

    $component->call('toggleProject', 'story-board')->assertSet('projects', '');

    expect(filteredHome($component->html()))->toBe(HOME_ALL);
    expect($component->html())->not->toContain('data-filter-chip');
});

it('given ?projects=nope, then the unknown name is ignored, the page shows all projects, and a warning is logged', function () {
    Log::spy();

    $html = $this->get('/?projects=nope')->assertOk()->getContent();

    expect(filteredHome($html))->toBe(HOME_ALL);
    expect($html)->not->toContain('data-filter-chip');
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context) => $event === 'board.overview_filter_ignored'
        && $context === ['reason' => 'unknown', 'names' => ['nope']])->once();
});

it('given a disabled project named in the URL, then it is ignored the same way', function () {
    Log::spy();

    $html = $this->get('/?projects=acme,coins')->assertOk()->getContent();

    expect(filteredHome($html))->toMatchArray(['needs_projects' => ['coins'], 'live_projects' => ['coins'], 'needs' => '3', 'live' => '2']);
    expect($html)->toContain('data-filter-chip="coins"')->not->toContain('data-filter-chip="acme"');
    Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context) => $event === 'board.overview_filter_ignored'
        && $context === ['reason' => 'disabled', 'names' => ['acme']])->once();

    // Named alone, the disabled project leaves no filter at all.
    expect(filteredHome($this->get('/?projects=acme')->assertOk()->getContent()))->toBe(HOME_ALL);
});

it('given the filter changes, then the page updates without a full reload and the URL matches the choice', function () {
    $component = Livewire::test(Overview::class);

    $component->call('toggleProject', 'story-board')->assertSet('projects', 'story-board');
    expect(filteredHome($component->html()))->toBe(HOME_STORY_BOARD);

    $component->call('toggleProject', 'coins')->assertSet('projects', 'story-board,coins');
    expect(filteredHome($component->html()))->toBe(HOME_TWO);

    $component->call('clearProjects')->assertSet('projects', '');
    expect(filteredHome($component->html()))->toBe(HOME_ALL);

    // The property is the URL's `projects`, pushed to history so Back returns, and dropped when empty.
    $url = (new ReflectionProperty(Overview::class, 'projects'))->getAttributes(Url::class)[0]->newInstance();
    expect($url->history)->toBeTrue()->and($url->except)->toBe('');
});

// Beyond the criteria: the control, its log lines and its guards.

it('lists every enabled project in the dropdown, never a disabled one, with the chosen ones ticked', function () {
    $html = $this->get('/?projects=coins')->assertOk()->getContent();

    preg_match_all('/data-filter-option="([^"]+)"/', $html, $m);
    expect($m[1])->toBe(['coins', 'ledger-app', 'story-board']);
    expect($html)->toMatch('/<input\b[^>]*data-filter-toggle="coins"[^>]*\schecked[\s>]/')
        ->not->toMatch('/<input\b[^>]*data-filter-toggle="ledger-app"[^>]*\schecked[\s>]/')
        ->toContain('data-filter-clear');
});

it('logs board.overview_filtered with the chosen names on the page load and on each change, not on a poll', function () {
    Log::spy();

    $component = Livewire::withQueryParams(['projects' => 'coins'])->test(Overview::class);
    $component->call('$refresh');
    $component->call('toggleProject', 'ledger-app');
    $component->call('clearProjects');

    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'board.overview_filtered' && $c === ['projects' => ['coins']])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'board.overview_filtered' && $c === ['projects' => ['coins', 'ledger-app']])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e, $c) => $e === 'board.overview_filtered' && $c === ['projects' => []])->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($e) => $e === 'board.overview_filtered')->times(3);
});

it('does not log a filter on an unfiltered page load', function () {
    Log::spy();

    $this->get('/')->assertOk();

    Log::shouldNotHaveReceived('info', fn ($e) => $e === 'board.overview_filtered');
    Log::shouldNotHaveReceived('warning', fn ($e) => $e === 'board.overview_filter_ignored');
});

it('drops an ignored name from the URL when the next change is made', function () {
    Log::spy();

    Livewire::withQueryParams(['projects' => 'nope,coins'])->test(Overview::class)
        ->call('toggleProject', 'ledger-app')
        ->assertSet('projects', 'coins,ledger-app');
});

it('reads a blank, repeated or padded name list as the names it holds', function () {
    expect(filteredHome($this->get('/?projects=,+story-board+,story-board,,')->assertOk()->getContent()))->toBe(HOME_STORY_BOARD);
});
