<?php

namespace App\Livewire\Board;

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
 * Everything interactive on the page is Alpine: nothing here writes.
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

    /** Rendered story body; null when git could not read it. */
    #[Locked]
    public ?string $body = null;

    /** @var array{visual: bool, chosen: string|null, why: string|null} */
    #[Locked]
    public array $gate = ['visual' => true, 'chosen' => null, 'why' => null];

    /**
     * Load the story from the snapshot and its text from git, or 404.
     */
    public function mount(Project $project, string $storyId, RenderStory $render, ReadMockupGate $gate): void
    {
        abort_unless($project->is_enabled, 404);
        $story = $this->story($project, $storyId);

        $this->project = $project;
        $this->storyId = $storyId;

        $markdown = $render->read($story);
        if ($markdown !== null) {
            $this->body = $render->toHtml($markdown);
            $this->gate = $gate->handle($markdown);
        }

        Log::info('board.story_viewed', ['project' => $project->name, 'story' => $storyId]);
    }

    /**
     * Render the page.
     */
    public function render(): View
    {
        $story = $this->story($this->project, $this->storyId);
        // Depends-on IDs that exist in this project become links; others stay plain text.
        $known = Story::onRef()->where('project_id', $this->project->id)
            ->whereIn('story_id', $story->depends_on)->pluck('story_id')->all();

        return view('livewire.board.story-page', [
            'story' => $story,
            'known' => $known,
        ])->title("{$story->story_id} — {$story->title}");
    }

    /**
     * The ref's row for this story, or 404.
     */
    private function story(Project $project, string $storyId): Story
    {
        $story = Story::onRef()->where('project_id', $project->id)->where('story_id', $storyId)->first();
        abort_if($story === null, 404);
        $story->setRelation('project', $project);

        return $story;
    }
}
