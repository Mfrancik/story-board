<?php

namespace App\Livewire\Board;

use App\Actions\Board\ReadMockupSets;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The mockup gallery at `/mockups` (SB-21, option A): every enabled project's
 * mockup sets as cards with a live thumbnail, grouped by project, awaiting-pick
 * first. The status, project and search filters are Alpine over the one render,
 * so the component has no actions; a card opens the full-screen viewer.
 */
#[Layout('layouts.board')]
class MockupGallery extends Component
{
    /** The project filter to start on (`?project=`, from a project page's Mockups button); '' for all. */
    #[Locked]
    public string $project = '';

    /**
     * Take the starting filter and log the visit.
     *
     * Side effects: logs `board.mockups_viewed` with the set and awaiting counts.
     */
    public function mount(ReadMockupSets $read): void
    {
        $this->project = (string) request()->query('project', '');
        $sets = $read->handle();

        Log::info('board.mockups_viewed', [
            'sets' => count($sets),
            'awaiting' => count(array_filter($sets, fn (array $s) => $s['state'] === ReadMockupSets::AWAITING)),
            'project' => $this->project === '' ? null : $this->project,
        ]);
    }

    /**
     * Render every set, grouped by project (the sets come back from the cache mount() filled).
     */
    public function render(ReadMockupSets $read): View
    {
        $sets = $read->handle();

        return view('livewire.board.mockup-gallery', [
            'groups' => collect($sets)->groupBy('project')->all(),
            'awaiting' => count(array_filter($sets, fn (array $s) => $s['state'] === ReadMockupSets::AWAITING)),
            'total' => count($sets),
        ])->title('Mockups');
    }
}
