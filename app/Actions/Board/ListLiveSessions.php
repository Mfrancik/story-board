<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Models\Story;
use App\Services\SessionReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The live Claude Code sessions of the board's enabled projects, each with the
 * checkout it runs in and the story its branch is building (SB-11). SessionReader
 * supplies the file metadata (cached); matching and story links are worked out
 * per request, so a project switched off disappears from the panel at once.
 */
class ListLiveSessions
{
    /** Where a project's own worktrees live (`<path>/.claude/worktrees/<name>`). */
    private const WORKTREES_DIR = '/.claude/worktrees/';

    /** A day: a story line is read at a fixed SHA, so it never changes; the TTL only bounds the cache. */
    private const LINE_CACHE_SECONDS = 86400;

    /**
     * @param  SessionReader  $reader  the only reader of transcript files
     * @param  FindStoryVersion  $versions  picks the story version a linked ID stands for, as the modal does
     * @param  RenderStory  $render  reads a linked story's markdown from git at its row's SHA
     */
    public function __construct(
        private SessionReader $reader,
        private FindStoryVersion $versions,
        private RenderStory $render,
    ) {}

    /**
     * The panel's data: the reader's status and one card per live session, newest
     * first, optionally limited to one project.
     *
     * Side effects: logs `board.session_ignored` (debug) for a session outside every
     * enabled project; git reads for linked stories whose line is not cached yet.
     *
     * @return array{status: string, sessions: list<array{key: string, project: string, checkout: string, branch: string|null, active_at: Carbon, stories: list<array{story: Story, line: string|null, thumb: string|null, chosen: string|null}>}>}
     */
    public function handle(?string $project = null): array
    {
        $live = $this->reader->live();
        $cards = [];

        foreach ($this->matched($live['sessions'], log: true) as [$session, $model, $checkout]) {
            if ($project !== null && $model->name !== $project) {
                continue;
            }

            $cards[] = [
                // Derived from the file name, never the session's own text (nothing from the file but cwd, branch and times renders).
                'key' => substr(sha1($session['file']), 0, 12),
                'project' => $model->name,
                'checkout' => $checkout,
                'branch' => $session['branch'],
                'active_at' => Carbon::createFromTimestamp($session['mtime']),
                'stories' => $this->links($model, $session['branch']),
            ];
        }

        return ['status' => $live['status'], 'sessions' => $cards];
    }

    /**
     * Live sessions per enabled project, for the sidebar badge. No story links, no git.
     *
     * @return array<string, int> project name => live sessions
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->matched($this->reader->live()['sessions'], log: false) as [, $model]) {
            $counts[$model->name] = ($counts[$model->name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Each session that sits in an enabled project, with that project and its
     * checkout name. The deepest registered path wins, so a worktree registered as
     * a location beats the main checkout it sits under.
     *
     * @param  list<array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int}>  $sessions
     * @return list<array{0: array{file: string, cwd: string, branch: string|null, timestamp: string|null, session_id: string|null, version: string|null, mtime: int}, 1: Project, 2: string}>
     */
    private function matched(array $sessions, bool $log): array
    {
        if ($sessions === []) {
            return [];
        }

        // Disabled projects are loaded too, only so the skip can say why.
        $anchors = $this->anchors(Project::with('locations')->get());
        $out = [];

        foreach ($sessions as $session) {
            $cwd = $session['cwd'];
            $best = null;
            foreach ($anchors as $anchor) {
                $inside = $cwd === $anchor['path'] || str_starts_with($cwd, $anchor['path'].'/');
                if ($inside && ($best === null || strlen($anchor['path']) > strlen($best['path']))) {
                    $best = $anchor;
                }
            }

            if ($best === null || ! $best['project']->is_enabled) {
                // Story-board's own sessions and any unregistered folder land here on every poll: debug, not warning.
                if ($log) {
                    Log::debug('board.session_ignored', [
                        'cwd' => $cwd,
                        'reason' => $best === null ? 'unregistered' : 'disabled',
                        'project' => $best['project']->name ?? null,
                    ]);
                }

                continue;
            }

            $out[] = [$session, $best['project'], $this->checkout($best, $cwd)];
        }

        return $out;
    }

