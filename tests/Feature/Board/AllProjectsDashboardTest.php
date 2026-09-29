<?php

use App\Livewire\Board\Home;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * SB-9 acceptance criteria: `/` as the all-projects dashboard. The fixture mirrors the
 * dev data checked on 2026-09-29 — 30 approved (coins 29, client-dashboard 1), rent-track
 * `{draft: 11, in: 1, done: 3}` all with parse errors, off-main rows — plus a disabled project
 * whose stories must never count.
 */
beforeEach(function () {
    Bus::fake();
    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK, 'sha' => str_repeat('a', 40)];
    $this->coins = Project::factory()->create(['name' => 'coins', ...$fresh]);
    $this->cd = Project::factory()->create(['name' => 'client-dashboard', ...$fresh]);
    $this->asset = Project::factory()->create(['name' => 'asset-track', ...$fresh]);
    $this->rent = Project::factory()->create(['name' => 'rent-track', ...$fresh]);
    $this->acme = Project::factory()->disabled()->create(['name' => 'acme-site', ...$fresh]);

    // Ready to build: coins CO-1..29 dated after client-dashboard's SS-12, the oldest of all.
    foreach (range(1, 29) as $n) {
        Story::factory()->for($this->coins)->create(['story_id' => "CO-{$n}", 'status' => 'approved',
            'dated_on' => Carbon::parse('2026-01-01')->addDays($n), 'path' => sprintf('stories/demo/CO-%02d.md', $n)]);
    }
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-12', 'status' => 'approved', 'dated_on' => '2025-12-31']);
    Story::factory()->for($this->asset)->count(3)->sequence(fn ($s) => ['story_id' => 'CV-'.($s->index + 1), 'status' => 'built'])->create();

    $oov = ['status is not in stories/README.md §Status'];
    Story::factory()->for($this->rent)->count(11)->sequence(fn ($s) => ['story_id' => 'MT-'.($s->index + 1), 'status' => 'draft'])->create(['parse_errors' => $oov]);
    Story::factory()->for($this->rent)->create(['story_id' => 'MT-20', 'status' => 'in', 'parse_errors' => $oov]);
    Story::factory()->for($this->rent)->count(3)->sequence(fn ($s) => ['story_id' => 'MT-'.($s->index + 30), 'status' => 'done'])->create(['parse_errors' => $oov]);

    // Not on main: two on coins, one on client-dashboard.
    Story::factory()->for($this->coins)->count(2)->sequence(fn ($s) => ['story_id' => 'CO-'.($s->index + 90)])
        ->create(['location_kind' => Story::KIND_BRANCH, 'location' => 'branch feat/x', 'branch' => 'feat/x']);
    Story::factory()->for($this->cd)->create(['story_id' => 'CT-1', 'location_kind' => Story::KIND_WORKTREE, 'location' => 'worktree /w', 'branch' => 'feat/CT-1']);

    // The disabled project: approved, draft, built and off-main rows that must count nowhere.
    Story::factory()->for($this->acme)->count(5)->sequence(fn ($s) => ['story_id' => 'AC-'.($s->index + 1)])->create(['status' => 'approved']);
    Story::factory()->for($this->acme)->create(['story_id' => 'AC-10', 'status' => 'draft']);
    Story::factory()->for($this->acme)->create(['story_id' => 'AC-11', 'status' => 'built']);
    Story::factory()->for($this->acme)->create(['story_id' => 'AC-12', 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch feat/y', 'branch' => 'feat/y']);
});

/**
 * One What needs me card's markup, so row assertions stay inside that card.
 */
function card(string $html, string $key): string
{
    preg_match('/<section\b[^>]*data-card="'.$key.'".*?<\/section>/s', $html, $m);

    return $m[0] ?? '';
}

/**
 * One project tile's markup.
 */
function tile(string $html, string $name): string
{
    preg_match('/<a\b[^>]*data-project-card="'.preg_quote($name, '/').'".*?<\/a>/s', $html, $m);

    return $m[0] ?? '';
}

/**
 * The text of one In flight figure.
 */
function figure(string $html, string $key): string
{
    preg_match('/data-figure="'.$key.'"[^>]*>\s*([^<]*)</', $html, $m);

    return trim($m[1] ?? '');
}

/**
 * The story IDs of the rows in a piece of markup, in page order.
 *
 * @return list<string>
 */
function rowIds(string $html): array
{
    preg_match_all('/data-row="([^"]+)"/', $html, $m);

    return $m[1];
}

it('shows "What needs me" as the first heading in <main> when / loads with the dev data', function () {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/<main\b.*?<\/main>/s', $html, $main);
    preg_match('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/s', $main[0] ?? '', $heading);

    expect(trim(strip_tags($heading[1] ?? '')))->toBe('What needs me');
});

it('shows 30 in Ready to build, the 5 oldest first by dated_on, and Show all 30 reveals the rest', function () {
    $component = Livewire::test(Home::class);
    $build = card($component->html(), 'build');

    expect($build)->toContain('data-count="30"')
        ->and(rowIds($build))->toBe(['SS-12', 'CO-1', 'CO-2', 'CO-3', 'CO-4'])
        ->and($build)->toContain('Show all 30');

    $all = card($component->call('showAll', 'build')->html(), 'build');

    expect(rowIds($all))->toHaveCount(30)
        ->and(rowIds($all)[29])->toBe('CO-29')
        ->and($all)->not->toContain('Show all');
});

it('shows "Nothing to approve" instead of an empty list when there are no drafts to approve', function () {
    Story::query()->where('status', 'draft')->delete();

    $approval = card(Livewire::test(Home::class)->html(), 'approval');

    expect($approval)->toContain('Nothing to approve')
        ->and($approval)->toContain('data-count="0"')
        ->and(rowIds($approval))->toBe([]);
});

it('tones rent-track\'s in and done segments as danger and warns of 15 parse errors on its tile', function () {
    $rent = tile($this->get('/')->getContent(), 'rent-track');

    expect($rent)->toMatch('/data-segment="in"[^>]*data-tone="danger"/')
        ->and($rent)->toMatch('/data-segment="done"[^>]*data-tone="danger"/')
        ->and($rent)->toMatch('/data-segment="draft"[^>]*data-tone="draft"/')
        ->and($rent)->toContain('data-parse-errors="15"')
        ->and($rent)->toContain('15 stories have parse errors');
});

it('says an unreachable project is unreachable with the first line of last_error, and counts it as not ok', function () {
    $this->asset->update(['state' => Project::STATE_UNREACHABLE, 'last_error' => "not a git repository: /gone\nfatal: second line"]);

    $html = $this->get('/')->getContent();
    $asset = tile($html, 'asset-track');

    expect($asset)->toContain('Unreachable')
        ->and($asset)->toContain('not a git repository: /gone')
        ->and($asset)->not->toContain('second line')
        ->and(figure($html, 'not-ok'))->toBe('1');
});

it('gives a disabled project no tile and counts none of its stories anywhere on the page', function () {
    $html = $this->get('/')->getContent();

    expect($html)->not->toContain('data-project-card="acme-site"')
        ->and(rowIds($html))->not->toContain('AC-1')
        ->and($html)->not->toContain('AC-10')
        ->and(figure($html, 'approved'))->toBe('30')
        ->and(figure($html, 'offmain'))->toBe('3')
        ->and(card($html, 'build'))->toContain('data-count="30"')
        ->and(card($html, 'approval'))->toContain('data-count="11"')
        // The collapsible sections' counts: 3 built (asset-track), 3 off main.
        ->and($html)->toMatch('/data-section="built".*?tabular-nums[^>]*>3</s')
        ->and($html)->toMatch('/data-section="offmain".*?tabular-nums[^>]*>3</s');
});

it('makes every row in every card open the SB-8 modal for its story', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'draft',
        'mockups' => ['dir' => 'docs/mockups/MOB-65', 'options' => ['a', 'b'], 'chosen' => null]]);

    $html = Livewire::test(Home::class)->html();

    foreach (['pick', 'approval', 'build'] as $key) {
        $rows = card($html, $key);
        expect(rowIds($rows))->not->toBeEmpty();
        // Every row is a button that asks the modal for `<project>/<ID>`.
        preg_match_all('/<button\b[^>]*data-row="[^"]+"[^>]*>/', $rows, $buttons);
        expect($buttons[0])->toHaveCount(count(rowIds($rows)));
        foreach ($buttons[0] as $button) {
            expect($button)->toContain('data-story-link=')->toContain("\$dispatch('board-story'");
        }
    }
    expect(card($html, 'pick'))->toContain('data-story-link="coins/MOB-65"')
        ->and(card($html, 'build'))->toContain('data-story-link="client-dashboard/SS-12"');
});

