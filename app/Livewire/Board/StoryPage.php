<?php

namespace App\Livewire\Board;

use App\Actions\Board\FindStoryVersion;
use App\Actions\Board\ParseVersion;
use App\Actions\Board\PickMockup;
use App\Actions\Board\ReadCurrentVersion;
use App\Actions\Board\ReadMockupGate;
use App\Actions\Board\ReadMockupSets;
use App\Actions\Board\ReadStoryOutline;
use App\Actions\Board\RenderStory;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One story, short first (RD-3, docs/mockups/RD-3/option-a.html), in the RD-2
 * shell: its tags and title, one summary line, the one action it is waiting
 * for (a mockup pick), its mockups in one sandboxed frame, and every other
 * detail folded below. Replaces SB-4's long page at the same route. `?v=<row>`
 * shows a version that is not on main (SB-5). The only write is the pick,
 * through PickMockup → StoryPickWriter; option buttons and Details are pure UI.
 */
#[Layout('layouts.shell')]
class StoryPage extends Component
{
    use GuardsProjectPage;

    /*
     * Locked: set by the server from the route and git. `body` and the criteria are
     * printed as HTML, so the browser must never be able to set them.
     */

    /** The story's ID, e.g. MOB-56. */
    #[Locked]
    public string $storyId = '';

    /** The `stories` row shown: the ref's, or the requested off-main version. */
    #[Locked]
    public int $rowId = 0;

    /** Rendered story body; null when it is not in git or git could not read it. */
    #[Locked]
    public ?string $body = null;

    /** Lines in the story file, for the Full story text header; null when it was not read. */
    #[Locked]
    public ?int $lines = null;

    /** @var array{visual: bool, chosen: string|null, why: string|null} the gate section, quoted */
    #[Locked]
    public array $gate = ['visual' => true, 'chosen' => null, 'why' => null];

    /** @var array{summary: string|null, criteria: list<string>, untouchable: list<string>} criteria and untouchables as safe inline HTML */
    #[Locked]
    public array $outline = ['summary' => null, 'criteria' => [], 'untouchable' => []];

    /** Why the last pick was refused, in one line; null when nothing was refused. */
    #[Locked]
    public ?string $refusal = null;

    /** The confirmation after a pick landed ("Picked B, committed on main (not pushed)."); null before. */
    #[Locked]
    public ?string $done = null;

    /**
     * Resolve which version to show and read its text from git once, or 404. An
     * unknown or disabled project never gets here: the route's EnsureProjectIsShown
     * refuses it first (SB-7).
     *
     * Side effects: logs `board.story_viewed`; `board.version_rejected` or
     * `board.story_not_found` before a 404; ReadStoryOutline logs
     * `board.story_summary_fallback` when the summary is the Story's first sentence.
     */
    public function mount(Project $project, string $storyId, RenderStory $render, ReadMockupGate $gate, ReadStoryOutline $outline, ParseVersion $parse, FindStoryVersion $find): void
    {
        $context = ['project' => $project->name, 'story' => $storyId];
        $version = $parse->handle(request()->query('v'), $context);

        $story = $find->handle($project, $storyId, $version);
        if ($story === null) {
            Log::info('board.story_not_found', [...$context, 'v' => $version, 'reason' => 'no such story or version']);
            abort(404);
        }
        $this->project = $project->name;
        $this->storyId = $storyId;
        $this->rowId = $story->id;

        $markdown = $render->read($story);
        if ($markdown !== null) {
            $this->body = $render->toHtml($markdown);
            $this->lines = substr_count(rtrim($markdown, "\n"), "\n") + 1;
            $this->gate = $gate->handle($markdown);
            $read = $outline->handle($markdown, $context);
            $this->outline = [
                'summary' => $read['summary'],
                'criteria' => array_map($render->inline(...), $read['criteria']),
                'untouchable' => array_map($render->inline(...), $read['untouchable']),
            ];
        }

        Log::info('board.story_viewed', [...$context, 'version' => $story->location]);
    }

    /**
     * Record `$option` as this story's pick with `$reason`. Everything is
     * re-validated in PickMockup and StoryPickWriter: the browser can send any
     * option for any story, and the writer's refusals are the page's refusals.
     *
     * Side effects: one file write and one local commit in the project (never
     * pushed), or none when refused (logged as `board.mockup_pick_refused`).
     */
    public function pick(string $option, string $reason, PickMockup $picker): void
    {
        $this->done = null;
        $result = $picker->handle($this->project, $this->storyId, $option, $reason);
        $this->refusal = $result['refusal'];
        if ($result['refusal'] === null) {
            $this->done = 'Picked option '.strtoupper($option).", committed on {$result['branch']} (not pushed).";
        }
    }

    /**
     * Render the page: the row, the story's mockup set (on-ref rows only — the
     * set, the viewer and the pick all read the ref), today's page when a
     * journey shot shows it, the dependencies and the other versions.
     */
    public function render(FindStoryVersion $find, ReadMockupSets $sets, ReadCurrentVersion $current): View|string
    {
        if ($this->gone) {
            return '<div></div>';
        }

        $project = Project::where('name', $this->project)->firstOrFail();
        $story = Story::with('project')->findOrFail($this->rowId);
        $all = $find->all($project, $this->storyId);
        $set = $story->location_kind === null ? $sets->find($project, $this->storyId) : null;
        $today = $set !== null ? $current->handle($project, $set['where'], $set['sha']) : null;
        // Depends-on IDs on the ref in this project become links with their title and status; others stay plain text.
        $depends = Story::onRef()->where('project_id', $project->id)->whereIn('story_id', $story->depends_on)
            ->get(['story_id', 'title', 'status'])->keyBy('story_id');

        return view('livewire.board.story-page', [
            'model' => $project,
            'story' => $story,
            'set' => $set,
            'today' => $today !== null && $today['state'] === ReadCurrentVersion::SHOT ? $today : null,
            'depends' => $depends,
            'onRef' => $all->first(fn (Story $s) => $s->location_kind === null),
            'versions' => $find->others($all, $story),
            'versionKinds' => $all->reject(fn (Story $s) => $s->id === $story->id)
                ->countBy(fn (Story $s) => $s->location_kind ?? 'main')->all(),
        ])->title("{$story->story_id} — ".($story->title ?? 'not on main'));
    }
}
