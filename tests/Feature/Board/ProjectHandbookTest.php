<?php

use App\Actions\Board\ReadHandbook;
use App\Livewire\Board\ProjectHandbook;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GitFixture;

/**
 * SB-14 acceptance criteria: `/p/{project}/handbook` reads a project's rules, lessons, standards,
 * runbook, decisions and skills from git at its snapshot SHA, and badges each standards file and
 * kit skill against the dev-standards kit. The fixtures mirror the real shapes checked on
 * 2026-09-29 (coins 16 lessons, client-dashboard 5, rent-track 2, asset-track none; coins'
 * codebase standard changed and disposal missing; the story skill the same as the kit; rent-track's
 * project-only `new-volt-page`; 1,747 decisions paged by 50) without touching the real repos.
 */
beforeEach(function () {
    Queue::fake();

    // The kit: four standards and three skills, as ~/Code/dev-standards has them.
    $this->kit = new GitFixture;
    foreach (['codebase', 'design', 'logging', 'disposal'] as $name) {
        $this->kit->write("docs/standards/{$name}-standards.md", "# Kit {$name} standards\n\nThe kit's {$name} rules.\n");
    }
    foreach (['build', 'story', 'preflight'] as $skill) {
        $this->kit->write(".claude/skills/{$skill}/SKILL.md", "---\nname: {$skill}\n---\n# {$skill}\n");
    }
    $this->kit->commitAndPush('kit')->syncProject();
    config(['board.kit_path' => $this->kit->project, 'board.kit_ref' => 'origin/main']);

    $this->repos = [];
});

afterEach(function () {
    $this->kit->destroy();
    foreach ($this->repos as $repo) {
        $repo->destroy();
    }
});

/**
 * A LESSONS.md with `$n` entries L-1..L-n, plus the ledger's commented-out format template and a
 * fenced example heading — neither of which is an entry (the reason the first real counts were off).
 */
function lessonsFile(int $n): string
{
    $text = "# Lessons Ledger\n\n<!-- Entry format:\n## L-<n> — <date> — <short name>\nSymptom: | Root cause:\n-->\n\n"
        ."```markdown\n## L-99 — 2026-01-01 — A fenced example, not a lesson\n```\n\n";
    foreach (range(1, $n) as $i) {
        $day = sprintf('2026-09-%02d', $i);
        $scope = $i % 2 ? 'PROJECT-ONLY' : 'PROMOTE TO CENTRAL';
        $text .= "## L-{$i} — {$day} — Lesson number {$i}\nSymptom: thing {$i} broke. | Root cause: reason {$i}.\nScope: {$scope}\n\n";
    }

    return $text;
}

/**
 * Register a fixture project on the board, its snapshot SHA at origin's main. `$files` maps path to
 * contents; the fixture's own stories/README.md is always there.
 *
 * @param  array<string, string>  $files
 */
function handbookProject(object $test, string $name, array $files): Project
{
    $repo = new GitFixture;
    foreach ($files as $path => $contents) {
        $repo->write($path, $contents);
    }
    $repo->commitAndPush()->syncProject();
    $test->repos[$name] = $repo;

    return Project::factory()->create([
        'name' => $name, 'path' => $repo->project, 'sha' => $repo->originSha(), 'state' => Project::STATE_OK,
        'indexed_at' => now(), 'refresh_attempted_at' => now(),
    ]);
}

/**
 * The coins-shaped project: 16 lessons, codebase standard changed, design and logging the same as
 * the kit, disposal missing, a project-only standard, story skill the same, preflight changed, build
 * missing, a project-only skill, and `$decisions` decision files plus a README.
 */
