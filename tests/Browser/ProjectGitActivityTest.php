<?php

use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * RD-12 in a real browser: the overview's git activity tiles — Commits per day, Last edits with the working copy's
 * count, Most-edited code today — render from a fixture repo, and at 375 px the chart and lists fit with no
 * sideways scroll, long file paths and subjects included.
 */
beforeEach(function () {
    Queue::fake();
    $this->fixture = new GitFixture;
    $long = 'app/Http/Controllers/Collections/Personal/Showcase/VeryLongControllerNameThatWouldOverflowAPhone.php';
    $this->fixture->write($long, "<?php\n")->commitAt('feat(CO-1): a subject long enough to run well past the width of a phone screen in one line', now()->subMinutes(2));
    $this->fixture->write($long, "<?php\n// two\n")->write('app/Short.php', "x\n")->commitAt('fix(CO-1): second pass', now()->subMinute());
    $this->fixture->git($this->fixture->project, 'fetch', '--quiet');
    $this->fixture->untracked($this->fixture->project, 'scratch.txt', 'x');

    $coins = Project::factory()->create([
        'name' => 'coins', 'path' => $this->fixture->project, 'ref' => 'origin/main',
        'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK,
    ]);
    Story::factory()->for($coins)->create(['story_id' => 'CO-1', 'status' => 'built']);
});

afterEach(function () {
    $this->fixture->destroy();
});

it('shows the three activity tiles with today\'s numbers and opens a commit\'s story from Last edits', function () {
    visit('/p/coins')->resize(1280, 900)
        ->assertVisible('[data-tile="commits"] [data-commits-chart]')
        ->assertSeeIn('[data-commits-today]', '3')
        ->assertSeeIn('[data-tile="edits"]', 'second pass')
        ->assertSeeIn('[data-uncommitted]', '1 uncommitted path')
        ->assertSeeIn('[data-tile="files"]', 'VeryLongControllerNameThatWouldOverflowAPhone.php')
        ->assertNoJavaScriptErrors()
        ->click('[data-tile="edits"] li:first-child [data-edit-story="CO-1"]')
        ->assertPathIs('/p/coins/s/CO-1');
});

it('Given a 375px viewport, then the chart and lists fit with no sideways scroll', function () {
    visit('/p/coins')->resize(375, 812)
        ->assertVisible('[data-commits-chart]')
        ->assertVisible('[data-tile="edits"] li:first-child [data-edit-added]')
        ->assertVisible('[data-tile="files"] li:first-child')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript("[...document.querySelectorAll('[data-tile=\"commits\"], [data-tile=\"edits\"], [data-tile=\"files\"]')].every(t => t.scrollWidth <= t.clientWidth)", true)
        ->assertNoJavaScriptErrors();
});
