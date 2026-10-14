<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use App\Services\Marketing\DraftingStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The review inbox's queue (MK-20): every date of a project waiting for the owner's pick, soonest first, each with
 * its drafts. A campaign date waits by BuildCampaignRunway::datesToApprove() — the one rule behind the campaigns
 * list's count and the studio's queue (upcoming, drafts only, campaign on) — so this page, the Marketing tab's count
 * and "Start approving" always agree on campaign dates. One-offs are added here under the same rule
 * (BuildCampaignRunway::state() is `review`, upcoming), each its own date of one ad. Dates MK-8 is drafting or failed
 * to draft (DraftingStatus) are listed too, without posts, so a date rejected here stays in view until its new ads
 * land. Reads only.
 *
 * All posts are read in one query (with their votes) and handed to each campaign as its loaded `posts`, so the queue
 * costs the same few queries however many dates or campaigns wait.
 */
class ListReviewQueue
{
    /** The filter value for every date. */
    public const ALL = 'all';

    /** The filter value for one-off posts. */
    public const ONE_OFF = 'one-off';

    /**
     * @param  BuildCampaignRunway  $runway  the one place "to approve" is decided
     * @param  CampaignSchedule  $schedule  the campaign dates a drafting state is looked up for
     * @param  DraftingStatus  $drafting  MK-8's drafting / failed state per campaign date
     */
    public function __construct(private BuildCampaignRunway $runway, private CampaignSchedule $schedule, private DraftingStatus $drafting) {}

    /**
     * The queue of `$project` as of `$now`, narrowed to `$filter` (ALL, ONE_OFF or a campaign id as a string).
     *
     * Side effects: a campaign whose schedule cannot be read is left out and logged as `marketing.runway.failed`
     * (warning, source review).
     *
     * @return array{
     *     dates: list<array{key: string, campaign: MarketingCampaign|null, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null}>,
     *     waiting: int,
     *     total: int,
     *     chips: list<array{value: string, label: string, count: int}>,
     * }
     *   `dates` are the filtered rows; `waiting` counts the filtered rows that have drafts to pick from; `total` counts
     *   every such row (the sub-nav badge); `chips` are All plus each campaign (and One-off) with a date waiting.
     */
    public function handle(Project $project, CarbonInterface $now, string $filter = self::ALL): array
    {
        $all = $this->dates($project, $now);
        $dates = array_values(array_filter($all, fn (array $d) => $filter === self::ALL || $this->filterOf($d) === $filter));
        $waiting = fn (array $list) => count(array_filter($list, fn (array $d) => $d['drafting'] === null));

        // A chip for every campaign (and One-off) with a row, counting the rows that have drafts to pick from.
        $labels = [];
        $counts = [];
        foreach ($all as $d) {
            $value = $this->filterOf($d);
            $labels[$value] = $d['campaign']->name ?? 'One-off';
            $counts[$value] = ($counts[$value] ?? 0) + ($d['drafting'] === null ? 1 : 0);
        }
        // Campaign chips in name order, One-off last, as the Library's.
        uksort($labels, fn (int|string $a, int|string $b) => [$a === self::ONE_OFF, $labels[$a]] <=> [$b === self::ONE_OFF, $labels[$b]]);
        $chips = [['value' => self::ALL, 'label' => 'All', 'count' => $waiting($all)]];
        foreach ($labels as $value => $label) {
            $chips[] = ['value' => (string) $value, 'label' => $label, 'count' => $counts[$value]];
        }

        return ['dates' => $dates, 'waiting' => $waiting($dates), 'total' => $waiting($all), 'chips' => $chips];
    }

    /**
     * Just the number of dates waiting for a pick: the Review item's badge in the marketing sub-nav.
     */
    public function count(Project $project, CarbonInterface $now): int
    {
        return $this->handle($project, $now)['total'];
    }

