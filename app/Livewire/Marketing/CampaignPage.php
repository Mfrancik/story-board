<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\BuildCampaignRunway;
use App\Actions\Marketing\FillCampaign;
use App\Actions\Marketing\ListCampaignOccurrences;
use App\Actions\Marketing\SaveCampaign;
use App\Actions\Marketing\ToggleCampaign;
use App\Exceptions\CampaignScheduleException;
use App\Exceptions\MarketingDraftRefusedException;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\MarketingAsset;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\DraftingStatus;
use App\Services\Marketing\MarketingAiClient;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One campaign's page at `/p/{project}/marketing/campaigns/{slug}` (MK-7, the prototype's campaign route with MK-7
 * option A's criteria): header with the on/off switch and the campaign in one sentence, the runway status line, the
 * next 8 dates in their states, past posts, and the criteria edited in place. `/campaigns/new` is the same component
 * in create mode: one form for create and edit.
 *
 * The criteria inputs are deferred `wire:model`s, so typing makes no request; the "Unsaved changes" marker is Alpine,
 * comparing the inputs with `stored`. Adding or removing an own topic, Save, Discard and the switch are the only
 * round trips. Each timeline date opens the post studio (MK-9, `open-studio`), which sits on this page and tells it
 * to re-read when it changes a post (`marketing-changed`).
 *
 * Fill (MK-8): each open row has Fill, and the Future posts header has "Fill N open dates" (the next two weeks).
 * Both queue one DraftOccurrenceJob per date through FillCampaign; while any date is drafting the page polls, and
 * a date whose job failed shows "Drafting failed · Retry". With no ANTHROPIC_API_KEY the buttons are disabled.
 *
 * The route's EnsureProjectIsShown refuses an unknown or disabled project before mount; mount() refuses a slug the
 * project does not have (another project's included); GuardsProjectPage re-checks the project on updates.
 */
#[Layout('layouts.shell')]
class CampaignPage extends Component
{
    use GuardsProjectPage;

    /** The campaign being edited, or null in create mode. Locked: only the server may choose it. */
    #[Locked]
    public ?int $campaignId = null;

    public string $name = '';

    /** @var list<string> ISO weekdays as the checkboxes send them ("1" … "7") */
    public array $days = [];

    /** Wall-clock time, HH:MM. */
    public string $time = '19:00';

    public string $timezone = '';

    /** @var list<string> ticked idea-menu entries as "Category|Title" (ADR-065) */
    public array $ideas = [];

    /** @var list<array{text: string, detail: string}> own topics in the owner's order; detail '' when none */
    public array $own = [];

    public string $brief = '';

    public string $photoMode = MarketingCampaign::PHOTO_PER_POST;

    /** The campaign's ad style (MK-5), one of MarketingCampaign::AD_STYLES. */
    public string $adStyle = MarketingCampaign::AD_STYLE_GALLERY;

    /** The "Add a topic" input. */
    public string $newTopic = '';

    /**
     * The criteria as last stored, in the shape the page's Alpine compares the inputs with to show "Unsaved changes".
     * Locked, so the client cannot move the baseline.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $stored = [];

    /**
     * Keep the project's name and load the campaign's criteria, or blank ones in create mode (no `$campaign`).
     *
     * Side effects: a slug the project does not have logs `marketing.campaign.not_found` (info) and is a 404.
     */
    public function mount(Project $project, ?string $campaign = null): void
    {
        $this->project = $project->name;
        if ($campaign === null) {
            // A new campaign posts in the owner's own timezone (config board.timezone), as the seeded ones do.
            $this->timezone = (string) config('board.timezone');
            $this->stored = $this->snapshot();

            return;
        }

        $found = $project->marketingCampaigns()->where('slug', $campaign)->first();
        if ($found === null) {
            Log::info('marketing.campaign.not_found', ['project' => $project->name, 'slug' => $campaign]);
            abort(404);
        }
        $this->campaignId = $found->id;
        $this->fillFrom($found);
    }

    /**
     * "Add" / Enter in "Add a topic": append the typed topic to the own topics (saved with the rest on Save).
     */
    public function addOwnTopic(): void
    {
        $this->validate(['newTopic' => ['required', 'string', 'max:120']], ['newTopic.required' => 'Type a topic first.']);

        $this->own[] = ['text' => trim($this->newTopic), 'detail' => ''];
        $this->reset('newTopic');
    }

    /**
     * Remove own topic `$index` from the form (saved with the rest on Save).
     */
    public function removeOwnTopic(int $index): void
    {
        $this->own = array_values(array_filter($this->own, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY));
    }

    /**
     * "Save changes" / "Create campaign": validate the criteria and save them. A new campaign then opens its page.
     *
     * Side effects: writes the campaign through SaveCampaign (logs `marketing.campaign.created|updated`); toasts.
     */
    public function save(SaveCampaign $save): void
    {
        // Livewire updates skip TrimStrings and SaveCampaign stores the trimmed name, so trim before the
        // unique rule sees it — otherwise " TrueView Tuesday" passes and saves a duplicate.
        $this->name = trim($this->name);
        $this->validate(...$this->rules());

        $model = $this->model();
        $campaign = $save->handle($model, $this->campaign(), [
            'name' => $this->name, 'days' => $this->days, 'time' => $this->time, 'timezone' => $this->timezone,
            'topics' => ['ideas' => $this->ideas, 'own' => $this->own], 'brief' => $this->brief, 'photo_mode' => $this->photoMode,
            'ad_style' => $this->adStyle,
        ]);

        if ($this->campaignId === null) {
            Flux::toast(variant: 'success', text: 'Campaign created');
            $this->redirectRoute('projects.marketing.campaign', [$model->name, $campaign->slug], navigate: true);

            return;
        }
        $this->fillFrom($campaign);
        Flux::toast(variant: 'success', text: 'Changes saved');
    }

    /**
     * "Discard": put the stored criteria back in the form (blank ones in create mode) and clear any errors.
     */
    public function discard(): void
    {
        $this->resetErrorBag();
        $campaign = $this->campaign();
        if ($campaign === null) {
            $this->reset('name', 'days', 'time', 'ideas', 'own', 'brief', 'photoMode', 'adStyle', 'newTopic');
            $this->timezone = (string) config('board.timezone');

            return;
        }
        $this->reset('newTopic');
        $this->fillFrom($campaign);
    }

    /**
     * The header's on/off switch: pause or resume the campaign. Its posts are kept either way, and unsaved criteria
     * stay in the form.
     *
     * Side effects: writes `is_enabled` through ToggleCampaign (logs `marketing.campaign.toggled`); toasts.
     */
    public function toggle(ToggleCampaign $toggle): void
    {
        $campaign = $this->campaign() ?? abort(404);
        $toggle->handle($this->model(), $campaign, ! $campaign->is_enabled);
        Flux::toast(variant: 'success', text: $campaign->name.($campaign->is_enabled ? ' is on' : ' is paused'));
    }

    /**
     * A row's Fill (or Retry): queue drafting for open date `$date` (Y-m-d in the campaign's timezone).
     *
     * Side effects: FillCampaign marks the date drafting and queues its job (logs `marketing.ai.fill.queued`); a
     * refusal (no key, paused, not an open date) is logged there and toasted here.
     */
    public function fillDate(FillCampaign $fill, string $date): void
    {
        $this->queueFill($fill, $date);
    }

    /**
     * "Fill N open dates": queue drafting for every open date in the next two weeks.
     *
     * Side effects: as fillDate(), for each date.
     */
    public function fillOpen(FillCampaign $fill): void
    {
        $this->queueFill($fill, null);
    }

    /**
     * The post studio (MK-9) changed a post: re-render so this page shows it. The body is empty on purpose; the
     * request itself re-renders the page.
     */
    #[On('marketing-changed')]
    public function refreshFromStudio(): void {}

    /**
     * Render the campaign page.
     *
     * Side effects: a campaign whose schedule cannot be read logs `marketing.runway.failed` (warning, source
     * campaign_page) and shows why in place of its timeline; the criteria still render so it can be fixed.
     */
    public function render(ListCampaignOccurrences $occurrences, BuildCampaignRunway $runway, FillCampaign $fill, DraftingStatus $drafting, MarketingAiClient $ai): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = $this->model();
        $campaign = $this->campaign();
        $now = CarbonImmutable::now();
        $timeline = [];
        $status = null;
        $error = null;
        $past = collect();
        $fillStates = [];
        $openSoon = 0;

        if ($campaign !== null) {
            // One posts query feeds both the runway (status line) and the timeline.
            $campaign->load(['posts' => fn ($q) => $q->runwayWindow($now)->with('asset')]);
            try {
                $status = $runway->handle($campaign, $now);
                $timeline = $occurrences->handle($campaign, $now);
                $soon = array_map(fn (CarbonImmutable $at) => $at->toDateString(), $fill->openDates($campaign, $now));
                $fillStates = $drafting->states($campaign->id, array_values(array_unique([...array_column($timeline, 'date'), ...$soon])));
                // N counts what the button would queue: open dates in the window not already drafting.
                $openSoon = count(array_filter($soon, fn (string $d) => ($fillStates[$d] ?? null) !== DraftingStatus::DRAFTING));
            } catch (CampaignScheduleException $e) {
                Log::warning('marketing.runway.failed', ['project' => $model->name, 'campaign_id' => $campaign->id, 'reason' => $e->getMessage(), 'source' => 'campaign_page']);
                $error = $e->getMessage();
            }
            $past = $campaign->posts()
                ->where(fn ($q) => $q->where('status', MarketingPost::STATUS_POSTED)->orWhereNotNull('posted_at'))
                ->with('asset')
                ->get()
                ->sortByDesc(fn (MarketingPost $p) => $p->posted_at ?? $p->scheduled_for)
                ->values();
        }

        $menu = $model->marketingBrand()->first()->idea_menu ?? [];
        $onMenu = collect($menu)->flatMap(fn (array $c) => collect($c['ideas'])->map(fn (array $i) => $c['category'].'|'.$i['title']));

        return view('livewire.marketing.campaign-page', [
            'model' => $model,
            'campaign' => $campaign,
            'timeline' => $timeline,
            'status' => $status,
            'error' => $error,
            'past' => $past,
            'fillStates' => $fillStates,
            'openSoon' => $openSoon,
            'isDrafting' => in_array(DraftingStatus::DRAFTING, $fillStates, true),
            'aiReady' => $ai->isConfigured(),
            'menu' => $menu,
            // Picks whose idea was renamed or removed from the menu (ADR-065), shown so they can be unticked.
            'offMenu' => array_values(array_diff($this->ideas, $onMenu->all())),
            'favourites' => MarketingAsset::query()->where('project_id', $model->id)->favouritePhotos()->count(),
            'sentence' => MarketingCampaign::describe($this->days, $this->time, ['ideas' => $this->ideas, 'own' => $this->own], $this->photoMode),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ])->title(($campaign !== null ? $campaign->name : 'New campaign').' · '.$model->name.' · Marketing');
    }

    /**
     * Queue Fill for `$date`, or every open date in the window when null, and say what happened.
     */
    private function queueFill(FillCampaign $fill, ?string $date): void
    {
        $campaign = $this->campaign() ?? abort(404);
        try {
            $queued = $fill->handle($campaign, CarbonImmutable::now(), $date);
        } catch (MarketingDraftRefusedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        } catch (CampaignScheduleException $e) {
            Log::warning('marketing.runway.failed', ['project' => $this->project, 'campaign_id' => $campaign->id, 'reason' => $e->getMessage(), 'source' => 'fill']);
            Flux::toast(variant: 'danger', text: 'This campaign\'s dates can\'t be worked out. Fix its days, time or timezone first.');

            return;
        }

        Flux::toast(text: $queued === [] ? 'Those dates are already drafting' : 'Drafting '.count($queued).' '.Str::plural('date', count($queued)));
    }

    /**
     * The criteria form's rules and messages.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function rules(): array
    {
        return [[
            // The database's case-insensitive collation makes "set saturday" the same name as "Set Saturday".
            'name' => ['required', 'string', 'max:60', Rule::unique('marketing_campaigns', 'name')->where('project_id', $this->model()->id)->ignore($this->campaignId)],
            'days' => ['required', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone:all'],
            'ideas' => ['array'],
            'ideas.*' => ['string', 'max:200'],
            'own' => ['array', 'max:50'],
            'own.*.text' => ['required', 'string', 'max:120'],
            'own.*.detail' => ['nullable', 'string', 'max:1000'],
            'brief' => ['nullable', 'string', 'max:240'],
            'photoMode' => ['required', Rule::in(MarketingCampaign::PHOTO_MODES)],
            'adStyle' => ['required', Rule::in(MarketingCampaign::AD_STYLES)],
        ], [
            'adStyle.in' => 'Pick one of the ad styles.',
            'name.unique' => 'This project already has a campaign with that name.',
            'days.required' => 'Pick at least one day.',
            'time.date_format' => 'Use a time like 19:00.',
            'timezone.timezone' => 'Pick a timezone from the list.',
            'own.*.text.required' => 'A topic needs some words. Remove it if you no longer want it.',
        ]];
    }

    /**
     * Load `$campaign`'s stored criteria into the form and make them the "Unsaved changes" baseline.
     */
    private function fillFrom(MarketingCampaign $campaign): void
    {
        $this->name = $campaign->name;
        $this->days = array_map('strval', array_values($campaign->days));
        $this->time = substr((string) $campaign->time, 0, 5);
        $this->timezone = $campaign->timezone;
        $this->ideas = $campaign->topics['ideas'] ?? [];
        $this->own = array_map(fn (array $o) => ['text' => $o['text'], 'detail' => (string) $o['detail']], $campaign->topics['own'] ?? []);
        $this->brief = (string) $campaign->brief;
        $this->photoMode = $campaign->photo_mode;
        $this->adStyle = $campaign->adStyle();
        $this->stored = $this->snapshot();
    }

    /**
     * The form's values normalised as the page's Alpine normalises the inputs (days as sorted numbers, ideas
     * sorted), so the two compare equal exactly when nothing has changed.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $days = array_map('intval', $this->days);
        sort($days);
        $ideas = $this->ideas;
        sort($ideas);

        return [
            'name' => $this->name, 'days' => $days, 'time' => $this->time, 'timezone' => $this->timezone, 'ideas' => $ideas,
            'own' => array_map(fn (array $o) => ['text' => $o['text'], 'detail' => $o['detail']], $this->own),
            'brief' => $this->brief, 'photoMode' => $this->photoMode, 'adStyle' => $this->adStyle,
        ];
    }

    /**
     * The project being shown.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }

    /**
     * The campaign being edited, re-read from the database (null in create mode). It must still belong to the
     * project: the id is locked, but the check costs nothing and keeps the page to its project.
     */
    private function campaign(): ?MarketingCampaign
    {
        if ($this->campaignId === null) {
            return null;
        }

        return MarketingCampaign::where('project_id', $this->model()->id)->findOrFail($this->campaignId);
    }
}
