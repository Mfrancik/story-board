<?php

namespace App\Livewire\Board;

use App\Actions\Board\CheckProjectShown;
use App\Actions\Board\ReadHandbook;
use App\Actions\Board\ResolveKit;
use App\Exceptions\GitReaderException;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * A project's handbook at `/p/{project}/handbook` (SB-14, option A): section tabs
 * above one full-width reading card holding the project's rules, lessons,
 * standards, runbook, decisions and skills, read from git at its snapshot SHA,
 * with kit badges inline and decisions opening in the design-A centred modal.
 *
 * The tabs are Alpine. A section's HTML comes back from a renderless Livewire
 * call the first time its tab opens and Alpine keeps it, so the component never
 * re-renders: a re-render would resend every loaded section (coins' runbook is
 * ~20,000 lines) on each later click. The route's EnsureProjectIsShown has
 * already refused an unknown or disabled project before this mounts (ADR-013).
 */
#[Layout('layouts.board')]
class ProjectHandbook extends Component
{
    /** The sections, in tab order. */
    public const SECTIONS = ['rules', 'lessons', 'standards', 'runbook', 'decisions', 'skills'];

    /** Decision files per page of the Decisions list (the story's 50). */
    public const DECISIONS_PAGE = 50;

    /*
     * Locked: public Livewire properties are otherwise settable from the browser —
     * only the server's own actions may change these.
     */

    /** The project's name. A name, not the model, so a project removed mid-visit is refused, not a crash. */
    #[Locked]
    public string $project = '';

    /** The kit's configured checkout, shown in the notice when it cannot be read. */
    #[Locked]
    public string $kitPath = '';

    /** The kit commit the badges compare with, or null when the kit is unreachable (no badges). */
    #[Locked]
    public ?string $kitSha = null;

    /** The tab open on arrival: Rules, or Decisions when the URL names a decision. */
    #[Locked]
    public string $initial = 'rules';

    /** A decision named by `?decision=` on arrival, whether or not it was allowed. */
    #[Locked]
    public ?string $decision = null;

    /** Why that decision was not opened, or null. */
    #[Locked]
    public ?string $decisionRefusal = null;

    /** Set by hydrate() when the project left the board mid-visit: return nothing, the redirect is on its way. */
    private bool $gone = false;

    /**
     * Resolve the kit once per visit (logging `board.kit_unreachable` when it
     * cannot be read) and take a `?decision=` from the URL, checking its name
     * before anything reaches git.
     */
    public function mount(Project $project, ResolveKit $kit): void
    {
        $this->project = $project->name;
        $resolved = $kit->handle();
        $this->kitPath = $resolved['path'];
        $this->kitSha = $resolved['sha'];

        $decision = request()->query('decision');
        if (is_string($decision) && $decision !== '') {
            $this->initial = 'decisions';
            $this->decision = $decision;
        }
    }

    /**
     * Re-check, on every request after the first, that the project is still on
     * the board. Route middleware never sees Livewire update requests (ADR-019
     * amendment), so a project switched off or removed mid-visit would otherwise
     * keep being read from git. The owner is sent home instead.
     */
    public function hydrate(CheckProjectShown $check): void
    {
        $refusal = $check->refusal($this->project);
        if ($refusal === null) {
            return;
        }

        $this->refuse(null, $refusal);
        $this->gone = true;
        $this->redirectRoute('home', navigate: true);
    }

    /**
     * One section's HTML, for the tab opened for the first time. Null (logged)
     * for a section that does not exist or a project gone from the board.
     */
    #[Renderless]
    public function loadSection(string $section, ReadHandbook $handbook): ?string
    {
        if ($this->gone) {
            return null;
        }
        if (! in_array($section, self::SECTIONS, true)) {
            $this->refuse($section, 'unknown_section');

            return null;
        }

        return $this->section($section, $handbook);
    }

    /**
     * One decision file's HTML for the modal, or null (logged) when its name is
     * refused — checked before any git call — or it is not in the project.
     */
    #[Renderless]
    public function openDecision(string $file, ReadHandbook $handbook): ?string
    {
        if ($this->gone) {
            return null;
        }

        return $this->decisionHtml($file, $handbook);
    }

