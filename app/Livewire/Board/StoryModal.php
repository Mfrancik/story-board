<?php

namespace App\Livewire\Board;

use App\Actions\Board\FindStoryVersion;
use App\Actions\Board\ReadMockupGate;
use App\Actions\Board\RenderStory;
use App\Actions\Board\ResolveStoryLink;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything about one story in a large modal over the list (SB-8, design A):
 * its text from git, its details, dependencies, mockups and off-main versions.
 * Addressed by `?story=<project>/<ID>`, so a reload or a shared link opens the
 * same story. The view pushes a history entry per open, so Back closes it.
 * Embedded by the home page and the project page. Nothing here writes.
 */
class StoryModal extends Component
{
    /**
     * The open story as `<project>/<ID>`, '' when closed. It is set from the
     * browser (the URL, Back/Forward), so it is only ever read through
     * ResolveStoryLink, never used as a name or a path directly.
     */
    #[Url(except: '')]
    public string $story = '';

    /*
     * Locked: everything below is derived on the server from a validated `story`.
     * `body` is printed as HTML, so the browser must never be able to set it.
     */

    /** The `stories` row shown, null while closed or refused. */
    #[Locked]
    public ?int $rowId = null;

    /** Rendered story text; null when the row is untracked or git could not read it. */
    #[Locked]
    public ?string $body = null;

    /** @var array{visual: bool, chosen: string|null, why: string|null} the gate section, quoted */
    #[Locked]
    public array $gate = ['visual' => true, 'chosen' => null, 'why' => null];

    /** Why the last link was not opened, in words for the page; null when nothing was refused. */
    #[Locked]
    public ?string $refusal = null;

    /**
     * Open the story named in the URL, if any.
     */
    public function mount(ResolveStoryLink $resolve, RenderStory $render, ReadMockupGate $gate): void
    {
        $this->show($resolve, $render, $gate);
    }

    /**
     * Open a story from a row, a dependency chip, or Back/Forward through the
     * entries the view pushed.
     *
     * @param  string  $story  `<project>/<ID>`, validated before use
     */
    public function open(string $story, ResolveStoryLink $resolve, RenderStory $render, ReadMockupGate $gate): void
    {
        $this->story = $story;
        $this->show($resolve, $render, $gate);
    }

    /**
     * `story` is a public property, so the browser can set it directly; whatever it
     * is set to goes through the same validation as every other way in.
     */
    public function updatedStory(): void
    {
        $this->show(app(ResolveStoryLink::class), app(RenderStory::class), app(ReadMockupGate::class));
    }

    /**
     * Close the modal (and dismiss a refusal), taking `?story` out of the URL.
     */
    public function close(): void
    {
        $this->story = '';
        $this->reset('rowId', 'body', 'gate', 'refusal');
    }

    /**
     * Render the modal: its shell always, its content only while a story is shown.
     */
    public function render(FindStoryVersion $find): View
    {
        $shown = $this->rowId === null ? null : Story::with('project')->find($this->rowId);
        if ($shown === null) {
            return view('livewire.board.story-modal', ['shown' => null]);
        }

        // Depends-on IDs with any version in this project open here; others stay plain text.
        $known = Story::where('project_id', $shown->project_id)->whereIn('story_id', $shown->depends_on)
            ->distinct()->pluck('story_id')->all();

        return view('livewire.board.story-modal', [
            'shown' => $shown,
            'known' => $known,
            'versions' => $find->others($find->all($shown->project, (string) $shown->story_id), $shown),
        ]);
    }

    /**
     * Resolve `story` and load what the modal shows, or record why not.
     *
     * Side effects: one git read for the text; logs `board.story_modal_opened`, or
     * ResolveStoryLink logs `board.story_modal_refused`.
     */
    private function show(ResolveStoryLink $resolve, RenderStory $render, ReadMockupGate $gate): void
    {
        $this->reset('rowId', 'body', 'gate', 'refusal');
        if ($this->story === '') {
            return;
        }

        $link = $resolve->handle($this->story);
        $story = $link['story'];
        if ($story === null) {
            $this->refusal = $link['reason'] === ResolveStoryLink::MALFORMED
                ? "“{$link['id']}” is not a story link. A story link looks like ?story=coins/ACQ-20."
                : "No story {$link['project']}/{$link['id']} on the board.";

            return;
        }

        $this->rowId = $story->id;
        $markdown = $render->read($story);
        if ($markdown !== null) {
            $this->body = $render->toHtml($markdown);
            $this->gate = $gate->handle($markdown);
        }

        Log::info('board.story_modal_opened', ['project' => $link['project'], 'story' => $link['id'], 'version' => $story->location_kind ?? 'ref']);
    }
}
