<?php

namespace App\Actions\Marketing;

use App\Models\MarketingPost;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The marketing library's posts (MK-10, prototype round 5): a calm archive in two sections. Coming up is every
 * approved post still to go out, in date order, grouped by week (This week, Next week, Week of …); Posted is every
 * post that went out, newest first, grouped by month. Reads only.
 *
 * Drafts never appear (they live on campaign pages and in the studio), nor do skipped or discarded posts. A missed
 * post — approved, its time passed, never marked posted — is not "coming up" either: the campaign page and the
 * calendar flag it, and once marked posted it joins Posted. Weeks and months are the owner's (config board.timezone).
 */
class ListLibraryPosts
{
    /** The filter value for posts in no campaign. */
    public const ONE_OFF = 'one-off';

    /** The filter value for every post. */
    public const ALL = 'all';

    /**
     * The library of `$project` as of `$now`: `coming` and `posted`, each a list of groups `{label, open, posts}`
     * (`open` false only for a past month, which starts collapsed), and each section's count. `$filter` is ALL,
     * ONE_OFF or a campaign id (the caller checks it is the project's); `$search` matches captions, as typed.
     *
     * One query, with each post's campaign and photo.
     *
     * @return array{
     *     coming: list<array{label: string, open: bool, posts: Collection<int, MarketingPost>}>,
     *     posted: list<array{label: string, open: bool, posts: Collection<int, MarketingPost>}>,
     *     counts: array{coming: int, posted: int},
     * }
     */
    public function handle(Project $project, CarbonInterface $now, string $filter = self::ALL, string $search = ''): array
    {
        $zone = (string) config('board.timezone');
        $posts = MarketingPost::query()
            ->where('project_id', $project->id)
            ->whereNotNull('scheduled_for')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', MarketingPost::STATUS_APPROVED)->whereNull('posted_at')->where('scheduled_for', '>=', $now))
                ->orWhere('status', MarketingPost::STATUS_POSTED)
                // MarketingPost::isPosted()'s rule as a query: the posted status, or a posted time.
                ->orWhere(fn (Builder $q) => $q->whereNotNull('posted_at')->where('status', '!=', MarketingPost::STATUS_DISCARDED)))
            ->when($filter === self::ONE_OFF, fn (Builder $q) => $q->whereNull('campaign_id'))
            ->when(ctype_digit($filter), fn (Builder $q) => $q->where('campaign_id', (int) $filter))
            ->when(trim($search) !== '', fn (Builder $q) => $q->where('caption', 'like', '%'.addcslashes(trim($search), '\\%_').'%'))
            ->with(['campaign', 'asset'])
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();

        [$posted, $coming] = $posts->partition(fn (MarketingPost $p) => $p->isPosted());
        $today = CarbonImmutable::instance($now)->setTimezone($zone);

        return [
            'coming' => $this->groups($coming, fn (MarketingPost $p) => $this->weekLabel($this->local($p->scheduled_for, $zone), $today), fn () => true),
            'posted' => $this->groups(
                $posted->sortByDesc(fn (MarketingPost $p) => [$p->posted_at ?? $p->scheduled_for, $p->id]),
                fn (MarketingPost $p) => $this->local($p->posted_at ?? $p->scheduled_for, $zone)->format('F Y'),
                fn (string $label) => $label === $today->format('F Y'),
            ),
            'counts' => ['coming' => $coming->count(), 'posted' => $posted->count()],
        ];
    }

    /**
     * Split `$posts` (already in order) into consecutive groups by `$label`; `$open` says whether a group starts open.
     *
     * @param  Collection<int, MarketingPost>  $posts
     * @param  callable(MarketingPost): string  $label
     * @param  callable(string): bool  $open
     * @return list<array{label: string, open: bool, posts: Collection<int, MarketingPost>}>
     */
    private function groups(Collection $posts, callable $label, callable $open): array
    {
        $groups = [];
        foreach ($posts as $post) {
            $name = $label($post);
            $groups[$name] ??= ['label' => $name, 'open' => $open($name), 'posts' => new Collection];
            $groups[$name]['posts']->push($post);
        }

        return array_values($groups);
    }

    /**
     * "This week", "Next week" or "Week of 26 Oct": the Monday-first week holding `$day`, seen from `$today`.
     */
    private function weekLabel(CarbonImmutable $day, CarbonImmutable $today): string
    {
        $week = $day->startOfWeek(CarbonInterface::MONDAY);
        $thisWeek = $today->startOfWeek(CarbonInterface::MONDAY);

        return match (true) {
            $week->equalTo($thisWeek) => 'This week',
            $week->equalTo($thisWeek->addWeek()) => 'Next week',
            default => 'Week of '.$week->format('j M'),
        };
    }

    /**
     * `$at` in the owner's timezone.
     */
    private function local(CarbonInterface $at, string $zone): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($zone);
    }
}
