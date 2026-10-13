<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\DeleteMarketingAsset;
use App\Actions\Marketing\FindProjectAsset;
use App\Actions\Marketing\QueueAssetRead;
use App\Actions\Marketing\ReadLogoFromRepo;
use App\Actions\Marketing\SaveBrand;
use App\Actions\Marketing\SetBrandLogo;
use App\Actions\Marketing\StoreMarketingAsset;
use App\Exceptions\MarketingLogoException;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Livewire\Marketing\Concerns\ValidatesMarketingImages;
use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
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
 * A project's Marketing settings at `/p/{project}/marketing/settings` (MK-2, the prototype's Settings route): the
 * brand every draft uses (one form: it creates the brand on first save and edits it after), its logo read from the
 * repo or uploaded and always sanitised, a small set of favourite photos, the copy rules MK-8 lints and prompts
 * with, and Facebook as a disabled phase-2 card.
 *
 * Uploads are validated here (type and size) and stored by StoreMarketingAsset. Deleting a photo is confirmed in
 * board/confirm-modal, whose open/close is Alpine; only the confirm calls the server. The route's
 * EnsureProjectIsShown refuses an unknown or disabled project; GuardsProjectPage re-checks on updates.
 */
#[Layout('layouts.shell')]
class Settings extends Component
{
    use GuardsProjectPage;
    use ValidatesMarketingImages;
    use WithFileUploads {
        _uploadErrored as livewireUploadErrored;
    }

    /** The largest logo file accepted, in KB; SanitiseSvg refuses anything bigger anyway. */
    public const LOGO_MAX_KB = 512;

    public string $name = '';

    public string $accent = '';

    public string $ink = '';

    public string $surface = '';

    public string $font = '';

    public int $radius = 0;

    public string $shadow = '';

    public string $voice = '';

    /** Filler phrases, one per line as the owner types them; stored as a list. */
    public string $filler = '';

    /** Banned claims, one per line; stored as a list. */
    public string $banned = '';

    public string $logoPath = '';

    public string $logoRef = 'origin/main';

    /** An SVG chosen in "Upload an SVG"; stored as soon as it arrives. */
    public ?TemporaryUploadedFile $logoFile = null;

    /** @var array<int, TemporaryUploadedFile> photos chosen in "Add favourites"; stored as soon as they arrive */
    public array $photos = [];

    /**
     * Keep the project's name and load the brand into the forms: its saved values, or the defaults for a project
     * with no brand yet (nothing is written until the owner saves).
     */
    public function mount(Project $project, SaveBrand $save): void
    {
        $this->project = $project->name;
        $brand = $save->brandOf($project);
        $values = $brand->values + MarketingBrand::DEFAULTS;

        $this->name = (string) ($values['name'] ?? $project->name);
        $this->accent = (string) $values['accent'];
        $this->ink = (string) $values['ink'];
        $this->surface = (string) $values['surface'];
        $this->font = (string) $values['font'];
        $this->radius = (int) $values['radius'];
        $this->shadow = (string) $values['shadow'];
        $this->voice = (string) $brand->voice;
        $this->filler = implode("\n", $brand->copy_rules['filler'] ?? []);
        $this->banned = implode("\n", $brand->copy_rules['banned'] ?? []);

        // Offer the last repo read again, so re-reading after a change upstream is one click.
        if (preg_match('/^(\S+) @ (\S+)/', (string) $brand->logo_source, $m)) {
            [, $this->logoPath, $this->logoRef] = $m;
        }
    }

