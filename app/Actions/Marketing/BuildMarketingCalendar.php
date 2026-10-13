<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Every campaign's posts on one calendar (MK-10): for a range of days, each day's chips — a campaign date's posts as
 * one chip in its state (approved, drafts to approve, posted, missed), each one-off post as its own chip — and the
 * schedule's open dates from today on as ghosts. Also the campaigns tray (each campaign with how far out it is
 * approved) and the first run of days with nothing approved. Reads only.
 *
 * It composes MK-1/MK-7's rules and copies none of them: ghosts are ListCampaignOccurrences' `open` rows (so the
 * schedule is worked out in one place), a date's state is BuildCampaignRunway::state() plus MarketingPost::isMissed(),
 * and a post belongs to a day in its campaign's timezone by BuildCampaignRunway::day(). A one-off has no campaign, so
 * its day is read in the owner's timezone (config board.timezone), the one it was created in.
 *
 * Queries are fixed however many campaigns there are: the campaigns with their runway-window posts (what the
 * occurrences and runway read), and the range's posts with their photos.
 *
 * @phpstan-type Chip array{key: string, date: string, at: CarbonImmutable, state: string, campaign: MarketingCampaign|null, post: MarketingPost|null, drafts: int}
 */
class BuildMarketingCalendar
{
    /** A run of this many days or more with nothing approved is a gap (the prototype's gap rule). */
    public const GAP_DAYS = 4;

    /** How far ahead the gap rule looks: today and the next 27 days. */
    public const HORIZON_DAYS = 28;

    /**
     * @param  ListCampaignOccurrences  $occurrences  where open dates (ghosts) come from
     * @param  BuildCampaignRunway  $runway  a date's state and a post's day; the tray's approved-through
     * @param  CampaignSchedule  $schedule  a campaign's timezone
     */
    public function __construct(
        private ListCampaignOccurrences $occurrences,
        private BuildCampaignRunway $runway,
        private CampaignSchedule $schedule,
    ) {}

    /**
     * The calendar of `$project` from day `$from` to day `$to` (Y-m-d keys, both inclusive) as of `$now`.
     *
     * `days` maps every day of the range to its chips, earliest first. A chip is `key` (unique on the page), `date`,
     * `at` (its first post's or the schedule's time, local), `state` (approved, review, posted, missed or open),
     * `campaign` (null for a one-off), `post` (the post it shows; null for a ghost) and `drafts` (how many candidates
     * a review date has). Skipped dates and discarded posts make no chip, and a paused campaign has no ghosts.
     *
     * `campaigns` is the tray: every campaign by name with its runway (BuildCampaignRunway::handle) or why it failed.
     *
     * Side effects: logs `marketing.runway.failed` (warning, source calendar) per campaign whose schedule cannot be
     * read; its posts still show, but it has no ghosts.
     *
     * @return array{
     *     days: array<string, list<Chip>>,
     *     campaigns: list<array{campaign: MarketingCampaign, runway: array<string, mixed>|null, error: string|null}>,
     * }
     */
    public function handle(Project $project, CarbonImmutable $from, CarbonImmutable $to, CarbonInterface $now): array
    {
        $owner = new DateTimeZone((string) config('board.timezone'));
        $campaigns = $project->marketingCampaigns()
            ->with(['posts' => fn ($q) => $q->runwayWindow($now)->with('asset')])
            ->orderBy('name')
            ->get();

        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[$day->toDateString()] = [];
        }

        $tray = [];
        $zones = [];
        foreach ($campaigns as $campaign) {
            try {
                $zones[$campaign->id] = $this->schedule->zone($campaign);
                $tray[] = ['campaign' => $campaign, 'runway' => $this->runway->handle($campaign, $now), 'error' => null];
                if ($campaign->is_enabled) {
                    $this->addGhosts($days, $campaign, $now, $to);
                }
            } catch (CampaignScheduleException $e) {
                Log::warning('marketing.runway.failed', ['project' => $project->name, 'campaign_id' => $campaign->id, 'reason' => $e->getMessage(), 'source' => 'calendar']);
                $tray[] = ['campaign' => $campaign, 'runway' => null, 'error' => $e->getMessage()];
            }
        }

        $this->addPosts($days, $project, $campaigns, $zones, $owner, $from, $to, $now);

        foreach ($days as $date => $chips) {
            usort($chips, fn (array $a, array $b) => [$a['at']->format('H:i'), $a['key']] <=> [$b['at']->format('H:i'), $b['key']]);
            $days[$date] = $chips;
        }