function coinsLike(object $test, int $decisions = 120): Project
{
    $kit = $test->kit->author;
    $files = [
        'CLAUDE.md' => "# CLAUDE.md — Coins operating manual\n\nMySQL only.\n\n<script>alert(1)</script>\n",
        'docs/LESSONS.md' => lessonsFile(16),
        'docs/RUNBOOK.md' => "# Runbook\n\n## 2026-09-01 — Queue stuck\nRestart the worker.\n",
        'docs/standards/codebase-standards.md' => "# Coins codebase standards\n\nOur own rules.\n",
        'docs/standards/design-standards.md' => file_get_contents("{$kit}/docs/standards/design-standards.md"),
        'docs/standards/logging-standards.md' => file_get_contents("{$kit}/docs/standards/logging-standards.md"),
        'docs/standards/money-standards.md' => "# Money\n\nCents only.\n",
        '.claude/skills/story/SKILL.md' => file_get_contents("{$kit}/.claude/skills/story/SKILL.md"),
        '.claude/skills/preflight/SKILL.md' => "---\nname: preflight\n---\n# coins preflight\n",
        '.claude/skills/new-volt-page/SKILL.md' => "# new volt page\n",
        'docs/decisions/README.md' => "# Decisions\n",
    ];
    foreach (range(1, $decisions) as $i) {
        $slug = $i % 10 === 0 ? 'bullion-rule' : 'other-rule';
        $files[sprintf('docs/decisions/ADR-%04d-%s.md', $i, $slug)] = "# ADR-{$i}\n\nDecided {$i}.\n";
    }

    return handbookProject($test, 'coins', $files);
}

/**
 * The lesson numbers of a Lessons section, in page order.
 *
 * @return list<int>
 */
function lessonNumbers(string $html): array
{
    preg_match_all('/data-lesson="(\d+)"/', $html, $m);

    return array_map('intval', $m[1]);
}

/**
 * A section's HTML, as the Livewire call behind its tab returns it.
 */
function handbookSection(Project $project, string $section): string
{
    $html = null;
    Livewire::test(ProjectHandbook::class, ['project' => $project])
        ->call('loadSection', $section)
        ->assertReturned(function ($value) use (&$html) {
            $html = $value;

            return is_string($value);
        });

    return $html;
}

/**
 * The badge a section gives one file or skill, or null when it has none.
 */
function badgeFor(string $html, string $attr, string $name): ?string
{
    return preg_match('/data-'.$attr.'="'.preg_quote($name, '/').'"[^>]*data-badge="([a-z]+)"/', $html, $m) ? $m[1] : null;
}

it('Given coins, when /p/coins/handbook loads, then Lessons lists 16 entries, newest first, each with its number, date and name', function () {
    $coins = coinsLike($this);
    $this->get('/p/coins/handbook')->assertOk()->assertSee('Handbook');

    $html = handbookSection($coins, 'lessons');

    expect(lessonNumbers($html))->toBe(range(16, 1));
    expect($html)->toContain('L-16')->toContain('2026-09-16')->toContain('Lesson number 16')
        ->not->toContain('A fenced example')->not->toContain('&lt;short name&gt;');
});

it('Given client-dashboard, then Lessons lists 5 entries. Given rent-track, then it lists 2', function () {
    $cd = handbookProject($this, 'client-dashboard', ['docs/LESSONS.md' => lessonsFile(5)]);
    $rent = handbookProject($this, 'rent-track', ['docs/LESSONS.md' => lessonsFile(2)]);

    expect(lessonNumbers(handbookSection($cd, 'lessons')))->toBe([5, 4, 3, 2, 1])
        ->and(lessonNumbers(handbookSection($rent, 'lessons')))->toBe([2, 1]);
});

it('ignores `## L-` headings inside HTML comments and code fences', function () {
    $lessons = app(ReadHandbook::class)->parseLessons(lessonsFile(3)."<!--\n## L-50 — 2026-01-01 — commented\n-->\n~~~\n## L-51 — 2026-01-01 — tilde fence\n~~~\n");

    expect(array_column($lessons, 'number'))->toBe([3, 2, 1])
        ->and($lessons[0])->toMatchArray(['date' => '2026-09-03', 'name' => 'Lesson number 3', 'scope' => 'PROJECT-ONLY']);
});

it('Given asset-track, which has no docs/LESSONS.md and no docs/standards/, then Lessons and Standards show empty states naming the file and the page still returns 200', function () {
    $asset = handbookProject($this, 'asset-track', ['CLAUDE.md' => "# Asset\n"]);

    $this->get('/p/asset-track/handbook')->assertOk();
    expect(handbookSection($asset, 'lessons'))->toContain('asset-track has no')->toContain('docs/LESSONS.md')->toContain('data-empty="docs/LESSONS.md"')
        ->and(handbookSection($asset, 'standards'))->toContain('data-empty="docs/standards/"')->toContain('asset-track has no')
        ->and(handbookSection($asset, 'runbook'))->toContain('data-empty="docs/RUNBOOK.md"')
        ->and(handbookSection($asset, 'decisions'))->toContain('data-empty="docs/decisions/"');
});