    /**
     * "Save brand": validate the brand form and save it, creating the brand on first save.
     *
     * Side effects: writes the brand (SaveBrand logs `marketing.brand.saved`); toasts "Brand saved".
     */
    public function saveBrand(SaveBrand $save): void
    {
        $values = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'accent' => ['required', 'string', 'regex:'.MarketingBrand::HEX_PATTERN],
            'ink' => ['required', 'string', 'regex:'.MarketingBrand::HEX_PATTERN],
            'surface' => ['required', 'string', 'regex:'.MarketingBrand::HEX_PATTERN],
            // Letters, digits, spaces and hyphens only: the name is written into a style attribute for the preview.
            'font' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'radius' => ['required', 'integer', 'min:0', 'max:32'],
            'shadow' => ['required', 'in:'.implode(',', MarketingBrand::SHADOWS)],
        ], [
            'accent.regex' => 'Use a hex colour like #B79F82.',
            'ink.regex' => 'Use a hex colour like #222222.',
            'surface.regex' => 'Use a hex colour like #FAFAFA.',
            'font.regex' => 'Use the font\'s name only: letters, digits, spaces and hyphens.',
        ]);

        $save->handle($this->model(), ['values' => $values]);
        Flux::toast(variant: 'success', text: 'Brand saved');
    }

    /**
     * "Save copy rules": the voice and the filler and banned lists (one per line, blanks dropped).
     *
     * Side effects: writes the brand (SaveBrand logs `marketing.brand.saved`); toasts "Copy rules saved".
     */
    public function saveCopyRules(SaveBrand $save): void
    {
        $this->validate([
            'voice' => ['nullable', 'string', 'max:2000'],
            'filler' => ['nullable', 'string', 'max:4000'],
            'banned' => ['nullable', 'string', 'max:4000'],
        ]);

        $save->handle($this->model(), [
            'voice' => trim($this->voice) === '' ? null : trim($this->voice),
            'copy_rules' => ['filler' => $this->lines($this->filler), 'banned' => $this->lines($this->banned)],
        ]);
        Flux::toast(variant: 'success', text: 'Copy rules saved');
    }

    /**
     * "Read logo": read the SVG at `logoPath` on `logoRef` from the project's repo and store it sanitised. A refusal
     * is shown under the field it concerns.
     *
     * Side effects: two read-only git commands; writes the brand; see ReadLogoFromRepo for the log lines.
     */
    public function readLogo(ReadLogoFromRepo $read): void
    {
        $this->validate(['logoPath' => ['required', 'string', 'max:255'], 'logoRef' => ['required', 'string', 'max:100']]);

        try {
            $read->handle($this->model(), $this->logoPath, $this->logoRef);
        } catch (MarketingLogoException $e) {
            $this->addError($e->field, $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: 'Logo read from '.$this->logoRef);
    }

    /**
     * An SVG was chosen in "Upload an SVG": validate it and store it sanitised as the logo.
     *
     * Side effects: writes the brand; see SetBrandLogo for the log lines.
     */
    public function updatedLogoFile(): void
    {
        $this->validate(
            ['logoFile' => ['required', 'file', 'mimes:svg', 'max:'.self::LOGO_MAX_KB]],
            ['logoFile.mimes' => 'The logo must be an SVG file.', 'logoFile.max' => 'The logo can be 512 KB at most.'],
        );
        $file = $this->logoFile;
        $this->reset('logoFile');

        try {
            app(SetBrandLogo::class)->handle($this->model(), (string) $file?->get(), 'uploaded '.$file?->getClientOriginalName());
        } catch (MarketingLogoException $e) {
            $this->addError('logoFile', $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: 'Logo uploaded');
    }

    /**
     * Photos were chosen in "Add favourites": validate every one (JPG, PNG or WebP, 10 MB at most), then store them
     * all as favourites and queue each one's AI read (MK-18: what is in it, so Fill picks photos by content). One bad
     * file stores none, so the owner fixes the pick and adds them again in one go.
     *
     * Side effects: writes files and asset rows (StoreMarketingAsset logs `marketing.asset.stored` each); one
     * ReadInspiration job per photo (QueueAssetRead); toasts.
     */
    public function updatedPhotos(): void
    {
        // An upload that brought no files back (a dropped request) is nothing to store, and no "Added 0 photos".
        if ($this->photos === []) {
            Log::info('marketing.asset.upload_empty', ['project' => $this->project]);

            return;
        }
        try {
            $this->validate(
                ['photos' => ['array', 'max:20'], 'photos.*' => $this->imageRules()],
                ['photos.max' => 'Add up to 20 photos at a time.', ...$this->imageMessages('photos.*', 'Photos')],
            );
        } catch (ValidationException $e) {
            // Livewire appends the next pick to this array: a rejected file left here would fail every later upload.
            $this->reset('photos');

            throw $e;
        }

        $store = app(StoreMarketingAsset::class);
        $read = app(QueueAssetRead::class);
        $project = $this->model();
        foreach ($this->photos as $photo) {
            $read->handle($store->handle($project, $photo));
        }
        $count = count($this->photos);
        $this->reset('photos');
        Flux::toast(variant: 'success', text: 'Added '.$count.' '.($count === 1 ? 'photo' : 'photos'));
    }

    /**
     * Livewire rejects an upload over its own temporary limit (12 MB) before updatedPhotos() runs, with a message
     * naming "12288 kilobytes". Give the owner this page's own limits instead, under the same field.
     *
     * @param  string  $name  the property the upload was for
     * @param  string|null  $errorsInJson  Livewire's upload validation errors
     * @param  bool  $isMultiple  whether the input takes several files
     *
     * @throws ValidationException always, as Livewire's does.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple): void
    {
        $limits = ['photos' => $this->imageTooBig('Photos'), 'logoFile' => 'The logo can be 512 KB at most.'];

        try {
            $this->livewireUploadErrored($name, $errorsInJson, $isMultiple);
        } catch (ValidationException $e) {
            if ($name === 'photos') {
                $this->reset('photos');
            }
            if (! isset($limits[$name])) {
                throw $e;
            }

            throw ValidationException::withMessages(array_map(fn () => [$limits[$name]], $e->errors()));
        }
    }

    /**
     * "Delete photo" in the confirm modal: delete the favourite and its file. Another project's id is refused.
     *
     * Side effects: see DeleteMarketingAsset (posts using it lose their photo); FindProjectAsset logs a refusal.
     */
    public function deletePhoto(int $id, FindProjectAsset $find, DeleteMarketingAsset $delete): void
    {
        $asset = $find->handle($this->model(), $id);
        if ($asset === null) {
            Flux::toast(variant: 'warning', text: 'That photo is no longer here.');

            return;
        }

        $delete->handle($asset);
        Flux::toast(variant: 'success', text: 'Photo deleted');
    }

    /**
     * "Retry" under a favourite whose read failed: queue its read again (MK-18). Another project's id is refused.
     *
     * Side effects: QueueAssetRead writes the status, queues the job and logs; FindProjectAsset logs a refusal.
     */
    public function retryRead(int $id, FindProjectAsset $find, QueueAssetRead $read): void
    {
        $asset = $find->handle($this->model(), $id);
        if ($asset === null) {
            Flux::toast(variant: 'warning', text: 'That photo is no longer here.');

            return;
        }
        if ($read->handle($asset)) {
            Flux::toast(variant: 'success', text: 'Reading it again…');
        }
    }

    /**
     * Render the settings page.
     */
    public function render(): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = $this->model();

        $favourites = $model->marketingAssets()->favouritePhotos()->get();

        return view('livewire.marketing.settings', [
            'model' => $model,
            'brand' => $model->marketingBrand,
            'favourites' => $favourites,
            // Poll only while a photo is being read (MK-18), so an idle page makes no requests.
            'reading' => $favourites->contains(fn (MarketingAsset $a) => $a->readState() === MarketingAsset::READ_READING),
        ])->title($model->name.' · Marketing settings');
    }

    /**
     * The project this page is for. Read fresh on each request: it is held by name only.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }

    /**
     * One entry per non-blank line, trimmed, duplicates dropped.
     *
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn ($l) => $l !== '')));
    }
}