        return ['days' => $days, 'campaigns' => $tray];
    }

    /**
     * The first run of GAP_DAYS or more days, from `$today`, with nothing approved or posted — or null. `open` is
     * true when the run reaches the horizon, so it may go on past it. `$days` must cover the horizon (handle()'s).
     *
     * @param  array<string, list<array{state: string}>>  $days
     * @return array{from: CarbonImmutable, to: CarbonImmutable, open: bool}|null
     */
    public function gap(array $days, CarbonImmutable $today): ?array
    {
        $start = null;
        $last = $today->addDays(self::HORIZON_DAYS - 1);
        for ($day = $today; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            // A missed post went nowhere, so its day is not covered either.
            $covered = collect($days[$day->toDateString()] ?? [])->contains(fn (array $c) => in_array($c['state'], ['approved', 'posted'], true));
            if (! $covered) {
                $start ??= $day;

                continue;
            }
            if ($start !== null && $start->diffInDays($day) >= self::GAP_DAYS) {
                return ['from' => $start, 'to' => $day->subDay(), 'open' => false];
            }
            $start = null;
        }

        if ($start !== null && $start->diffInDays($last) + 1 >= self::GAP_DAYS) {
            return ['from' => $start, 'to' => $last, 'open' => true];
        }

        return null;
    }

    /**
     * Add `$campaign`'s open dates within `$days` as ghosts. Only today on: an open date in the past is not something
     * to fill any more, and a missed one is already a chip.
     *
     * @param  array<string, list<Chip>>  $days
     *
     * @throws CampaignScheduleException when the campaign's schedule cannot be read
     */
    private function addGhosts(array &$days, MarketingCampaign $campaign, CarbonInterface $now, CarbonImmutable $to): void
    {
        $span = (int) CarbonImmutable::instance($now)->startOfDay()->diffInDays($to->endOfDay()) + 1;
        if ($span < 1) {
            return;
        }

        // A campaign posts at most once a day, so one occurrence per day of the span always reaches $to.
        foreach ($this->occurrences->handle($campaign, $now, $span) as $row) {
            if ($row['state'] !== 'open' || ! array_key_exists($row['date'], $days)) {
                continue;
            }
            $days[$row['date']][] = [
                'key' => 'ghost-'.$campaign->id.'-'.$row['date'], 'date' => $row['date'], 'at' => $row['at'], 'state' => 'open',
                'campaign' => $campaign, 'post' => null, 'drafts' => 0,
            ];
        }
    }

    /**
     * Add the range's posts to `$days`: one chip per campaign date, one per one-off post.
     *
     * @param  array<string, list<Chip>>  $days
     * @param  EloquentCollection<int, MarketingCampaign>  $campaigns
     * @param  array<int, DateTimeZone>  $zones  each readable campaign's timezone
     */
    private function addPosts(array &$days, Project $project, EloquentCollection $campaigns, array $zones, DateTimeZone $owner, CarbonImmutable $from, CarbonImmutable $to, CarbonInterface $now): void
    {
        $byId = $campaigns->keyBy('id');
        // A day of slack each side: a date's posts may sit on the previous or next UTC day in a campaign's timezone.
        $posts = MarketingPost::query()
            ->where('project_id', $project->id)
            ->whereNotNull('scheduled_for')
            ->where('status', '!=', MarketingPost::STATUS_DISCARDED)
            ->where('scheduled_for', '>=', $from->subDay()->utc())
            ->where('scheduled_for', '<', $to->addDays(2)->utc())
            ->with('asset')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();

        $groups = $posts->groupBy(function (MarketingPost $post) use ($byId, $zones, $owner) {
            $campaign = $post->campaign_id === null ? null : $byId->get($post->campaign_id);
            // The campaign instance the tray holds, so a chip never reloads it.
            $post->setRelation('campaign', $campaign);

            return $campaign === null
                ? 'post-'.$post->id.'|'.$this->runway->day($post, $owner)
                : 'date-'.$campaign->id.'|'.$this->runway->day($post, $zones[$campaign->id] ?? $owner);
        });

        foreach ($groups as $key => $group) {
            $date = explode('|', (string) $key)[1];
            $campaignId = $group->first()->campaign_id;
            $chip = $this->chip((string) $key, $date, $group, $zones[$campaignId] ?? $owner, $now);
            if ($chip !== null && array_key_exists($date, $days)) {
                $days[$date][] = $chip;
            }
        }
    }

    /**
     * One chip for a campaign date's posts (or a one-off post), or null for a skipped date: it needs nothing.
     *
     * @param  Collection<int, MarketingPost>  $posts
     * @param  DateTimeZone  $zone  the timezone the chip's time is read in
     * @return Chip|null
     */
    private function chip(string $key, string $date, Collection $posts, DateTimeZone $zone, CarbonInterface $now): ?array
    {
        $state = $this->runway->state($posts);
        if ($state === 'skipped' || $state === 'open') {
            return null;
        }
        if ($state === 'approved' && $posts->contains(fn (MarketingPost $p) => $p->isMissed($now))) {
            $state = 'missed';
        }
        $shown = match ($state) {
            'posted' => $posts->first(fn (MarketingPost $p) => $p->isPosted()),
            'review' => $posts->firstWhere('status', MarketingPost::STATUS_DRAFT),
            default => $posts->firstWhere('status', MarketingPost::STATUS_APPROVED),
        } ?? $posts->first();

        return [
            'key' => explode('|', $key)[0], 'date' => $date,
            'at' => CarbonImmutable::instance($shown->scheduled_for)->setTimezone($zone),
            'state' => $state, 'campaign' => $shown->campaign, 'post' => $shown,
            'drafts' => $posts->where('status', MarketingPost::STATUS_DRAFT)->count(),
        ];
    }
}