it('Given coins\' codebase-standards.md differs from the kit\'s blob, then it is badged "Changed in this project"; a file identical to the kit is "Same as kit"', function () {
    $html = handbookSection(coinsLike($this), 'standards');

    expect(badgeFor($html, 'standard', 'codebase-standards.md'))->toBe('changed')
        ->and(badgeFor($html, 'standard', 'design-standards.md'))->toBe('same')
        ->and(badgeFor($html, 'standard', 'money-standards.md'))->toBe('project')
        ->and($html)->toContain('Changed in this project')->toContain('Same as kit')
        // Both texts, one after the other: the project's and the kit's.
        ->toContain('Coins codebase standards')->toContain('Kit codebase standards');
});

it('Given client-dashboard\'s .claude/skills/story/SKILL.md matches the kit, then it is badged "Same as kit"', function () {
    $cd = handbookProject($this, 'client-dashboard', [
        '.claude/skills/story/SKILL.md' => file_get_contents($this->kit->author.'/.claude/skills/story/SKILL.md'),
        '.claude/skills/preflight/SKILL.md' => "# changed\n",
    ]);
    $html = handbookSection($cd, 'skills');

    expect(badgeFor($html, 'skill', 'story'))->toBe('same')
        ->and(badgeFor($html, 'skill', 'preflight'))->toBe('changed')
        ->and(badgeFor($html, 'skill', 'build'))->toBe('missing');
});

it('Given asset-track, then docs/standards/codebase-standards.md is badged "Missing"', function () {
    $asset = handbookProject($this, 'asset-track', ['CLAUDE.md' => "# Asset\n"]);
    $html = handbookSection($asset, 'standards');

    expect(badgeFor($html, 'standard', 'codebase-standards.md'))->toBe('missing')
        ->and(badgeFor($html, 'standard', 'disposal-standards.md'))->toBe('missing')
        ->and($html)->toContain('Missing');
});

it('Given coins lacks the kit\'s disposal-standards.md, then it is badged "Missing"', function () {
    expect(badgeFor(handbookSection(coinsLike($this), 'standards'), 'standard', 'disposal-standards.md'))->toBe('missing');
});

it('Given a skill folder that is in the project and not in the kit (rent-track new-volt-page), then it is badged "Project only"', function () {
    $rent = handbookProject($this, 'rent-track', ['.claude/skills/new-volt-page/SKILL.md' => "# volt\n", '.claude/skills/story/SKILL.md' => "# ours\n"]);
    $html = handbookSection($rent, 'skills');

    expect(badgeFor($html, 'skill', 'new-volt-page'))->toBe('project')
        ->and($html)->toContain('Project only')->toContain('From the kit');
});

it('Given coins\' decision files, then the Decisions list pages them 50 at a time with a file-name filter over every file', function () {
    $html = handbookSection(coinsLike($this, 120), 'decisions');

    expect(ProjectHandbook::DECISIONS_PAGE)->toBe(50)
        ->and($html)->toContain('data-decisions')->toContain('data-decision-filter')
        ->toContain('121 files')
        // Every name goes to the page once, so the filter narrows all of them, not one page.
        ->toContain('ADR-0001-other-rule.md')->toContain('ADR-0120-bullion-rule.md')->toContain('README.md');
});

it('opens a decision file rendered, safely', function () {
    $coins = coinsLike($this);

    Livewire::test(ProjectHandbook::class, ['project' => $coins])
        ->call('openDecision', 'ADR-0010-bullion-rule.md')
        ->assertReturned(fn ($html) => str_contains($html, '<h1>ADR-10</h1>') && str_contains($html, 'Decided 10.'));
});

it('opens a decision straight from ?decision= in the URL', function () {
    coinsLike($this);

    $this->get('/p/coins/handbook?decision=ADR-0007-other-rule.md')->assertOk()->assertSee('Decided 7.')->assertSee('data-decision-open', false);
});

