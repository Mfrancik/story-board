<?php

use App\Actions\Board\ReadGitActivity;
use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * RD-12 acceptance criteria: the project overview's git activity tiles — commits per day over 14 days, Last edits
 * with +/− counts and story links, the most-edited code files today and the working copy's uncommitted-path count —
 * read through new fixed, read-only GitReader forms. Every repo is a throwaway GitFixture; the clock is frozen.
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-11T15:00:00Z'));
    $this->fixture = new GitFixture;
    $this->coins = Project::factory()->create([
        'name' => 'coins', 'path' => $this->fixture->project, 'ref' => 'origin/main',
        'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK,
    ]);
});

afterEach(function () {
    $this->fixture->destroy();
});

/**
 * One tile's markup by its `data-tile` name, up to the end of its section.
 */
function activityTile(string $html, string $tile): string
{
    preg_match('#<section\b[^>]*data-tile="'.$tile.'".*?</section>#s', $html, $m);

    return $m[0] ?? '';
}

/**
 * Let the registered clone learn origin's new commits, as a refresh would.
 */
function activityFetch(GitFixture $fixture): void
{
    $fixture->git($fixture->project, 'fetch', '--quiet');
}

it('Given a fixture repo with commits over 20 days, then commits-per-day covers exactly the last 14 days with correct counts, zero-filled', function () {
    // Days ago => commits that day. Day 5 has none (zero-filled); days 14–19 are outside the window.
    $plan = [19 => 2, 16 => 1, 14 => 3, 13 => 2, 12 => 1, 7 => 4, 5 => 0, 1 => 1, 0 => 3];
    $n = 0;
    foreach ($plan as $ago => $count) {
        for ($i = 0; $i < $count; $i++) {
            // 00:10 + i hours: the window's edges are midnights, so the earliest commit of a day is the one that tests them.
            $at = now()->subDays($ago)->startOfDay()->addMinutes(10)->addHours($i);
            $this->fixture->write('app/F'.$n++.'.php', 'x')->commitAt("chore: commit {$n}", $at);
        }
        // One second before the window opens: 14 days ago at 23:59:59 must not count. Committed in date order,
        // as a real history is: `git log --since` stops walking at a commit older than the window.
        if ($ago === 14) {
            $this->fixture->write('app/Edge.php', 'x')->commitAt('chore: edge', now()->subDays(14)->endOfDay());
        }
    }
    activityFetch($this->fixture);

    $commits = app(ReadGitActivity::class)->handle($this->coins)['commits'];

    $expected = [];
    for ($ago = 13; $ago >= 0; $ago--) {
        $expected[now()->subDays($ago)->toDateString()] = $plan[$ago] ?? 0;
    }
    expect($commits['days'])->toHaveCount(14)
        ->and(array_combine(array_map(fn ($d) => $d['day']->toDateString(), $commits['days']), array_column($commits['days'], 'count')))->toBe($expected)
        ->and($commits['total'])->toBe(array_sum($expected))
        ->and($commits['today'])->toBe(3);

    $html = activityTile($this->get('/p/coins')->assertOk()->getContent(), 'commits');
    expect($html)->toContain('data-commits-chart')
        ->and(substr_count($html, '<rect'))->toBe(count(array_filter($expected)))
        ->and($html)->toMatch('/data-commits-today[^>]*>.*?3/s')
        ->and($html)->toMatch('/data-commits-total[^>]*>\s*'.array_sum($expected).'\s*</');
});

it('Given commits with known line changes, then Last edits shows each commit\'s +/− counts and links a subject\'s story ID to its story page', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'RD-5', 'status' => 'built']);
    $this->fixture->write('app/Widget.php', "a\nb\nc\n")->commitAt('feat(RD-5): add the widget', now()->subHours(3));
    $this->fixture->write('app/Widget.php', "a\nB\nc\nd\n")->commitAt('fix: tidy the widget', now()->subHours(2));
    // An ID with no story in the snapshot has no page to open: shown, not linked.
    $this->fixture->write('app/Other.php', "1\n")->commitAt('docs(ZZ-9): note', now()->subHour());
    activityFetch($this->fixture);

    $edits = app(ReadGitActivity::class)->handle($this->coins)['edits'];

    expect(array_column($edits, 'subject'))->toBe(['note', 'tidy the widget', 'add the widget', 'kit'])
        ->and(array_map(fn ($e) => [$e['added'], $e['removed']], array_slice($edits, 0, 3)))->toBe([[1, 0], [2, 1], [3, 0]])
        ->and(array_column(array_slice($edits, 0, 3), 'story_id'))->toBe(['ZZ-9', null, 'RD-5'])
        ->and(array_column(array_slice($edits, 0, 3), 'linked'))->toBe([false, false, true]);

    $html = activityTile($this->get('/p/coins')->assertOk()->getContent(), 'edits');
    $page = route('stories.show', ['project' => 'coins', 'storyId' => 'RD-5']);
    expect($html)->toContain('href="'.$page.'"')
        ->and($html)->not->toContain('storyId=ZZ-9')->not->toContain('/s/ZZ-9')
        ->and($html)->toMatch('/data-edit-added[^>]*>\+3</')
        ->and($html)->toMatch('/data-edit-removed[^>]*>−1</')
        ->and($html)->toContain('3h ago');
});