it('runs the same number of queries whatever the number of projects', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $before = $count();

    foreach (range(1, 4) as $i) {
        $p = Project::factory()->create(['name' => "extra-{$i}", 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_STALE]);
        Story::factory()->for($p)->count(3)->sequence(fn ($s) => ['story_id' => 'EX-'.($i * 10 + $s->index), 'status' => ['draft', 'approved', 'built'][$s->index]])->create(['parse_errors' => ['x']]);
        Story::factory()->for($p)->create(['story_id' => 'EX-'.($i * 10 + 5), 'location_kind' => Story::KIND_BRANCH, 'location' => 'branch b', 'branch' => 'b']);
    }

    expect($count())->toBe($before);
});

it('shows the not-on-main count, story count and refresh age on each tile and links it to its project page', function () {
    $this->cd->update(['indexed_at' => now()->subMinutes(3)]);

    $html = $this->get('/')->getContent();

    expect(tile($html, 'coins'))->toContain('2 not on main')
        ->and(tile($html, 'coins'))->toContain('data-story-count="29"')
        ->and(tile($html, 'client-dashboard'))->toContain('1 not on main')
        ->and(tile($html, 'client-dashboard'))->toContain('3 minutes ago')
        ->and(tile($html, 'coins'))->toContain('href="'.route('projects.show', ['project' => 'coins']).'"');
});