it('Given a decision file name with .. or a leading - in the URL, then it is refused without calling git and board.handbook_refused is logged with reason bad_path', function (string $name) {
    $coins = coinsLike($this);
    Log::spy();
    $git = Mockery::mock(GitReader::class);
    $git->shouldReceive('isRepository')->andReturn(false);
    // The first tab's file is listed (Decisions from the URL, Rules on a bare mount); the refused name never reaches git.
    $git->shouldReceive('listFiles')->with($coins->path, $coins->sha, Mockery::anyOf('docs/decisions/', 'CLAUDE.md'))->andReturn([]);
    $git->shouldNotReceive('show');
    $git->shouldNotReceive('run');
    $this->app->instance(GitReader::class, $git);

    $this->get('/p/coins/handbook?decision='.urlencode($name))->assertOk()->assertSee('data-decision-refused', false);
    Livewire::test(ProjectHandbook::class, ['project' => $coins])->call('openDecision', $name)->assertReturned(null);

    Log::shouldHaveReceived('info')->with('board.handbook_refused', ['project' => 'coins', 'path' => $name, 'reason' => 'bad_path'])->twice();
})->with(['../../etc/passwd.md', '-output=x.md', 'sub/dir.md', 'x..md']);

it('refuses a decision that is not in the project, logged as not_found', function () {
    $coins = coinsLike($this);
    Log::spy();

    Livewire::test(ProjectHandbook::class, ['project' => $coins])->call('openDecision', 'ADR-9999-nope.md')->assertReturned(null);

    Log::shouldHaveReceived('info')->with('board.handbook_refused', ['project' => 'coins', 'path' => 'ADR-9999-nope.md', 'reason' => 'not_found']);
});

it('Given the kit path is missing or not a repo, then badges are hidden, the notice shows, board.kit_unreachable is logged, and every section still renders', function (string $kind) {
    $coins = coinsLike($this);
    $path = $kind === 'missing' ? '/nowhere/dev-standards' : sys_get_temp_dir();
    config(['board.kit_path' => $path]);
    Log::spy();

    $this->get('/p/coins/handbook')->assertOk()->assertSee("Kit not found at {$path} — comparison unavailable")->assertSee('data-kit-unreachable', false);
    foreach (ProjectHandbook::SECTIONS as $section) {
        expect(handbookSection($coins, $section))->not->toContain('data-badge="')->not->toContain('Same as kit');
    }
    expect(handbookSection($coins, 'lessons'))->toContain('data-lesson="16"')
        ->and(handbookSection($coins, 'standards'))->toContain('Coins codebase standards');

    Log::shouldHaveReceived('warning')->with('board.kit_unreachable', Mockery::on(fn ($c) => $c['path'] === $path && is_string($c['error'])));
})->with(['missing', 'not a repo']);

it('Given the kit ref does not resolve, then the kit is unreachable too', function () {
    coinsLike($this);
    config(['board.kit_ref' => 'origin/nope']);
    Log::spy();

    $this->get('/p/coins/handbook')->assertOk()->assertSee('comparison unavailable');
    Log::shouldHaveReceived('warning')->with('board.kit_unreachable', Mockery::any());
});

it('Given the handbook loads, then no project\'s or the kit\'s git status --porcelain changes', function () {
    $coins = coinsLike($this);
    $repo = $this->repos['coins'];
    $before = [$repo->git($repo->project, 'status', '--porcelain'), $this->kit->git($this->kit->project, 'status', '--porcelain')];

    $this->get('/p/coins/handbook?decision=ADR-0001-other-rule.md')->assertOk();
    foreach (ProjectHandbook::SECTIONS as $section) {
        handbookSection($coins, $section);
    }

    expect([$repo->git($repo->project, 'status', '--porcelain'), $this->kit->git($this->kit->project, 'status', '--porcelain')])->toBe($before);
});

it('renders Rules and the Runbook with RenderStory\'s safe markdown: raw HTML escaped', function () {
    $coins = coinsLike($this);

    $this->get('/p/coins/handbook')->assertOk()->assertSee('Coins operating manual')
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    expect(handbookSection($coins, 'runbook'))->toContain('Queue stuck')->toContain('Restart the worker.');
});

it('logs board.handbook_viewed with the project and section each time a section loads', function () {
    $coins = coinsLike($this);
    Log::spy();

    $this->get('/p/coins/handbook')->assertOk();
    handbookSection($coins, 'skills');

    Log::shouldHaveReceived('info')->with('board.handbook_viewed', ['project' => 'coins', 'section' => 'rules']);
    Log::shouldHaveReceived('info')->with('board.handbook_viewed', ['project' => 'coins', 'section' => 'skills']);
});

