<?php

namespace App\Livewire\Board;

use App\Actions\Board\ParseVersion;
use App\Actions\Board\ReadMockupGate;
use App\Actions\Board\RenderStory;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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
    /** Where off-main versions are looked for first when the ref has no such story. */
    private const KIND_ORDER = [Story::KIND_BRANCH, Story::KIND_WORKTREE, Story::KIND_UNTRACKED];

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
     * Resolve which version to show, load its text from git, or 404.
     */
    public function mount(Project $project, string $storyId, RenderStory $render, ReadMockupGate $gate, ParseVersion $parse): void
    {
        if (! $project->is_enabled) {
            Log::info('board.story_not_found', ['project' => $project->name, 'story' => $storyId, 'reason' => 'project disabled']);
            abort(404);
        }
        $version = $parse->handle(request()->query('v'), ['project' => $project->name, 'story' => $storyId]);

        $story = $this->resolve($project, $storyId, $version);
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
    public function render(): View
    {
        $story = Story::with('project')->findOrFail($this->rowId);
        $all = Story::where('project_id', $this->project->id)->where('story_id', $this->storyId)->get();
        $onRef = $all->first(fn (Story $s) => $s->location_kind === null);
        // Depends-on IDs that exist in this project become links; others stay plain text.
        $known = Story::onRef()->where('project_id', $this->project->id)
            ->whereIn('story_id', $story->depends_on)->pluck('story_id')->all();

        return view('livewire.board.story-page', [
            'story' => $story,
            'known' => $known,
            'onRef' => $onRef,
            'versions' => $this->versions($all, $story, $onRef),
        ])->title("{$story->story_id} — ".($story->title ?? 'not on main'));
    }

    /**
     * The row to show: the requested version, else the ref's row, else the
     * story's first off-main version (a story that exists only on a branch).
     */
    private function resolve(Project $project, string $storyId, ?int $version): Story
    {
        $query = Story::where('project_id', $project->id)->where('story_id', $storyId);

        $story = match (true) {
            $version !== null => (clone $query)->offMain()->find($version),
            default => (clone $query)->onRef()->first()
                ?? (clone $query)->offMain()->get()->sortBy(fn (Story $s) => array_search($s->location_kind, self::KIND_ORDER, true))->first(),
        };
        if ($story === null) {
            Log::info('board.story_not_found', ['project' => $project->name, 'story' => $storyId, 'v' => $version, 'reason' => 'no such story or version']);
            abort(404);
        }

        return $story;
    }

    /**
     * Banner lines for every version other than the one shown: what each says
     * differently from main, and where it lives ("Picked D on branch x — not on main").
     *
     * @param  Collection<int, Story>  $all
     * @return list<array{id: int, text: string, onRef: bool}>
     */
    private function versions(Collection $all, Story $shown, ?Story $onRef): array
    {
        $lines = [];
        foreach ($all as $version) {
            if ($version->id === $shown->id) {
                continue;
            }
            if ($version->location_kind === null) {
                $lines[] = ['id' => $version->id, 'text' => 'The version on main: '.($version->status ?? 'no status'), 'onRef' => true];

                continue;
            }

            $chosen = $version->mockups['chosen'] ?? null;
            $what = match (true) {
                $onRef === null => 'Exists only',
                $chosen !== null && $chosen !== ($onRef->mockups['chosen'] ?? null) => 'Picked '.strtoupper($chosen),
                $version->status !== $onRef->status => ucfirst((string) $version->status),
                default => 'Changed',
            };
            $lines[] = ['id' => $version->id, 'text' => "{$what} {$version->placePhrase()} — not on main", 'onRef' => false];
        }

        return $lines;
    }
}
