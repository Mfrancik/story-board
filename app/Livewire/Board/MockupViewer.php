<?php

namespace App\Livewire\Board;

use App\Actions\Board\PickMockup;
use App\Actions\Board\ReadCurrentVersion;
use App\Actions\Board\ReadMockupSets;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The full-screen mockup viewer at `/mockups/{project}/{story}` (SB-21, option
 * A's lightbox): title bar, description and Where, option tabs with SB-23's
 * "Current" pane (today's page, from the newest matching journey shot), a width
 * switch, side-by-side compare with a picker per pane — opening as Current vs
 * the first option when a shot exists — and, for a set awaiting a pick, Pick
 * a / b / c with a one-line reason.
 * Everything but the pick is Alpine; the pick is the one server action, and it
 * writes only through StoryPickWriter. EnsureProjectIsShown has already refused
 * an unknown or disabled project before this mounts.
 *
 * @phpstan-import-type CurrentVersion from ReadCurrentVersion
 */
#[Layout('layouts.board')]
class MockupViewer extends Component
{
    use GuardsProjectPage;

    /*
     * Locked: set by the server from the route, re-validated on every pick.
     */

    /** The story ID whose set is shown. */
    #[Locked]
    public string $story = '';

    /** Why the last pick was refused, in one line for the dialog; null when nothing was refused. */
    #[Locked]
    public ?string $refusal = null;

    /**
     * The Current pane, read once per request: mount() needs it for the log line
     * and render() for the page, and reading twice would log each refused shot twice.
     *
     * @var CurrentVersion|null
     */
    private ?array $current = null;

    /**
     * Open the set, or 404 when the story has none.
     *
     * Side effects: logs `board.mockup_viewed` (with `current`: whether a journey
     * shot shows today's page), or `board.mockup_not_found` before the 404.
     */
    public function mount(Project $project, string $story, ReadMockupSets $sets, ReadCurrentVersion $current): void
    {
        $this->project = $project->name;
        $this->story = $story;

        $set = $sets->find($project, $story);
        if ($set === null) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $story, 'reason' => 'no mockup set']);
            abort(404);
        }

        $this->current = $current->handle($project, $set['where'], $set['sha']);
        $shot = $this->current['state'] === ReadCurrentVersion::SHOT;
        // With today's page on hand the viewer opens straight into Current vs an option — the delta is the point.
        Log::info('board.mockup_viewed', ['project' => $project->name, 'story' => $story, 'option' => $this->firstOption($set), 'compare' => $shot, 'current' => $shot]);
    }

    /**
     * A viewer whose project left the board returns to the gallery, not home.
     */
    protected function refusedRoute(): string
    {
        return 'mockups';
    }

    /**
     * Record `$option` as this story's pick with `$reason`, committing the story
     * file alone on the checkout's branch. Everything is re-validated in
     * PickMockup and StoryPickWriter: the browser can send any option for any state.
     *
     * Side effects: one file write and one commit in the project (never pushed),
     * or none when refused; a toast on success.
     */
    public function pick(string $option, string $reason, PickMockup $picker): void
    {
        $result = $picker->handle($this->project, $this->story, $option, $reason);
        $this->refusal = $result['refusal'];
        if ($result['refusal'] !== null) {
            return;
        }

        Flux::toast(variant: 'success', text: 'Picked option '.strtoupper($option)." for {$this->story}, committed on {$result['branch']} (not pushed).");
    }

    /**
     * Render the viewer from the (cached) set, its directory listing and the Current pane.
     */
    public function render(ReadMockupSets $sets, ReadCurrentVersion $current): View|string
    {
        if ($this->gone) {
            return '<div></div>';
        }

        $project = Project::where('name', $this->project)->firstOrFail();
        $all = $sets->handle($project);
        $index = array_search($this->story, array_column($all, 'story'), true);
        abort_if($index === false, 404);
        $set = $all[$index];
        $this->current ??= $current->handle($project, $set['where'], $set['sha']);

        return view('livewire.board.mockup-viewer', [
            'set' => $set,
            ...$sets->describe($project, $set),
            'first' => $this->firstOption($set),
            'current' => $this->current,
            'prev' => $all[($index - 1 + count($all)) % count($all)]['story'],
            'next' => $all[($index + 1) % count($all)]['story'],
            'position' => $index + 1,
            'count' => count($all),
        ])->title("{$set['story']} · Mockups");
    }

    /**
     * The option the viewer opens on: the picked one, else the first.
     *
     * @param  array{picked: string|null, options: list<string>}  $set
     */
    private function firstOption(array $set): string
    {
        return $set['picked'] !== null && in_array($set['picked'], $set['options'], true) ? $set['picked'] : $set['options'][0];
    }
}
