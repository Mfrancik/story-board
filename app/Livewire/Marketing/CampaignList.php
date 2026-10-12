<?php

namespace App\Livewire\Marketing;

use App\Actions\Marketing\BuildCampaignRunway;
use App\Exceptions\CampaignScheduleException;
use App\Livewire\Board\Concerns\GuardsProjectPage;
use App\Models\MarketingCampaign;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Livewire;

/**
 * A project's Marketing tab at `/p/{project}/marketing` (MK-1, the prototype's
 * Campaigns page): an alerts banner summing what needs the owner, then one row per
 * campaign that is on — schedule, next post, approved-through and a 6-date runway,
 * badges — and the paused campaigns in a quieter group below.
 *
 * Reads only: switching a campaign on or off and opening one arrive with MK-7, the
 * studio behind "to approve" with MK-9, so rows and badges are not links yet. A
 * campaign whose schedule cannot be worked out shows its own error row and the rest
 * of the page still renders. The route's EnsureProjectIsShown has already refused
 * an unknown or disabled project before this mounts; GuardsProjectPage re-checks on
 * updates.
 */
#[Layout('layouts.shell')]
class CampaignList extends Component
{
    use GuardsProjectPage;

    /**
     * Keep the project's name. Campaigns are read in render(), never held in public
     * properties, so none of them is serialised into the component snapshot.
     */
    public function mount(Project $project): void
    {
        $this->project = $project->name;
    }

    /**
     * Render the campaigns list.
     *
     * Side effects: logs `marketing.runway.failed` (warning) per campaign whose
     * schedule cannot be read, and `marketing.campaigns.viewed` (debug: project and
     * counts) on the page load.
     */
    public function render(BuildCampaignRunway $build): View|string
    {
        if ($this->gone) {
            // Nothing of a project that is off the board may render.
            return '<div></div>';
        }

        $model = Project::where('name', $this->project)->firstOrFail();
        $now = CarbonImmutable::now();
        $campaigns = $model->marketingCampaigns()
            ->with(['posts' => fn ($q) => $q->runwayWindow($now)])
            ->orderBy('name')
            ->get();

        $rows = $campaigns->map(fn (MarketingCampaign $c) => $this->row($c, $build, $now));
        [$on, $paused] = $rows->partition(fn (array $r) => $r['campaign']->is_enabled);
        // Only campaigns that are on, and whose runway could be read, count towards what needs the owner.
        $live = $on->filter(fn (array $r) => $r['runway'] !== null);
        $toApprove = $live->sum(fn (array $r) => $r['runway']['to_approve']);

        if (! Livewire::isLivewireRequest()) {
            Log::debug('marketing.campaigns.viewed', [
                'project' => $model->name, 'campaigns' => $rows->count(), 'enabled' => $on->count(), 'paused' => $paused->count(),
                'to_approve' => $toApprove, 'failed' => $rows->whereNull('runway')->count(),
            ]);
        }

        return view('livewire.marketing.campaign-list', [
            'model' => $model,
            'on' => $on->values(),
            'paused' => $paused->values(),
            'toApprove' => $toApprove,
            // A campaign that missed a post is named for that, not also for running thin.
            'missed' => $live->filter(fn (array $r) => $r['runway']['missed'] !== null)->values(),
            'thin' => $live->filter(fn (array $r) => $r['runway']['thin'] && $r['runway']['missed'] === null)->values(),
        ])->title($model->name.' · Marketing');
    }

    /**
     * One campaign's row: the campaign, its runway, or why the runway failed.
     *
     * @return array{campaign: MarketingCampaign, runway: array<string, mixed>|null, error: string|null}
     */
    private function row(MarketingCampaign $campaign, BuildCampaignRunway $build, CarbonImmutable $now): array
    {
        try {
            return ['campaign' => $campaign, 'runway' => $build->handle($campaign, $now), 'error' => null];
        } catch (CampaignScheduleException $e) {
            Log::warning('marketing.runway.failed', ['project' => $this->project, 'campaign_id' => $campaign->id, 'reason' => $e->getMessage()]);

            return ['campaign' => $campaign, 'runway' => null, 'error' => $e->getMessage()];
        }
    }
}
