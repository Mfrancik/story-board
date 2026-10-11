<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which projects the home overview covers (RD-6): every enabled project, or the
 * chosen enabled ones. It is the one place that rule lives — each home action is
 * handed this value and asks it, so no panel keeps its own copy of "which
 * projects". Built from the URL by ResolveProjectFilter, which has already
 * dropped unknown and disabled names; a project switched off after that is still
 * left out, because every question re-applies "enabled".
 */
final readonly class ProjectFilter
{
    /**
     * @param  list<string>  $names  the chosen project names, in the order chosen; empty means every enabled project
     */
    private function __construct(public array $names) {}

    /**
     * No filter: every enabled project.
     */
    public static function all(): self
    {
        return new self([]);
    }

    /**
     * The given project names, already checked against the enabled projects;
     * repeats are dropped and the keys renumbered.
     *
     * @param  array<int, string>  $names
     */
    public static function only(array $names): self
    {
        return new self(array_values(array_unique($names)));
    }

    /**
     * True when no project is chosen, so the overview covers them all.
     */
    public function isAll(): bool
    {
        return $this->names === [];
    }

    /**
     * The enabled projects this filter covers, as a query a caller can run or
     * use as a subquery (`->select('id')`).
     *
     * @return Builder<Project>
     */
    public function projects(): Builder
    {
        return Project::enabled()->when(! $this->isAll(), fn (Builder $q) => $q->whereIn('name', $this->names));
    }

    /**
     * Whether a project the caller already knows is enabled is covered — for data
     * matched in memory (live sessions) rather than queried.
     */
    public function includes(string $project): bool
    {
        return $this->isAll() || in_array($project, $this->names, true);
    }
}
