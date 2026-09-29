<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Everything waiting on the owner across enabled projects (SB-3): drafts to
 * approve, mockups to pick, approved work to build, plus one summary per
 * project. Reads the stored snapshot only — never git.
 */
class ListWhatNeedsMe
{
    /** Card order for the kit's vocabulary; anything else (a project's own or a typo) follows, A–Z. */
    private const STATUS_ORDER = ['draft', 'approved', 'built', 'cancelled'];

    /**
     * The three groups and the project summaries, each narrowed by the same filters.
     * The project summaries and In flight (SB-9) describe the whole portfolio, so
     * only the project filter narrows them — never the initiative or the search.
     *
     * @param  string|null  $project  a project name, or null for all
     * @param  string|null  $initiative  an initiative folder name, or null for all
     * @param  string|null  $search  a story ID (exact) or text found in an ID or title
     * @return array{
     *     approval: Collection<int, Story>,
     *     pick: Collection<int, Story>,
     *     build: Collection<int, Story>,
     *     parked: int,
     *     built: int,
     *     offmain: int,
     *     projects: list<array{name: string, ref: string, state: string, sha: string|null, indexed_at: Carbon|null, last_error: string|null, counts: array<string, int>, parse_errors: int, offmain: int}>,
     *     in_flight: array{approved: int, offmain: int, not_ok: list<string>}
     * }
     */
    public function handle(?string $project = null, ?string $initiative = null, ?string $search = null): array
    {
        $scoped = fn () => $this->scoped($project, $initiative, $search);
        $projects = $this->projects($project);

        return [
            'approval' => $scoped()->where('stories.status', 'draft')->where('stories.is_parked', false)
                ->orderBy('projects.name')->orderBy('stories.path')->get(),
            // Only open stories: a built or cancelled story with no parseable pick is history, not a decision.
            // Raw SQL: the query builder has no JSON length/type predicates. Constant expressions, no input.
            'pick' => $scoped()->whereIn('stories.status', ['draft', 'approved'])
                ->whereRaw("json_length(json_extract(stories.mockups, '$.options')) > 0")
                ->whereRaw("json_type(json_extract(stories.mockups, '$.chosen')) = 'NULL'")
                ->orderBy('projects.name')->orderBy('stories.path')->get(),
            // Oldest first by the date the story was asked for; undated ones last (raw: MySQL has no NULLS LAST).
            'build' => $scoped()->where('stories.status', 'approved')
                ->orderByRaw('stories.dated_on is null')->orderBy('stories.dated_on')
                ->orderBy('projects.name')->orderBy('stories.path')->get(),
            'parked' => $scoped()->where('stories.status', 'draft')->where('stories.is_parked', true)->count(),
            'built' => $scoped()->where('stories.status', 'built')->count(),
            'offmain' => $this->scoped($project, $initiative, $search, offMain: true)->count(),
            'projects' => $projects,
            'in_flight' => $this->inFlight($projects),
        ];
    }

    /**
     * The rows of a collapsible section (owner ruling at SB-3's gate), loaded only
     * when it is opened — coins alone has 828 built stories.
     *
     * @param  'built'|'parked'|'offmain'  $section
     * @return Collection<int, Story>
     */
    public function section(string $section, ?string $project = null, ?string $initiative = null, ?string $search = null): Collection
    {
        $query = $this->scoped($project, $initiative, $search, offMain: $section === 'offmain');

        return match ($section) {
            // SB-5: grouped by where the work lives, then by story.
            'offmain' => $query->orderBy('projects.name')->orderBy('stories.location_kind')->orderBy('stories.branch')
                ->orderBy('stories.path')->get(),
            'built' => $query->where('stories.status', 'built')->orderBy('projects.name')->orderBy('stories.path')->get(),
            'parked' => $query->where('stories.status', 'draft')->where('stories.is_parked', true)
                ->orderBy('projects.name')->orderBy('stories.path')->get(),
        };
    }

    /**
     * Every story on a ref with exactly this ID (any status), for an ID search
     * that no visible group answers — a cancelled story, say.
     *
     * @return Collection<int, Story>
     */
    public function withId(string $storyId, ?string $project = null): Collection
    {
        $storyId = $this->asId($storyId);
        if ($storyId === null) {
            return new Collection;
        }

        return $this->scoped($project, null, $storyId)->orderBy('projects.name')->get();
    }

