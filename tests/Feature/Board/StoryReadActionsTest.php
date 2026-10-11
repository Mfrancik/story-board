<?php

use App\Actions\Board\FindStoryVersion;
use App\Actions\Board\RenderStory;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitFixture;

/**
 * RD-1: RenderStory and FindStoryVersion::handle() called directly, so their
 * coverage does not depend on the story page and story modal RD-9 replaces. No
 * page is loaded here.
 */

/**
 * One story row of `$project` with the given location.
 *
 * @param  array<string, mixed>  $attributes
 */
function rdVersion(Project $project, string $storyId, ?string $kind, array $attributes = []): Story
{
    return Story::factory()->for($project)->create([
        'story_id' => $storyId,
        'path' => "stories/demo/{$storyId}-story.md",
        'location_kind' => $kind,
        'location' => $kind === null ? null : "{$kind} somewhere",
        ...$attributes,
    ]);
}

it('renders a story read from git at its row\'s commit as safe HTML', function () {
    $fixture = new GitFixture;
    $fixture->story('RS-1', 'draft', 'demo', "<script>alert(1)</script>\n\n[bad](javascript:alert(1)) and **bold**\n");
    $fixture->commitAndPush();
    $project = Project::factory()->create(['name' => 'rs', 'path' => $fixture->project, 'indexed_at' => now(), 'refresh_attempted_at' => now()]);
    $this->artisan('board:refresh')->assertSuccessful();
    $story = Story::onRef()->where('story_id', 'RS-1')->sole();

    $html = app(RenderStory::class)->handle($story);

    expect($html)->toContain('<h1>RS-1 — Story RS-1</h1>')
        ->and($html)->toContain('<strong>bold</strong>')
        // Raw HTML is escaped and unsafe links dropped: story files come from other repos.
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('javascript:');
    $fixture->destroy();
});

it('renders nothing, and logs why, when git cannot read the story', function () {
    Log::spy();
    $project = Project::factory()->create(['name' => 'gone', 'path' => sys_get_temp_dir().'/story-board-rd1-no-repo']);
    $story = rdVersion($project, 'RS-2', null);

    expect(app(RenderStory::class)->handle($story))->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($e, $c = []) => $e === 'board.story_read_failed'
        && $c['project'] === 'gone' && $c['story'] === 'RS-2')->once();
});

it('reads no untracked story from git', function () {
    $project = Project::factory()->create();
    $story = rdVersion($project, 'RS-3', Story::KIND_UNTRACKED);

    expect(app(RenderStory::class)->read($story))->toBeNull();
});

it('finds the ref\'s row first, the requested off-main version when asked, and null for one that is not there', function () {
    $project = Project::factory()->create();
    $onRef = rdVersion($project, 'FV-1', null);
    $branch = rdVersion($project, 'FV-1', Story::KIND_BRANCH);
    $elsewhere = rdVersion(Project::factory()->create(), 'FV-1', Story::KIND_BRANCH);
    $find = app(FindStoryVersion::class);

    expect($find->handle($project, 'FV-1')?->id)->toBe($onRef->id)
        ->and($find->handle($project, 'FV-1', $branch->id)?->id)->toBe($branch->id)
        // A version id is honoured only as an off-main version of this story in this project.
        ->and($find->handle($project, 'FV-1', $onRef->id))->toBeNull()
        ->and($find->handle($project, 'FV-1', $elsewhere->id))->toBeNull()
        ->and($find->handle($project, 'FV-9'))->toBeNull();
});

it('falls back to an off-main version, a branch before a worktree before an untracked file, when the ref has none', function () {
    $project = Project::factory()->create();
    rdVersion($project, 'FV-2', Story::KIND_UNTRACKED);
    $worktree = rdVersion($project, 'FV-2', Story::KIND_WORKTREE);
    $find = app(FindStoryVersion::class);

    expect($find->handle($project, 'FV-2')?->id)->toBe($worktree->id);

    $branch = rdVersion($project, 'FV-2', Story::KIND_BRANCH);
    expect($find->handle($project, 'FV-2')?->id)->toBe($branch->id);
});