it('Given commits today touching `app/X.php` three times and `docs/Y.md` five times, then most-edited lists `app/X.php` and not `docs/Y.md`', function () {
    // Yesterday's edits are not today's (committed first: a real history is in date order).
    $this->fixture->write('app/Old.php', 'x')->commitAt('feat: yesterday', now()->subDay());
    for ($i = 1; $i <= 5; $i++) {
        $this->fixture->write('docs/Y.md', "v{$i}")->write('stories/demo/S-1.md', "v{$i}");
        if ($i <= 3) {
            $this->fixture->write('app/X.php', "v{$i}");
        }
        $this->fixture->commitAt("docs: pass {$i}", now()->subHours(6 - $i));
    }
    activityFetch($this->fixture);

    $files = app(ReadGitActivity::class)->handle($this->coins)['files'];

    expect($files)->toBe([['path' => 'app/X.php', 'commits' => 3]]);

    $html = activityTile($this->get('/p/coins')->assertOk()->getContent(), 'files');
    expect($html)->toContain('app/X.php')->toContain('3 commits')
        ->not->toContain('docs/Y.md')->not->toContain('stories/demo')->not->toContain('app/Old.php');
});

it('Given a working copy with uncommitted paths, then the count is shown; given a clean one, then it reads "clean"', function () {
    $html = activityTile($this->get('/p/coins')->assertOk()->getContent(), 'edits');
    expect($html)->toMatch('/data-uncommitted="0"[^>]*>.*?clean/s');

    $this->fixture->untracked($this->fixture->project, 'notes.txt', 'x')
        ->untracked($this->fixture->project, 'stories/README.md', 'changed')
        ->untracked($this->fixture->project, 'kept.txt', 'x');
    // A staged rename is one path, though porcelain -z prints its old name as a second entry.
    $this->fixture->git($this->fixture->project, 'add', 'kept.txt');
    $this->fixture->git($this->fixture->project, 'commit', '--quiet', '-m', 'local');
    $this->fixture->git($this->fixture->project, 'mv', 'kept.txt', 'moved.txt');
    // A fresh read: the count is cached with the project's snapshot.
    cache()->flush();

    expect(app(ReadGitActivity::class)->handle($this->coins)['uncommitted'])->toBe(3);
    $html = activityTile($this->get('/p/coins')->assertOk()->getContent(), 'edits');
    expect($html)->toMatch('/data-uncommitted="3"[^>]*>.*?3 uncommitted paths/s');
});

it('Given `GitReader` is asked for any other `log`/`status` form (patch, `-p`, free-text dates), then it refuses and logs', function (array $args) {
    Log::spy();

    expect(fn () => app(GitReader::class)->run('/tmp', $args))->toThrow(GitReaderException::class, 'not allowed');

    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.git_refused' && $c['subcommand'] === $args[0])->once();
})->with([
    'shortstat with a patch' => [['log', GitReader::STAT_FORMAT, '--shortstat', '-p', '--no-textconv', '--max-count=7', 'origin/main', '--']],
    'shortstat letting textconv run' => [['log', GitReader::STAT_FORMAT, '--shortstat', '--no-ext-diff', '--textconv', '--max-count=7', 'origin/main', '--']],
    'shortstat, unbounded count' => [['log', GitReader::STAT_FORMAT, '--shortstat', '--no-ext-diff', '--no-textconv', '--max-count=100000', 'origin/main', '--']],
    'shortstat with a path' => [['log', GitReader::STAT_FORMAT, '--shortstat', '--no-ext-diff', '--no-textconv', '--max-count=7', 'origin/main', '--', '.env']],
    'name-only with a free-text date' => [['log', '-z', GitReader::FILES_FORMAT, '--name-only', '--no-renames', '--since=yesterday', 'origin/main', '--']],
    'name-only as a patch' => [['log', '-z', GitReader::FILES_FORMAT, '--patch', '--no-renames', '--since=@1700000000', 'origin/main', '--']],
    'name-only on every ref' => [['log', '-z', GitReader::FILES_FORMAT, '--name-only', '--no-renames', '--since=@1700000000', '--all', '--']],
    'status showing the diff' => [['status', '--porcelain', '-v']],
    'status --verbose' => [['status', '--porcelain', '-z', '--verbose']],
    'status writing a file' => [['status', '--porcelain', '--output=/tmp/x']],
]);

it('Given git fails for the project, then each activity tile shows its error state, `board.git_activity_failed` is logged, and the other Overview tiles still render', function () {
    Log::spy();
    Story::factory()->for($this->coins)->create(['story_id' => 'CO-1', 'status' => 'built']);
    $this->coins->update(['path' => $this->fixture->root.'/gone']);

    $html = $this->get('/p/coins')->assertOk()->getContent();

    foreach (['commits', 'edits', 'files'] as $tile) {
        expect(activityTile($html, $tile))->toContain('data-activity-state="error"')->toContain('role="alert"');
    }
    expect(activityTile($html, 'edits'))->toContain('data-uncommitted="error"')
        ->and(activityTile($html, 'progress'))->toContain('data-progress-count="built"')
        ->and($html)->toContain('data-tile="about"')->toContain('data-tile="initiatives"');
    foreach (['commits', 'edits', 'files', 'uncommitted'] as $read) {
        Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.git_activity_failed'
            && $c['project'] === 'coins' && $c['read'] === $read && $c['error'] !== '')->once();
    }
});

it('caches the activity as plain values, so a second read from a serialising store comes back whole', function () {
    // As the database store does in production: serialise values and unserialise no classes (config/cache.php).
    config(['cache.stores.array.serialize' => true]);
    app('cache')->forgetDriver('array');
    $this->fixture->write('app/A.php', "a\n")->commitAt('feat(RD-5): one', now()->subHour());
    activityFetch($this->fixture);

    app(ReadGitActivity::class)->handle($this->coins);
    $cached = app(ReadGitActivity::class)->handle($this->coins);

    expect($cached['edits'][0]['at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($cached['edits'][0]['at']->timestamp)->toBe(now()->subHour()->timestamp)
        ->and($cached['commits']['today'])->toBe(1)
        ->and($cached['files'])->toBe([['path' => 'app/A.php', 'commits' => 1]]);
});
