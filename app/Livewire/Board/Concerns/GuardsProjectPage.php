<?php

namespace App\Livewire\Board\Concerns;

use App\Actions\Board\CheckProjectShown;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

/**
 * The guard every one-project Livewire page shares: it holds the project by name
 * and, on every update request, re-checks that the project is still on the board.
 * Route middleware (EnsureProjectIsShown) never sees Livewire update requests
 * (ADR-019 amendment), so without this a project switched off or removed
 * mid-visit would keep being read and rendered.
 *
 * A page using it renders nothing while `$gone` is set; the redirect is on its way.
 */
trait GuardsProjectPage
{
    /**
     * The project's name. A name, not the model, so a project removed mid-visit is
     * refused, not a crash. Locked: only the server may set it.
     */
    #[Locked]
    public string $project = '';

    /** Set by hydrate() when the project left the board mid-visit: render nothing, the redirect is on its way. */
    private bool $gone = false;

    /**
     * Re-check, on every request after the first, that the project is still on
     * the board; when it is not, log why and send the owner to refusedRoute().
     *
     * Side effects: logs the refusal (see logProjectRefused()) and redirects.
     */
    public function hydrate(CheckProjectShown $check): void
    {
        $refusal = $check->refusal($this->project);
        if ($refusal === null) {
            return;
        }

        $this->logProjectRefused($refusal);
        $this->gone = true;
        $this->redirectRoute($this->refusedRoute(), navigate: true);
    }

    /**
     * Where the owner goes when the project has left the board: home, unless the
     * page has a nearer list to return to.
     */
    protected function refusedRoute(): string
    {
        return 'home';
    }

    /**
     * Log why an update request was refused. A page with its own refusal event
     * overrides this, so existing event names stay as they were.
     *
     * @param  string  $reason  CheckProjectShown's reason: `disabled` or `unknown`
     */
    protected function logProjectRefused(string $reason): void
    {
        Log::info('board.project_page_refused', ['project' => $this->project, 'reason' => $reason, 'request' => 'update']);
    }
}