    /**
     * Stories of enabled projects, joined to their project for ordering, with the filters applied.
     *
     * @return Builder<Story>
     */
    private function scoped(?string $project, ?string $initiative, ?string $search, bool $offMain = false): Builder
    {
        $search = trim((string) $search);

        return Story::query()
            ->select('stories.*')
            ->join('projects', 'projects.id', '=', 'stories.project_id')
            ->where('projects.is_enabled', true)
            // Off-main rows (SB-5) have their own section; the groups count the ref only.
            ->when($offMain, fn ($q) => $q->whereNotNull('stories.location_kind'), fn ($q) => $q->whereNull('stories.location_kind'))
            ->with('project')
            ->when($project, fn ($q) => $q->where('projects.name', $project))
            ->when($initiative, fn ($q) => $q->where('stories.initiative', $initiative))
            // A search shaped like an ID wants that one story: `MOB-65` must not also match MOB-650.
            ->when($search !== '' && $this->asId($search) !== null, fn ($q) => $q->where('stories.story_id', $this->asId($search)))
            ->when($search !== '' && $this->asId($search) === null, fn ($q) => $q->where(fn ($q) => $q
                ->where('stories.story_id', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('stories.title', 'like', '%'.addcslashes($search, '%_\\').'%')));
    }

    /**
     * One summary per enabled project: on-ref counts by raw status (so an
     * out-of-vocabulary status stays visible), how many stories carry parse errors,
     * and how many versions are not on main (SB-9 tiles).
     *
     * @return list<array{name: string, ref: string, state: string, sha: string|null, indexed_at: Carbon|null, last_error: string|null, counts: array<string, int>, parse_errors: int, offmain: int}>
     */
    private function projects(?string $only): array
    {
        $projects = Project::enabled()->when($only, fn ($q) => $q->where('name', $only))->orderBy('name')->get();

        // Two grouped queries for all projects instead of two per project, so the page's
        // query count does not grow with the portfolio. The first carries the on-ref and
        // off-main counts side by side; raw SQL because a conditional sum has no builder
        // form. Constant expressions, no input.
        $counts = Story::query()->whereIn('project_id', $projects->modelKeys())
            ->selectRaw('project_id, coalesce(status, ?) as status, sum(location_kind is null) as n, sum(location_kind is not null) as off', ['(none)'])
            ->groupBy('project_id', 'status')->get()->groupBy('project_id');
        // Raw SQL: JSON array length has no query-builder form. Constant expression, no input.
        $errors = Story::onRef()->whereIn('project_id', $projects->modelKeys())
            ->whereRaw('json_length(parse_errors) > 0')
            ->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');

        return array_values($projects->map(fn (Project $p) => [
            'name' => $p->name,
            'ref' => $p->ref,
            'state' => $p->state,
            'sha' => $p->sha,
            'indexed_at' => $p->indexed_at,
            'last_error' => $p->last_error,
            // A status seen only off main (a mockup-only row's null, say) is not on the ref: no segment.
            'counts' => $this->ordered($counts->get($p->id, collect())->filter(fn ($row) => (int) $row->n > 0)
                ->mapWithKeys(fn ($row) => [$row->status => (int) $row->n])->all()),
            'parse_errors' => (int) ($errors[$p->id] ?? 0),
            'offmain' => (int) $counts->get($p->id, collect())->sum(fn ($row) => (int) $row->off),
        ])->all());
    }

    /**
     * The In flight figures (SB-9), summed from the project summaries so they cost
     * no query of their own: approved-and-unbuilt stories, versions not on main, and
     * the projects whose snapshot is not `ok` (pending, stale or unreachable).
     *
     * @param  list<array{name: string, state: string, counts: array<string, int>, offmain: int}>  $projects
     * @return array{approved: int, offmain: int, not_ok: list<string>}
     */
    private function inFlight(array $projects): array
    {
        return [
            'approved' => array_sum(array_map(fn (array $p) => $p['counts']['approved'] ?? 0, $projects)),
            'offmain' => array_sum(array_column($projects, 'offmain')),
            'not_ok' => array_values(array_map(fn (array $p) => $p['name'], array_filter($projects, fn (array $p) => $p['state'] !== Project::STATE_OK))),
        ];
    }

    /**
     * Counts keyed by status, in STATUS_ORDER and then alphabetically.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function ordered(array $counts): array
    {
        $rank = fn (string $s) => [array_search($s, self::STATUS_ORDER, true) === false ? 1 : 0, (int) array_search($s, self::STATUS_ORDER, true), $s];
        uksort($counts, fn ($a, $b) => $rank((string) $a) <=> $rank((string) $b));

        return $counts;
    }

    /**
     * The search as a story ID when it is shaped like one. Typing `mob-65` finds
     * MOB-65: the letters are upper-cased, the optional suffix (`2a`) kept lower.
     */
    private function asId(string $search): ?string
    {
        $candidate = preg_replace_callback('/^[a-z]+/i', fn ($m) => strtoupper($m[0]), trim($search)) ?? '';

        return preg_match(Story::ID_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
