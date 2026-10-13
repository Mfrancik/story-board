<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\BuildMarketingCalendar;
use App\Actions\Marketing\ToggleCampaign;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The marketing calendar at `/p/{project}/marketing/calendar` (MK-10, calendar option A): a month grid (or one week)
 * of every campaign's posts as chips in their state, open dates from today on as dashed ghosts, a banner over the
 * first run of 4+ days with nothing approved, and a tray of the campaigns with their on/off switch and a link to each.
 * Below 768 px the grid gives way to a two-week agenda list (both are rendered; CSS picks one).
 *
 * A chip's quick-peek is Alpine over the chip's own data, so opening one makes no request; its "Open in studio"
 * dispatches `open-studio` to the post studio (MK-9), which sits on this page unchanged and tells it to re-read
 * (`marketing-changed`) when it changes a post. Moving between months or weeks is a round trip: the posts shown change.
 *
 * The route's EnsureProjectIsShown refuses an unknown or disabled project before mount; GuardsProjectPage re-checks on
 * updates.
 */
#[Layout('layouts.shell')]
class Calendar extends Component
{
    use GuardsProjectPage;

    /** The views the toggle offers. */
    public const VIEWS = ['month', 'week'];

    /** How many days the narrow-screen agenda lists, from today. */
    public const AGENDA_DAYS = 14;

    /** `month` or `week`. Locked: changed only through setView(), which refuses anything else. */
    #[Locked]
    public string $mode = 'month';

    /** A day (Y-m-d, the owner's timezone) inside the month or week shown. Locked: moved only by previous/next/today. */
    #[Locked]
    public string $anchor = '';

    /**
     * Keep the project's name and start on this month.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
        $this->anchor = $this->todayDate()->toDateString();
    }

    /**
     * The Week / Month toggle: show `$view`. Anything else is refused and the view stays as it was.
     *
     * Side effects: a refused view logs `marketing.calendar.view_refused` (warning).
     */
    public function setView(string $view): void
    {
        if (! in_array($view, self::VIEWS, true)) {
            Log::warning('marketing.calendar.view_refused', ['project' => $this->project, 'view' => $view]);

            return;
        }
        $this->mode = $view;
    }

    /**
     * ‹ : the previous month or week.
     */
    public function previous(): void
    {
        $this->move(-1);
    }

    /**
     * › : the next month or week.
     */
    public function next(): void
    {
        $this->move(1);
    }

    /**
     * "Today": back to the month or week holding today.
     */
    public function today(): void
    {
        $this->anchor = $this->todayDate()->toDateString();
    }

    /**
     * A tray row's switch: pause or resume campaign `$campaign`. Its posts stay either way; its ghosts come and go.
     *
     * Side effects: writes through ToggleCampaign (logs `marketing.campaign.toggled`) and toasts; a campaign that is
     * not this project's logs `marketing.calendar.campaign_refused` (info) and is a 404.
     */
    public function toggleCampaign(ToggleCampaign $toggle, int $campaign): void
    {
        $model = $this->model();
        $found = $model->marketingCampaigns()->find($campaign);
        if ($found === null) {
            Log::info('marketing.calendar.campaign_refused', ['project' => $model->name, 'campaign_id' => $campaign]);
            abort(404);
        }

        $on = ! $found->is_enabled;
        $toggle->handle($model, $found, $on);
        Flux::toast(variant: 'success', text: $found->name.($on ? ' is on' : ' is paused'));
    }

    /**
     * The post studio (MK-9) changed a post: re-render so the calendar shows it. The body is empty on purpose; the
     * request itself re-renders the page.
     */
    #[On('marketing-changed')]
    public function refreshFromStudio(): void {}

    /**
     * Render the calendar.
     *
     * Side effects: `marketing.calendar.viewed` (debug: the view, its range and counts) on the page load;
     * BuildMarketingCalendar logs a campaign whose schedule cannot be read.
     */
    public function render(BuildMarketingCalendar $build): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = $this->model();
        $now = CarbonImmutable::now();
        $today = $this->todayDate();
        $anchor = CarbonImmutable::parse($this->anchor, (string) config('board.timezone'));
        [$from, $to] = $this->mode === 'week'
            ? [$anchor->startOfWeek(CarbonInterface::MONDAY), $anchor->startOfWeek(CarbonInterface::MONDAY)->addDays(6)]
            : [$anchor->startOfMonth()->startOfWeek(CarbonInterface::MONDAY), $anchor->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()];
        $horizon = $today->addDays(BuildMarketingCalendar::HORIZON_DAYS - 1);

        // One build covers the grid, the agenda and the gap rule's horizon, so the page is read once.
        $calendar = $build->handle($model, $from->min($today), $to->max($horizon), $now);
        $days = $calendar['days'];
        $grid = array_filter($days, fn (string $d) => $d >= $from->toDateString() && $d <= $to->toDateString(), ARRAY_FILTER_USE_KEY);
        $agendaEnd = $today->addDays(self::AGENDA_DAYS - 1)->toDateString();
        $agenda = array_filter($days, fn (string $d) => $d >= $today->toDateString() && $d <= $agendaEnd, ARRAY_FILTER_USE_KEY);
        $gap = $build->gap($days, $today);

        if (! Livewire::isLivewireRequest()) {
            $chips = collect($grid)->flatten(1);
            Log::debug('marketing.calendar.viewed', [
                'project' => $model->name, 'view' => $this->mode, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'chips' => $chips->where('state', '!=', 'open')->count(), 'ghosts' => $chips->where('state', 'open')->count(), 'gap' => $gap !== null,
            ]);
        }

        return view('livewire.marketing.calendar', [
            'model' => $model,
            'grid' => $grid,
            'agenda' => $agenda,
            'month' => $anchor->month,
            'range' => $this->mode === 'week' ? $from->format('j M').' – '.$to->format('j M Y') : $anchor->format('F Y'),
            'today' => $today->toDateString(),
            'gap' => $gap,
            'campaigns' => $calendar['campaigns'],
        ])->title('Calendar · '.$model->name.' · Marketing');
    }

    /**
     * Move the anchor `$step` months (month view) or weeks (week view).
     */
    private function move(int $step): void
    {
        $anchor = CarbonImmutable::parse($this->anchor);
        $this->anchor = ($this->mode === 'week' ? $anchor->addWeeks($step) : $anchor->startOfMonth()->addMonths($step))->toDateString();
    }

    /**
     * Today, in the owner's timezone (config board.timezone): the calendar is the owner's week, whatever each
     * campaign's own timezone.
     */
    private function todayDate(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('board.timezone'))->startOfDay();
    }

    /**
     * The project being shown.
     */
    private function model(): Project
    {
        return Project::where('name', $this->project)->firstOrFail();
    }
}
