<?php

namespace App\Actions\Marketing;

use App\Exceptions\CampaignScheduleException;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * One campaign's approval runway for the campaigns list (MK-1): its next date and
 * that date's state, how far out it is approved, its next RUNWAY_SIZE dates, and
 * what needs the owner — dates to approve, a photo to add, a missed post, open
 * dates, nothing approved past this week. Reads only.
 *
 * Posts belong to a date by their day in the campaign's timezone, not by exact
 * time, so a post still counts for its date after the campaign's time changes.
 * "Missed" is derived here from the clock and never stored: an approved post whose
 * time has passed without being marked posted.
 */
class BuildCampaignRunway
{
    /** Dates shown on the runway bar. */
    public const RUNWAY_SIZE = 6;

    /** How many dates ahead an unbroken approved run is followed: half a year of a weekly campaign. */
    public const RUN_HORIZON = 26;

    /** A date's state, most decided first: a date with any posted post is posted, else approved, and so on. */
    private const PRECEDENCE = ['posted', 'approved', 'skipped', 'review'];

    /**
     * @param  CampaignSchedule  $schedule  the one place a campaign's dates are worked out
     */
    public function __construct(private CampaignSchedule $schedule) {}

    /**
     * The runway of `$campaign` as of `$now`. Reads `$campaign->posts`, which the
     * caller eager-loads with MarketingPost::scopeRunwayWindow so a list of campaigns
     * is one posts query; loaded lazily (same window) when it has not.
     *
     * @return array{
     *     next: array{at: CarbonImmutable, state: string}|null,
     *     approved_through: CarbonImmutable|null,
     *     runway: list<array{at: CarbonImmutable, state: string}>,
     *     approved: int,
     *     to_approve: int,
     *     needs_photo: bool,
     *     missed: CarbonImmutable|null,
     *     open_dates: int,
     *     thin: bool,
     * }
     *
     * @throws CampaignScheduleException when the campaign's timezone, days or time cannot be read
     */
    public function handle(MarketingCampaign $campaign, CarbonInterface $now): array
    {
        $zone = $this->schedule->zone($campaign);
        $dates = $this->schedule->handle($campaign, $now, self::RUN_HORIZON);
        if (! $campaign->relationLoaded('posts')) {
            $campaign->load(['posts' => fn ($q) => $q->runwayWindow($now)]);
        }
        /** @var Collection<int, MarketingPost> $posts */
        $posts = $campaign->posts->filter(fn (MarketingPost $p) => $p->scheduled_for !== null && $p->status !== MarketingPost::STATUS_DISCARDED);
        $byDay = $posts->groupBy(fn (MarketingPost $p) => $this->day($p, $zone));

        $states = array_map(fn (CarbonImmutable $at) => ['at' => $at, 'state' => $this->state($byDay->get($at->toDateString()))], $dates);
        $runway = array_slice($states, 0, self::RUNWAY_SIZE);
        $through = $this->approvedThrough($states);
        $endOfWeek = CarbonImmutable::instance($now)->setTimezone($zone)->endOfWeek(CarbonInterface::SUNDAY);

        // Upcoming days whose posts are only drafts: each such day is one post to approve, however many candidates it has.
        $toApprove = $posts->filter(fn (MarketingPost $p) => $p->scheduled_for >= $now)
            ->groupBy(fn (MarketingPost $p) => $this->day($p, $zone))
            ->filter(fn (Collection $day, string $key) => $this->state($byDay->get($key)) === 'review');
        $missed = $posts->filter(fn (MarketingPost $p) => $p->status === MarketingPost::STATUS_APPROVED && ! $p->isPosted() && $p->scheduled_for < $now)
            ->max('scheduled_for');

        return [
            'next' => $states[0] ?? null,
            'approved_through' => $through,
            'runway' => $runway,
            'approved' => count(array_filter($runway, fn ($d) => in_array($d['state'], ['approved', 'posted'], true))),
            'to_approve' => $toApprove->count(),
            // A photo-per-post campaign's draft cannot go without the photo the owner adds (MK-9).
            'needs_photo' => $campaign->photo_mode === MarketingCampaign::PHOTO_PER_POST
                && $toApprove->flatten()->contains(fn (MarketingPost $p) => $p->status === MarketingPost::STATUS_DRAFT && $p->asset_id === null),
            'missed' => $missed === null ? null : CarbonImmutable::instance($missed)->setTimezone($zone),
            'open_dates' => count(array_filter($runway, fn ($d) => $d['state'] === 'open')),
            // Nothing approved yet, or the approved run ends this week (ISO week, Monday to Sunday, local).
            'thin' => $through === null || $through->lessThanOrEqualTo($endOfWeek),
        ];
    }

    /**
     * The last date of the unbroken run of covered dates from the next one: approved
     * or posted, or deliberately skipped (the prototype's rule: a skipped date needs
     * nothing, so it does not break the run). An approved date after a gap does not
     * extend it, so the owner revisits the gap.
     *
     * @param  list<array{at: CarbonImmutable, state: string}>  $states
     */
    private function approvedThrough(array $states): ?CarbonImmutable
    {
        $last = null;
        foreach ($states as $date) {
            if (! in_array($date['state'], ['approved', 'posted', 'skipped'], true)) {
                break;
            }
            $last = $date['at'];
        }

        return $last;
    }

    /**
     * A date's state from its posts: posted, approved, skipped, review (drafts only) or open (none).
     * Public so ListCampaignOccurrences (MK-7) reads a date by this same rule instead of a copy of it.
     *
     * @param  Collection<int, MarketingPost>|null  $posts
     */
    public function state(?Collection $posts): string
    {
        if ($posts === null || $posts->isEmpty()) {
            return 'open';
        }
        $have = $posts->map(fn (MarketingPost $p) => $p->isPosted() ? 'posted' : ($p->status === MarketingPost::STATUS_DRAFT ? 'review' : $p->status));
        foreach (self::PRECEDENCE as $state) {
            if ($have->contains($state)) {
                return $state;
            }
        }

        return 'open';
    }

    /**
     * The post's day (Y-m-d) in the campaign's timezone. Public for ListCampaignOccurrences (MK-7), as state().
     */
    public function day(MarketingPost $post, DateTimeZone $zone): string
    {
        return CarbonImmutable::instance($post->scheduled_for)->setTimezone($zone)->toDateString();
    }
}
