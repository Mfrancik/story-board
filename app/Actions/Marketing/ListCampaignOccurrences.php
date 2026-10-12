<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A campaign's future-posts timeline for its page (MK-7): the next N dates of its schedule, each with its posts and
 * state, plus the dates that still hold posts although the schedule no longer lands on them, plus any missed post.
 * Reads only.
 *
 * It composes MK-1's rules and copies none of them: dates come from CampaignSchedule, and a post belongs to a date,
 * and a date gets its state, through BuildCampaignRunway::day() and ::state() (ADR-062/063). So when the owner moves
 * a campaign from Tuesday to Wednesday, an approved Tue 20 stays a Tue 20 row (`on_schedule` false) while the open
 * dates follow the new rule: nothing is ever moved or rewritten.
 */
class ListCampaignOccurrences
{
    /** The schedule dates the campaign page shows (the story's "next 8 occurrences"). */
    public const COUNT = 8;

    /** The states that need the owner, drawn with the amber edge. */
    public const FLAGGED = ['review', 'open', 'missed'];

    /**
     * @param  CampaignSchedule  $schedule  where the dates come from
     * @param  BuildCampaignRunway  $runway  whose day() and state() place posts on dates
     */
    public function __construct(private CampaignSchedule $schedule, private BuildCampaignRunway $runway) {}

    /**
     * The timeline of `$campaign` as of `$now`, earliest first. Reads `$campaign->posts` when the caller has loaded
     * them (MarketingPost::scopeRunwayWindow, which also brings old approved posts that may be missed); otherwise
     * loads that window with each post's photo.
     *
     * A row's state is the runway's (posted, approved, skipped, review, open), or `missed`: a date whose approved
     * post's time has passed without it being marked posted, the runway's own definition of a miss.
     *
     * @return list<array{at: CarbonImmutable, date: string, state: string, posts: Collection<int, MarketingPost>, on_schedule: bool, flagged: bool}>
     *
     * @throws CampaignScheduleException when the campaign's timezone, days or time cannot be read
     */
    public function handle(MarketingCampaign $campaign, CarbonInterface $now, int $count = self::COUNT): array
    {
        $zone = $this->schedule->zone($campaign);
        $dates = $this->schedule->handle($campaign, $now, $count);
        if (! $campaign->relationLoaded('posts')) {
            $campaign->load(['posts' => fn ($q) => $q->runwayWindow($now)->with('asset')]);
        }
        /** @var Collection<string, Collection<int, MarketingPost>> $byDay */
        $byDay = $campaign->posts
            ->filter(fn (MarketingPost $p) => $p->scheduled_for !== null && $p->status !== MarketingPost::STATUS_DISCARDED)
            ->groupBy(fn (MarketingPost $p) => $this->runway->day($p, $zone));

        $rows = [];
        foreach ($dates as $at) {
            $rows[$at->toDateString()] = $this->row($at, $byDay->get($at->toDateString(), new Collection), true, $now);
        }

        $last = $dates === [] ? null : end($dates)->toDateString();
        foreach ($byDay as $day => $posts) {
            if (isset($rows[$day])) {
                continue;
            }
            $first = CarbonImmutable::instance($posts->min('scheduled_for'))->setTimezone($zone);
            $row = $this->row($first, $posts, false, $now);
            // Off-schedule dates are shown only while they still matter: upcoming within the window, or missed.
            $upcoming = $posts->contains(fn (MarketingPost $p) => $p->scheduled_for >= $now) && ($last === null || $day <= $last);
            if ($upcoming || $row['state'] === 'missed') {
                $rows[$day] = $row;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * One timeline row: the date, its posts (newest candidate last), its state and whether it needs the owner.
     *
     * @param  Collection<int, MarketingPost>  $posts
     * @return array{at: CarbonImmutable, date: string, state: string, posts: Collection<int, MarketingPost>, on_schedule: bool, flagged: bool}
     */
    private function row(CarbonImmutable $at, Collection $posts, bool $onSchedule, CarbonInterface $now): array
    {
        $state = $this->runway->state($posts);
        $missed = $posts->contains(fn (MarketingPost $p) => $p->status === MarketingPost::STATUS_APPROVED && ! $p->isPosted() && $p->scheduled_for < $now);
        if ($state === 'approved' && $missed) {
            $state = 'missed';
        }

        return [
            'at' => $at,
            'date' => $at->toDateString(),
            'state' => $state,
            'posts' => $posts->sortBy('id')->values(),
            'on_schedule' => $onSchedule,
            'flagged' => in_array($state, self::FLAGGED, true),
        ];
    }
}
