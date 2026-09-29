<?php

namespace App\Livewire\Board;

use App\Actions\Board\AddProject;
use App\Actions\Board\RemoveProject;
use App\Actions\Board\SwitchProject;
use App\Exceptions\ProjectAddRefusedException;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Manage projects page at `/projects` (SB-12, design A view 4): every
 * registered project, enabled or not, with an on/off switch and Remove, and an
 * inline Add project form. Writes go through the SwitchProject, AddProject and
 * RemoveProject actions; the remove confirmation itself is Alpine (pure UI).
 */
#[Layout('layouts.board')]
#[Title('Manage projects')]
class ManageProjects extends Component
{
    /** Add project: the checkout's folder; a leading `~` is the home folder. */
    public string $path = '';

    /** Add project: optional; blank means the folder's name. */
    public string $name = '';

    /** Add project: the ref stories are read from. */
    public string $ref = 'origin/main';

    /**
     * Switch a project on or off the board. The switch sends the state it wants,
     * not "toggle", so a repeated request cannot flip it back.
     *
     * Side effects: see SwitchProject; toasts "<name> switched on|off" and reloads
     * the page so the sidebar (rendered by the layout, not this component) follows.
     */
    public function setEnabled(int $id, bool $enabled, SwitchProject $switch): void
    {
        $project = Project::find($id);
        // The id comes from the browser: a project removed in another tab is refused, not a crash.
        if (! $project) {
            Log::info('board.project_switch_refused', ['id' => $id, 'reason' => 'unknown', 'source' => SwitchProject::SOURCE_UI]);
            Flux::toast(variant: 'warning', text: 'That project is no longer on the board.');
            $this->reload();

            return;
        }

        $switch->handle($project, $enabled, SwitchProject::SOURCE_UI);
        Flux::toast(variant: 'success', text: $project->name.' switched '.($enabled ? 'on' : 'off'));
        $this->reload();
    }

    /**
     * "Add project": register the form's folder and queue its first refresh.
     * A refusal is shown inline under the field it concerns.
     */
    public function add(AddProject $add): void
    {
        $this->resetErrorBag();

        try {
            $add->handle($this->path, $this->name, $this->ref);
        } catch (ProjectAddRefusedException $e) {
            $this->addError($e->field, $e->getMessage());

            return;
        }

        $this->reset('path', 'name', 'ref');
        Flux::toast(variant: 'success', text: 'Project added');
        $this->reload();
    }

    /**
     * "Remove project" in the confirmation modal: delete the project from the board.
     * The row's own Remove button only opens the modal; this is the one server call.
     */
    public function remove(int $id, RemoveProject $remove): void
    {
        $project = Project::find($id);
        if (! $project) {
            Log::info('board.project_remove_refused', ['id' => $id, 'reason' => 'unknown']);
            Flux::toast(variant: 'warning', text: 'That project is no longer on the board.');
            $this->reload();

            return;
        }

        $remove->handle($project);
        Flux::toast(variant: 'success', text: 'Project removed');
        $this->reload();
    }

    /**
     * Every registered project, enabled or not, with its on-ref story count.
     */
    public function render(): View
    {
        return view('livewire.board.manage-projects', [
            'projects' => Project::withCount(['stories' => fn ($q) => $q->onRef()])->orderBy('name')->get(),
            'home' => AddProject::home(),
        ]);
    }

    /**
     * Re-load the page in place (wire:navigate): the sidebar is part of the layout,
     * rendered once per page, so it only drops or gains a project on a new page load.
     * The toast survives because the layout persists it.
     */
    private function reload(): void
    {
        $this->redirectRoute('projects.manage', navigate: true);
    }
}