    /**
     * Every row, unfiltered, soonest first (ties by campaign, one-offs after).
     *
     * @return list<array{key: string, campaign: MarketingCampaign|null, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null}>
     */
    private function dates(Project $project, CarbonInterface $now): array
    {
        $campaigns = $project->marketingCampaigns()->where('is_enabled', true)->orderBy('id')->get();
        // One query for every post the runway reads (campaign and one-off), with each post's vote for the 👍/👎.
        $posts = $project->marketingPosts()->runwayWindow($now)
            ->with(['feedback' => fn ($q) => $q->whereNotNull('vote')->latest('id')])
            ->get();

        $rows = [];
        foreach ($campaigns as $campaign) {
            $own = $posts->where('campaign_id', $campaign->id)->values();
            // The posts know their campaign, so needsPhoto() and the views never lazy-load it per post.
            $own->each(fn (MarketingPost $p) => $p->setRelation('campaign', $campaign));
            $campaign->setRelation('posts', $own);
            try {
                $rows = [...$rows, ...$this->campaignRows($campaign, $own, $now)];
            } catch (CampaignScheduleException $e) {
                Log::warning('marketing.runway.failed', ['project' => $project->name, 'campaign_id' => $campaign->id, 'reason' => $e->getMessage(), 'source' => 'review']);
            }
        }

        // A one-off is its own date of one post, waiting by the same rule as a campaign date: drafts only, upcoming.
        $posts->whereNull('campaign_id')
            ->filter(fn (MarketingPost $p) => $p->scheduled_for >= $now && $this->runway->state(new Collection([$p])) === 'review')
            ->each(function (MarketingPost $p) use (&$rows) {
                $p->setRelation('campaign', null);
                $at = CarbonImmutable::instance($p->scheduled_for)->setTimezone((string) config('board.timezone'));
                $rows[] = ['key' => self::ONE_OFF.'/'.$p->id, 'campaign' => null, 'date' => $at->toDateString(), 'at' => $at, 'posts' => new Collection([$p]), 'drafting' => null];
            });

        usort($rows, fn (array $a, array $b) => [$a['at']->getTimestamp(), $a['campaign']->id ?? PHP_INT_MAX, $a['key']]
            <=> [$b['at']->getTimestamp(), $b['campaign']->id ?? PHP_INT_MAX, $b['key']]);

        return $rows;
    }

    /**
     * One campaign's rows: its dates waiting for a pick (BuildCampaignRunway::datesToApprove) with their drafts, then
     * its open dates MK-8 is drafting or failed to draft.
     *
     * @param  Collection<int, MarketingPost>  $posts  the campaign's runway posts, already set as its `posts`
     * @return list<array{key: string, campaign: MarketingCampaign, date: string, at: CarbonImmutable, posts: Collection<int, MarketingPost>, drafting: string|null}>
     *
     * @throws CampaignScheduleException when the campaign's timezone or schedule cannot be read
     */
    private function campaignRows(MarketingCampaign $campaign, Collection $posts, CarbonInterface $now): array
    {
        $zone = $this->schedule->zone($campaign);
        $byDay = $posts->groupBy(fn (MarketingPost $p) => $this->runway->day($p, $zone));

        $rows = [];
        foreach ($this->runway->datesToApprove($campaign, $now) as $date) {
            $drafts = $byDay->get($date['date'], new Collection)->where('status', MarketingPost::STATUS_DRAFT)->sortBy('id')->values();
            $rows[$date['date']] = ['key' => $campaign->slug.'/'.$date['date'], 'campaign' => $campaign, 'date' => $date['date'], 'at' => $date['at'], 'posts' => new Collection($drafts->all()), 'drafting' => null];
        }

        // Only an open date (no kept post) shows its drafting state: once drafts land, the date is waiting as above.
        // The dates are the ones MK-8's Fill can target for one date (the campaign page's timeline).
        $open = array_filter($this->schedule->handle($campaign, $now, ListCampaignOccurrences::COUNT), fn (CarbonImmutable $at) => ! $byDay->has($at->toDateString()));
        $states = $this->drafting->states($campaign->id, array_values(array_map(fn (CarbonImmutable $at) => $at->toDateString(), $open)));
        foreach ($open as $at) {
            $state = $states[$at->toDateString()] ?? null;
            if ($state !== null) {
                $rows[$at->toDateString()] = ['key' => $campaign->slug.'/'.$at->toDateString(), 'campaign' => $campaign, 'date' => $at->toDateString(), 'at' => $at, 'posts' => new Collection, 'drafting' => $state];
            }
        }

        return array_values($rows);
    }

    /**
     * The filter value a row answers to: its campaign's id, or ONE_OFF.
     *
     * @param  array{campaign: MarketingCampaign|null}  $row
     */
    private function filterOf(array $row): string
    {
        return $row['campaign'] === null ? self::ONE_OFF : (string) $row['campaign']->id;
    }
}