    /**
     * Render the page with its first tab already filled in. After this the
     * component does not render again (every action is renderless).
     */
    public function render(ReadHandbook $handbook): View|string
    {
        if ($this->gone) {
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        $decisionHtml = null;
        if ($this->decision !== null) {
            $decisionHtml = $this->decisionHtml($this->decision, $handbook);
            $this->decisionRefusal = $decisionHtml === null
                ? (ReadHandbook::isSafeDecisionName($this->decision)
                    ? "No decision named {$this->decision} in {$this->project}."
                    : "The decision name {$this->decision} is not allowed: names cannot contain .. or a slash, or start with -.")
                : null;
        }

        return view('livewire.board.project-handbook', [
            'model' => $model,
            'initialHtml' => $this->section($this->initial, $handbook),
            'decisionHtml' => $decisionHtml,
        ])->title($model->name.' · Handbook');
    }

    /**
     * A section's HTML: its partial fed from git, the project's designed "not
     * read yet" state when there is no snapshot, or an error line when git fails.
     * Every call is one `board.handbook_viewed`.
     */
    private function section(string $section, ReadHandbook $handbook): string
    {
        $model = Project::where('name', $this->project)->firstOrFail();
        Log::info('board.handbook_viewed', ['project' => $this->project, 'section' => $section]);

        if ($model->sha === null) {
            $this->refuse(null, 'no_snapshot');

            return view('livewire.board.handbook.unread', ['project' => $this->project])->render();
        }

        $kit = $this->kitSha === null ? null : ['path' => $this->kitPath, 'sha' => $this->kitSha];

        try {
            // Each literal view name sits beside its data, so the partial and its reader cannot drift apart.
            [$view, $data] = match ($section) {
                'rules' => ['livewire.board.handbook.rules', ['html' => $handbook->page($model, ReadHandbook::RULES)]],
                'lessons' => ['livewire.board.handbook.lessons', ['lessons' => $handbook->lessons($model)]],
                'standards' => ['livewire.board.handbook.standards', $handbook->standards($model, $kit)],
                'runbook' => ['livewire.board.handbook.runbook', ['html' => $handbook->page($model, ReadHandbook::RUNBOOK)]],
                'decisions' => ['livewire.board.handbook.decisions', ['names' => $handbook->decisions($model)]],
                'skills' => ['livewire.board.handbook.skills', $handbook->skills($model, $kit)],
                // Callers pass only SECTIONS; loadSection() refuses anything else before it gets here.
                default => throw new InvalidArgumentException("Unknown handbook section: {$section}"),
            };
        } catch (GitReaderException $e) {
            // The project's checkout or snapshot SHA is unreadable: the section says so, the others still load.
            Log::warning('board.handbook_read_failed', ['project' => $this->project, 'section' => $section, 'error' => $e->getMessage()]);

            return view('livewire.board.handbook.failed', ['project' => $this->project, 'sha' => $model->sha, 'error' => $e->getMessage()])->render();
        }

        return view($view, [...$data, 'project' => $this->project, 'kitPath' => $this->kitPath])->render();
    }

    /**
     * A decision's HTML, or null (logged) when the name is refused or the file
     * is not in the project.
     */
    private function decisionHtml(string $file, ReadHandbook $handbook): ?string
    {
        // Before any git call: `..` could leave docs/decisions/ and a leading `-` would be read as an option.
        if (! ReadHandbook::isSafeDecisionName($file)) {
            $this->refuse($file, 'bad_path');

            return null;
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        if ($model->sha === null) {
            $this->refuse($file, 'no_snapshot');

            return null;
        }

        try {
            $html = $handbook->decision($model, $file);
        } catch (GitReaderException $e) {
            Log::warning('board.handbook_read_failed', ['project' => $this->project, 'section' => 'decisions', 'error' => $e->getMessage()]);

            return null;
        }

        if ($html === null) {
            $this->refuse($file, 'not_found');
        }

        return $html;
    }

    /**
     * Log a request the handbook would not serve: a bad decision name, a section
     * that does not exist, a project with no snapshot, or one gone from the board.
     */
    private function refuse(?string $path, string $reason): void
    {
        Log::info('board.handbook_refused', ['project' => $this->project, 'path' => $path, 'reason' => $reason]);
    }
}
