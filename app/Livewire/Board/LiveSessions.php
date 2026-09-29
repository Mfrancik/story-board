<?php

namespace App\Livewire\Board;

use App\Actions\Board\ListLiveSessions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Live now" panel (SB-11, design A): one card per Claude Code session active
 * in the last few minutes, with its project, checkout, branch and the story it is
 * building. Embedded by `/` (every project) and `/p/{project}` (that one). It
 * re-reads itself every 30 s; the page around it never polls.
 */
class LiveSessions extends Component
{
    /** The project the panel is limited to, or null for every project. Locked: only the host page sets it. */
    #[Locked]
    public ?string $project = null;

    /**
     * Render the panel from the cached scan. A project switched off since the page
     * loaded simply has no sessions any more, since only enabled projects match.
     */
    public function render(ListLiveSessions $live): View
    {
        return view('livewire.board.live-sessions', [
            ...$live->handle($this->project),
            'root' => (string) config('board.sessions_path'),
        ]);
    }
}