    /**
     * Every path a session can be matched against: each project's main checkout
     * and each of its registered locations (aliases, discovered worktrees).
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{path: string, project: Project, kind: string}>
     */
    private function anchors(Collection $projects): array
    {
        $anchors = [];
        foreach ($projects as $project) {
            $anchors[] = ['path' => rtrim($project->path, '/'), 'project' => $project, 'kind' => 'main'];
            foreach ($project->locations as $location) {
                $anchors[] = ['path' => rtrim($location->path, '/'), 'project' => $project, 'kind' => $location->kind];
            }
        }

        return $anchors;
    }

    /**
     * The checkout a session runs in, as the card names it: "main checkout",
     * "worktree <name>" or, for a sibling clone, its folder name.
     *
     * @param  array{path: string, project: Project, kind: string}  $anchor
     */
    private function checkout(array $anchor, string $cwd): string
    {
        if ($anchor['kind'] === 'main') {
            $prefix = $anchor['path'].self::WORKTREES_DIR;
            if (str_starts_with($cwd, $prefix)) {
                return 'worktree '.strtok(substr($cwd, strlen($prefix)), '/');
            }

            return 'main checkout';
        }

        return ($anchor['kind'] === 'worktree' ? 'worktree ' : '').basename($anchor['path']);
    }

    /**
     * The stories a branch is building, in the story's order: every upper-case ID
     * in the name that exists in the project (on the ref or off main); otherwise
     * the off-main rows committed on that branch; otherwise none.
     *
     * @return list<array{story: Story, line: string|null, thumb: string|null, chosen: string|null}>
     */
    private function links(Project $project, ?string $branch): array
    {
        // No branch recorded, or a detached HEAD: nothing names the work.
        if ($branch === null || $branch === 'HEAD') {
            return [];
        }

        $rows = [];
        // Case-sensitive on purpose: SB-3's ID rule is upper case, so `bul-65` is not BUL-65.
        preg_match_all('/(?<![A-Za-z0-9])[A-Z]{2,}-[0-9]+[a-z]?(?![A-Za-z0-9])/', $branch, $m);
        foreach (array_unique($m[0]) as $id) {
            if (preg_match(Story::ID_PATTERN, $id) === 1 && ($row = $this->versions->handle($project, $id)) !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            // IndexOffMain stores a pushed-only branch under its remote name.
            $rows = Story::where('project_id', $project->id)->offMain()
                ->whereIn('branch', [$branch, "origin/{$branch}"])
                ->orderBy('story_id')->get()
                ->filter(fn (Story $s) => $s->hasPage())
                ->unique('story_id')->values()->all();
        }

        return array_values(array_map(function (Story $row) use ($project) {
            $row->setRelation('project', $project);
            $chosen = $row->mockups['chosen'] ?? null;
            $hasChosen = is_string($chosen) && in_array($chosen, $row->mockups['options'] ?? [], true);

            return [
                'story' => $row,
                'line' => $this->firstSentence($row),
                'thumb' => $hasChosen ? $row->mockupUrl("option-{$chosen}.html") : null,
                'chosen' => $hasChosen ? $chosen : null,
            ];
        }, $rows));
    }

    /**
     * The first sentence of the story's `## Story` section, read with RenderStory's
     * git read at the row's SHA. Cached by SHA and path, so a 30 s poll does not
     * re-run git for text that cannot change. Null when unreadable or absent.
     */
    private function firstSentence(Story $row): ?string
    {
        $key = 'board.story_line:'.sha1("{$row->project_id}|{$row->sha}|{$row->path}|{$row->location_kind}");
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $markdown = $this->render->read($row);
        if ($markdown === null && $row->isInGit()) {
            // Git failed (RenderStory logged it): not cached, so the next poll tries again.
            return null;
        }

        $line = $markdown === null ? '' : $this->sentence($markdown);
        // '' stands for "no line" in the cache, since a cached null reads as a miss.
        Cache::put($key, $line, self::LINE_CACHE_SECONDS);

        return $line === '' ? null : $line;
    }

    /**
     * The first sentence of a story's `## Story` section as plain text, or ''.
     */
    private function sentence(string $markdown): string
    {
        if (preg_match('/^##\s+Story\s*$(.*?)(?=^##\s|\z)/ms', $markdown, $m) !== 1) {
            return '';
        }

        // Markdown emphasis would print as stray asterisks in a plain line.
        $text = trim((string) preg_replace(['/\s+/', '/\*+|`+/'], [' ', ''], $m[1]));

        return preg_match('/^.+?[.!?](?=\s|$)/u', $text, $s) === 1 ? $s[0] : mb_strimwidth($text, 0, 200, '…');
    }
}
