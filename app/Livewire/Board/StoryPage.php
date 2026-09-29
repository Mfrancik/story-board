<?php

namespace App\Livewire\Board;

use App\Actions\Board\FindStoryVersion;
use App\Actions\Board\ParseVersion;
use App\Actions\Board\ReadMockupGate;
use App\Actions\Board\RenderStory;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One story, read from its project's ref, above its mockup options (SB-4,
 * mockup B), with a full-screen compare overlay (owner ruling at the gate).
 * `?v=<row>` shows a version that is not on main (SB-5), and every page lists
 * the story's other versions in a banner. Nothing here writes.
 */
#[Layout('layouts.board')]
class StoryPage extends Component
{
    /** The project the story belongs to. */
    #[Locked]
    public Project $project;

    /** The story's ID, e.g. MOB-56. */
    #[Locked]
    public string $storyId;

    /** The `stories` row shown: the ref's, or the requested off-main version. */
    #[Locked]
    public int $rowId;

    /** Rendered story body; null when it is not in git or git could not read it. */
    #[Locked]
    public ?string $body = null;

    /** @var array{visual: bool, chosen: string|null, why: string|null} */
    #[Locked]
    public array $gate = ['visual' => true, 'chosen' => null, 'why' => null];

    /**
     * Resolve which version to show, load its text from git, or 404. An unknown or
     * disabled project never gets here: the route's EnsureProjectIsShown refuses it (SB-7).
     */
    public function mount(Project $project, string $storyId, RenderStory $render, ReadMockupGate $gate, ParseVersion $parse, FindStoryVersion $find): void
    {
        $version = $parse->handle(request()->query('v'), ['project' => $project->name, 'story' => $storyId]);

        $story = $this->resolve($project, $storyId, $version, $find);
        $this->project = $project;
        $this->storyId = $storyId;
        $this->rowId = $story->id;

        $markdown = $render->read($story);
        if ($markdown !== null) {
            $this->body = $render->toHtml($markdown);
            $this->gate = $gate->handle($markdown);
        }

        Log::info('board.story_viewed', ['project' => $project->name, 'story' => $storyId, 'version' => $story->location]);
    }

    /**
     * Render the page.
     */
    public function render(FindStoryVersion $find): View
    {
        $story = Story::with('project')->findOrFail($this->rowId);
        $all = $find->all($this->project, $this->storyId);
        $onRef = $all->first(fn (Story $s) => $s->location_kind === null);
        // Depends-on IDs that exist in this project become links; others stay plain text.
        $known = Story::onRef()->where('project_id', $this->project->id)
            ->whereIn('story_id', $story->depends_on)->pluck('story_id')->all();

        return view('livewire.board.story-page', [
            'story' => $story,
            'known' => $known,
            'onRef' => $onRef,
            'versions' => $find->others($all, $story),
        ])->title("{$story->story_id} — ".($story->title ?? 'not on main'));
    }

    /**
     * The row to show, or a logged 404 when the story or version does not exist.
     */
    private function resolve(Project $project, string $storyId, ?int $version, FindStoryVersion $find): Story
    {
        $story = $find->handle($project, $storyId, $version);
        if ($story === null) {
            Log::info('board.story_not_found', ['project' => $project->name, 'story' => $storyId, 'v' => $version, 'reason' => 'no such story or version']);
            abort(404);
        }

        return $story;
    }
}
