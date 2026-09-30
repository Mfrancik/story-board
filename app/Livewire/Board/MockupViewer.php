<?php

namespace App\Livewire\Board;

use App\Actions\Board\CheckProjectShown;
use App\Actions\Board\ReadMockupSets;
use App\Exceptions\StoryPickRefusedException;
use App\Models\Project;
use App\Models\Story;
use App\Services\StoryPickWriter;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The full-screen mockup viewer at `/mockups/{project}/{story}` (SB-21, option
 * A's lightbox): title bar, description and Where, option tabs with the SB-23
 * "Current" placeholder, a width switch, side-by-side compare with a picker per
 * pane, and — for a set awaiting a pick — Pick a / b / c with a one-line reason.
 * Everything but the pick is Alpine; the pick is the one server action, and it
 * writes only through StoryPickWriter. EnsureProjectIsShown has already refused
 * an unknown or disabled project before this mounts.
 */
#[Layout('layouts.board')]
class MockupViewer extends Component
{
    /*
     * Locked: set by the server from the route, re-validated on every pick.
     */

    /** The project's name. */
    #[Locked]
    public string $project = '';

    /** The story ID whose set is shown. */
    #[Locked]
    public string $story = '';

    /** Why the last pick was refused, in one line for the dialog; null when nothing was refused. */
    #[Locked]
    public ?string $refusal = null;

    /** Set by hydrate() when the project left the board mid-visit: render nothing, the redirect is on its way. */
    private bool $gone = false;

    /**
     * Open the set, or 404 when the story has none.
     *
     * Side effects: logs `board.mockup_viewed`, or `board.mockup_not_found` before the 404.
     */
    public function mount(Project $project, string $story, ReadMockupSets $sets): void
    {
        $this->project = $project->name;
        $this->story = $story;

        $set = $sets->find($project, $story);
        if ($set === null) {
            Log::info('board.mockup_not_found', ['project' => $project->name, 'story' => $story, 'reason' => 'no mockup set']);
            abort(404);
        }

        Log::info('board.mockup_viewed', ['project' => $project->name, 'story' => $story, 'option' => $this->firstOption($set), 'compare' => false]);
    }

    /**
     * Re-check on every request after the first that the project is still on the
     * board (route middleware never sees Livewire updates, ADR-019 amendment).
     */
    public function hydrate(CheckProjectShown $check): void
    {
        $refusal = $check->refusal($this->project);
        if ($refusal === null) {
            return;
        }

        Log::info('board.project_page_refused', ['project' => $this->project, 'reason' => $refusal, 'request' => 'update']);
        $this->gone = true;
        $this->redirectRoute('mockups', navigate: true);
    }

    /**
     * Record `$option` as this story's pick with `$reason`, committing the story
     * file alone on the checkout's branch. Everything is re-validated here and in
     * StoryPickWriter: the browser can send any option for any state.
     *
     * Side effects: one file write and one commit in the project (never pushed),
     * or none when refused; a toast on success.
     */
    public function pick(string $option, string $reason, ReadMockupSets $sets, StoryPickWriter $writer): void
    {
        $this->refusal = null;
        $project = Project::where('name', $this->project)->first();
        $set = $project ? $sets->find($project, $this->story) : null;
        $row = $set ? Story::find($set['row']) : null;
        if ($project === null || $set === null || $row === null) {
            Log::warning('board.mockup_pick_refused', ['project' => $this->project, 'story' => $this->story, 'reason' => 'no mockup set']);
            $this->refusal = 'This story has no mockup set on the board any more.';

            return;
        }

        try {
            $writer->pick($project, $row, $set['options'], $option, $reason);
        } catch (StoryPickRefusedException $e) {
            $this->refusal = 'Pick refused: '.$e->getMessage().'.';

            return;
        } finally {
            // A refused pick changes nothing, but a commit that landed must show at once.
            $sets->forget($project);
        }

        Flux::toast(variant: 'success', text: 'Picked option '.strtoupper($option)." for {$this->story}, committed on ".$writer->boardBranch($project).' (not pushed).');
    }

    /**
     * Render the viewer from the (cached) set and its directory listing.
     */
    public function render(ReadMockupSets $sets): View|string
    {
        if ($this->gone) {
            return '<div></div>';
        }

        $project = Project::where('name', $this->project)->firstOrFail();
        $all = $sets->handle($project);
        $index = array_search($this->story, array_column($all, 'story'), true);
        abort_if($index === false, 404);
        $set = $all[$index];

        return view('livewire.board.mockup-viewer', [
            'set' => $set,
            ...$sets->describe($project, $set),
            'first' => $this->firstOption($set),
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
