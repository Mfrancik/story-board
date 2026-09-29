<?php

use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * SB-14 in a real browser: the Handbook tab on the project page, a section filling in the first time
 * its tab opens, standards badged against a fixture kit, the Decisions list paged by 50 and narrowed
 * by the filter, a decision opening in the modal (and `?decision=`) and closing on Escape, and an
 * empty project at 375px with its empty states and no sideways scroll.
 */
beforeEach(function () {
    Queue::fake();
    $this->kit = new GitFixture;
    $this->kit->write('docs/standards/codebase-standards.md', "# Kit codebase\n")
        ->write('docs/standards/design-standards.md', "# Kit design\n")
        ->commitAndPush('kit')->syncProject();
    config(['board.kit_path' => $this->kit->project, 'board.kit_ref' => 'origin/main']);

    $this->repo = new GitFixture;
    $this->repo->write('CLAUDE.md', "# Coins manual\n")
        ->write('docs/LESSONS.md', "# Lessons\n\n## L-1 — 2026-09-01 — First\nScope: PROJECT-ONLY\n\n## L-2 — 2026-09-02 — Second\nSymptom: the body.\n")
        ->write('docs/standards/codebase-standards.md', "# Coins codebase\n")
        ->write('docs/standards/design-standards.md', "# Kit design\n");
    foreach (range(1, 60) as $i) {
        $this->repo->write(sprintf('docs/decisions/2026-09-%02d-%s.md', $i % 28 + 1, $i % 20 === 0 ? "bullion-{$i}" : "rule-{$i}"), "# Decision {$i}\n\nBody {$i}.\n");
    }
    $this->repo->commitAndPush()->syncProject();

    $fresh = ['indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK];
    Project::factory()->create(['name' => 'coins', 'path' => $this->repo->project, 'sha' => $this->repo->originSha(), ...$fresh]);
    Project::factory()->create(['name' => 'asset-track', 'path' => $this->kit->project, 'sha' => $this->kit->originSha(), ...$fresh]);
});

afterEach(function () {
    $this->repo->destroy();
    $this->kit->destroy();
});

it('opens the handbook from the project page and fills a tab the first time it opens', function () {
    $page = visit('/p/coins')->resize(1280, 900);

    $page->click('[data-project-tab="handbook"]')
        ->assertPathIs('/p/coins/handbook')
        ->assertSeeIn('[data-section="rules"]', 'Coins manual')
        ->assertMissing('[data-lesson]')
        ->click('[data-handbook-tab="lessons"]')
        ->assertVisible('[data-lesson="2"]')
        ->assertScript("[...document.querySelectorAll('[data-lesson]')].map(e => e.dataset.lesson).join()", '2,1')
        ->click('[data-lesson="2"] button')
        ->assertSeeIn('[data-lesson="2"]', 'the body.')
        ->click('[data-handbook-tab="standards"]')
        ->assertVisible('[data-standard="codebase-standards.md"][data-badge="changed"]')
        ->assertSeeIn('[data-section="standards"]', 'Changed in this project')
        ->assertNoJavaScriptErrors();
});

it('pages decisions by 50, narrows them by file name, and opens one in the modal', function () {
    $rows = "document.querySelectorAll('[data-decision-row]').length";
    $page = visit('/p/coins/handbook')->resize(1280, 900);

    $page->click('[data-handbook-tab="decisions"]')
        ->assertScript($rows, 50)
        ->click('[data-decisions-next]')
        ->assertScript($rows, 10)
        ->type('[data-decision-filter]', 'bullion')
        ->assertScript($rows, 3)
        ->click('[data-decision-row="2026-09-21-bullion-20.md"]')
        ->assertVisible('[role="dialog"]')
        ->assertSeeIn('[role="dialog"]', 'Body')
        ->assertScript("new URL(location.href).searchParams.get('decision').includes('bullion')", true)
        ->keys('[role="dialog"]', 'Escape')
        ->assertMissing('[role="dialog"]')
        ->assertScript("new URL(location.href).searchParams.has('decision')", false)
        ->assertNoJavaScriptErrors();
});

it('shows asset-track\'s empty states at 375px with no sideways scroll', function () {
    $page = visit('/p/asset-track/handbook')->resize(375, 800);

    $page->assertSee('asset-track has no')
        ->click('[data-handbook-tab="lessons"]')
        ->assertVisible('[data-empty="docs/LESSONS.md"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