it('shows the three In flight figures across enabled projects', function () {
    $html = $this->get('/')->getContent();

    expect(figure($html, 'approved'))->toBe('30')
        ->and(figure($html, 'offmain'))->toBe('3')
        ->and(figure($html, 'not-ok'))->toBe('0');
});

it('shows a designed empty state on a tile whose project has no stories', function () {
    Project::factory()->create(['name' => 'empty-one', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);

    expect(tile($this->get('/')->getContent(), 'empty-one'))->toContain('No stories on origin/main yet');
});

it('says in the header how long ago the board was refreshed, next to Refresh', function () {
    $this->coins->update(['indexed_at' => now()->subMinutes(7)]);

    $this->get('/')->assertSeeInOrder(['Refreshed 7 minutes ago', 'Refresh']);
});

it('drops the project dropdown for the sidebar and keeps the initiative filter and search', function () {
    $this->get('/')->assertDontSee('id="f-project"', false)
        ->assertSee('id="f-initiative"', false)
        ->assertSee('id="f-q"', false);
});

it('does not render the Live now slot until SB-11 ships', function () {
    $this->get('/')->assertDontSee('Live now');
});

it('keeps /p/{project} working as the dashboard pinned to that project', function () {
    $html = $this->get('/p/coins')->assertOk()->getContent();

    expect(card($html, 'build'))->toContain('data-count="29"')
        ->and(rowIds(card($html, 'build')))->not->toContain('SS-12')
        ->and(figure($html, 'approved'))->toBe('29')
        ->and($html)->toContain('data-project-card="coins"')
        ->and($html)->not->toContain('data-project-card="rent-track"');
});
