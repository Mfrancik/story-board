<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\DeleteMarketingAsset;
use App\Actions\Marketing\FindProjectAsset;
use App\Actions\Marketing\QueueAssetRead;
use App\Actions\Marketing\SetInspirationFavourite;
use App\Actions\Marketing\StoreInspiration;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Livewire\Marketing\Concerns\ValidatesMarketingImages;
use App\Models\MarketingAsset;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The Inspiration wall at `/p/{project}/marketing/inspiration` (MK-18, the prototype's Inspiration route): one
 * unorganised place to drop ads the owner likes — a big drop zone, multi-file upload, one masonry wall, no folders or
 * tags. Each image is read by Claude once (ReadInspiration) and shows "Reading…", its notes ("AI read it"), or
 * "Couldn't read · Retry". The heart makes an image a favourite, always among Fill's examples; delete is confirmed in
 * board/confirm-modal (Alpine; only the confirm calls the server).
 *
 * The page says plainly that this shapes tone and copy now, and that layout matching arrives with the designer later.
 * The route's EnsureProjectIsShown refuses an unknown or disabled project; GuardsProjectPage re-checks on updates.
 */
#[Layout('layouts.shell')]
class Inspiration extends Component
{
    use GuardsProjectPage;
    use ValidatesMarketingImages;
    use WithFileUploads {
        _uploadErrored as livewireUploadErrored;
    }

    /** At most this many images per drop: a generous armful, small enough for one request to store. */
    public const MAX_PER_DROP = 30;

    /** What the wall calls its files in messages: "Ads must be JPG, PNG or WebP images." */
    private const NOUN = 'Ads';

    /** @var array<int, TemporaryUploadedFile> images dropped or chosen; stored as soon as they arrive */
    public array $images = [];

    /**
     * Keep the project's name.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * Images were dropped or chosen: validate every one (JPG, PNG or WebP, 10 MB at most), then store each as
     * inspiration and queue its read. One bad file stores none, so the owner fixes the pick and drops again.
     *
     * Side effects: files and asset rows, one read job each (StoreInspiration logs `marketing.inspiration.stored`); toasts.
     */
    public function updatedImages(StoreInspiration $store): void
    {
        // A drop that brought no files back (a dropped request) is nothing to store, and no "Added 0".
        if ($this->images === []) {
            Log::info('marketing.asset.upload_empty', ['project' => $this->project, 'purpose' => MarketingAsset::PURPOSE_INSPIRATION]);

            return;
        }
        try {
            $this->validate(
                ['images' => ['array', 'max:'.self::MAX_PER_DROP], 'images.*' => $this->imageRules()],
                ['images.max' => 'Drop up to '.self::MAX_PER_DROP.' images at a time.', ...$this->imageMessages('images.*', self::NOUN)],
            );
        } catch (ValidationException $e) {
            // Livewire appends the next pick to this array: a rejected file left here would fail every later drop.
            $this->reset('images');

            throw $e;
        }

        $project = $this->model();
        foreach ($this->images as $image) {
            $store->handle($project, $image);
        }
        $count = count($this->images);
        $this->reset('images');
        Flux::toast(variant: 'success', text: 'Added '.$count.' '.($count === 1 ? 'image' : 'images').'. Reading them…');
    }

    /**
     * Livewire rejects a file over its own temporary limit (12 MB) before updatedImages() runs, naming "12288
     * kilobytes". Say this page's limit instead, under the same field.
     *
     * @param  string  $name  the property the upload was for
     * @param  string|null  $errorsInJson  Livewire's upload validation errors
     * @param  bool  $isMultiple  whether the input takes several files
     *
     * @throws ValidationException always, as Livewire's does.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple): void
    {
        try {
            $this->livewireUploadErrored($name, $errorsInJson, $isMultiple);
        } catch (ValidationException $e) {
            $this->reset('images');

            throw ValidationException::withMessages(array_map(fn () => [$this->imageTooBig(self::NOUN)], $e->errors()));
        }
    }

    /**
     * The heart: make image `$id` a favourite, or not.
     *
     * Side effects: SetInspirationFavourite writes and logs; a refused id is logged (see find()).
     */
    public function toggleFavourite(int $id, SetInspirationFavourite $set): void
    {
        $asset = $this->find($id);
        if ($asset !== null) {
            $set->handle($asset, ! $asset->is_favourite);
        }
    }

    /**
     * "Delete" in the confirm modal: remove image `$id` and its file from the wall.
     *
     * Side effects: DeleteMarketingAsset deletes and logs; toasts.
     */
    public function delete(int $id, DeleteMarketingAsset $delete): void
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return;
        }
        $delete->handle($asset);
        Flux::toast(variant: 'success', text: 'Removed from Inspiration');
    }

    /**
     * "Retry" on a tile whose read failed: queue its read again.
     *
     * Side effects: QueueAssetRead writes the status, queues the job and logs.
     */
    public function retry(int $id, QueueAssetRead $read): void
    {
        $asset = $this->find($id);
        if ($asset !== null && $read->handle($asset)) {
            Flux::toast(variant: 'success', text: 'Reading it again…');
        }
    }

    /**
     * Render the wall.
     */
    public function render(): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = $this->model();
        // Not `images`: that is the upload property, and Livewire's properties win over view data of the same name.
        $wall = MarketingAsset::query()->where('project_id', $model->id)->inspiration()->get();

        return view('livewire.marketing.inspiration', [
            'model' => $model,
            'brand' => $model->marketingBrand,
            'wall' => $wall,
            // Poll only while a read is running, so an idle wall makes no requests.
            'reading' => $wall->contains(fn (MarketingAsset $a) => $a->readState() === MarketingAsset::READ_READING),
        ])->title($model->name.' · Inspiration');
    }

    /**
     * This project's inspiration image `$id`, or null with a toast. Another project's id is refused by
     * FindProjectAsset (logged `marketing.asset.refused`); a photo's id is not the wall's to change (logged
     * `marketing.inspiration.refused`, reason `not_inspiration`).
     */
    private function find(int $id): ?MarketingAsset
    {
        $asset = app(FindProjectAsset::class)->handle($this->model(), $id);
        if ($asset !== null && $asset->purpose !== MarketingAsset::PURPOSE_INSPIRATION) {
            Log::warning('marketing.inspiration.refused', ['project' => $this->project, 'asset_id' => $id, 'reason' => 'not_inspiration']);
            $asset = null;
        }
        if ($asset === null) {
            Flux::toast(variant: 'warning', text: 'That image is no longer here.');
        }

        return $asset;
    }

    /**
     * The project this page is for. Read fresh on each request: it is held by name only.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }
}