it('refuses a section that does not exist, logged, with no git read', function () {
    $coins = coinsLike($this);
    Log::spy();

    Livewire::test(ProjectHandbook::class, ['project' => $coins])->call('loadSection', 'secrets')->assertReturned(null);

    Log::shouldHaveReceived('info')->with('board.handbook_refused', ['project' => 'coins', 'path' => 'secrets', 'reason' => 'unknown_section']);
});

it('shows a project with no snapshot yet as not read, logged, without reading git', function () {
    $coins = coinsLike($this);
    $coins->update(['sha' => null]);
    Log::spy();

    $this->get('/p/coins/handbook')->assertOk()->assertSee('coins has not been read yet');
    expect(handbookSection($coins, 'lessons'))->toContain('coins has not been read yet');

    Log::shouldHaveReceived('info')->with('board.handbook_refused', ['project' => 'coins', 'path' => null, 'reason' => 'no_snapshot']);
});

it('sends the owner home, logged, when the project leaves the board mid-visit', function () {
    $coins = coinsLike($this);
    $page = Livewire::test(ProjectHandbook::class, ['project' => $coins]);
    $coins->update(['is_enabled' => false]);
    Log::spy();

    $page->call('loadSection', 'lessons')->assertReturned(null)->assertRedirect(route('home'));

    Log::shouldHaveReceived('info')->with('board.handbook_refused', ['project' => 'coins', 'path' => null, 'reason' => 'disabled']);
});

it('404s the handbook of an unknown or disabled project', function () {
    Project::factory()->create(['name' => 'off', 'is_enabled' => false]);

    $this->get('/p/nope/handbook')->assertNotFound();
    $this->get('/p/off/handbook')->assertNotFound();
});

it('reaches the handbook through a Handbook tab on the project page, and back', function () {
    coinsLike($this);

    $this->get('/p/coins')->assertOk()->assertSee(route('projects.handbook', 'coins'), false)->assertSee('Handbook');
    $this->get('/p/coins/handbook')->assertOk()->assertSee(route('projects.show', 'coins'), false)->assertSee('Dashboard');
});

it('says so, logged, when git cannot read the project at its snapshot SHA, and the other sections still load', function () {
    $coins = coinsLike($this);
    $coins->update(['sha' => str_repeat('0', 40)]);
    Log::spy();

    $this->get('/p/coins/handbook')->assertOk()->assertSee('data-read-failed', false);
    expect(handbookSection($coins, 'lessons'))->toContain('Could not read this section of coins');
    Livewire::test(ProjectHandbook::class, ['project' => $coins])->call('openDecision', 'ADR-0001-other-rule.md')->assertReturned(null);

    Log::shouldHaveReceived('warning')->with('board.handbook_read_failed', Mockery::on(fn ($c) => $c['project'] === 'coins' && $c['section'] === 'rules'));
    Log::shouldHaveReceived('warning')->with('board.handbook_read_failed', Mockery::on(fn ($c) => $c['section'] === 'decisions'));
});

it('hides the badges, logged, when the kit disappears after the page loaded', function () {
    $coins = coinsLike($this);
    $page = Livewire::test(ProjectHandbook::class, ['project' => $coins]);
    $this->kit->destroy();
    Log::spy();

    $page->call('loadSection', 'standards')->assertReturned(fn ($html) => ! str_contains($html, 'data-badge=') && str_contains($html, 'Coins codebase standards'));
    $page->call('loadSection', 'skills')->assertReturned(fn ($html) => ! str_contains($html, 'data-badge=') && str_contains($html, 'new-volt-page'));

    Log::shouldHaveReceived('warning')->with('board.kit_unreachable', Mockery::any())->twice();
});

it('refuses a decision of a project with no snapshot yet, logged', function () {
    $coins = coinsLike($this);
    $coins->update(['sha' => null]);
    Log::spy();

    Livewire::test(ProjectHandbook::class, ['project' => $coins])->call('openDecision', 'ADR-0001-other-rule.md')->assertReturned(null);

    Log::shouldHaveReceived('info')->with('board.handbook_refused', ['project' => 'coins', 'path' => 'ADR-0001-other-rule.md', 'reason' => 'no_snapshot']);
});
