<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Models\Story;
use App\Services\GitReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A project's app map (SB-24): every journey in its `docs/journeys/*.md` at the
 * snapshot commit as an ordered flow of steps, each with its story, state,
 * route and picture — the journey test's shot, a placeholder, the chosen
 * mockup, "awaiting pick" or "no mockup yet" — plus the screens (routes) more
 * than one journey passes through.
 *
 * Built only from files each project already keeps, so it needs no change to
 * any project. Journey docs are free-form markdown that /story has written in
 * several shapes; parse() is tolerant of all of them (docs/journeys/README.md
 * fixes none), and a doc it cannot read is named, not fatal.
 *
 * @phpstan-type MapPicture array{
 *     kind: 'shot'|'placeholder'|'mockup'|'awaiting'|'mockups'|'none'|'unlinked',
 *     url: string|null, option: string|null, captured_at: Carbon|null, gallery: string|null
 * }
 * @phpstan-type MapStep array{
 *     n: int, label: string, name: string, text: string, story: string|null, also: list<string>,
 *     title: string|null, status: string|null, state: 'built'|'pending'|'unlinked', route: string|null,
 *     unlinked: 'story'|'route'|null, shared: string|null, picture: MapPicture, link: string|null
 * }
 * @phpstan-type MapJourney array{
 *     slug: string, file: string, name: string, test: string|null, steps: list<MapStep>, built: int, total: int
 * }
 * @phpstan-type MapShared array{
 *     route: string, letter: string, uses: list<array{journey: string, slug: string, n: int, story: string|null}>
 * }
 * @phpstan-type JourneyMap array{
 *     journeys: list<MapJourney>, unparsed: list<string>, shared: list<MapShared>, unreadable: bool, steps: int, shots: int
 * }
 * @phpstan-type ParsedStep array{label: string, name: string, text: string, stories: list<string>, route: string|null, status: string|null}
 */
class ReadJourneyMap
{
    /** Where /story keeps journey docs, one per flow. */
    public const DIR = 'docs/journeys/';

    /** A story ID anywhere in text (Story::ID_PATTERN without its anchors). */
    private const ID_IN_TEXT = '/(?<![A-Za-z0-9-])[A-Z]{2,}-[0-9]+[a-z]?(?![A-Za-z0-9-])/';

    /** A step name is its first clause; past this length it is cut with an ellipsis. */
    private const NAME_LENGTH = 90;

    /**
     * @param  GitReader  $git  journey docs and story files at the snapshot commit
     * @param  ReadMockupSets  $sets  each pending step's mockups (SB-21)
     * @param  ReadJourneyShots  $shots  each built step's shot, when the project has SB-22's manifests
     */
    public function __construct(
        private readonly GitReader $git,
        private readonly ReadMockupSets $sets,
        private readonly ReadJourneyShots $shots,
    ) {}

    /**
     * The project's journeys A–Z by file, the files that could not be parsed,
     * and the shared screens. A project with no snapshot yet, or no journey
     * docs, has no journeys (not an error).
     *
     * Side effects: two to three git reads; reads the working tree's journey
     * shots; logs `board.journey_unparsed` (warning) per doc it cannot read and
     * `board.app_map_unreadable` (warning) when git cannot list or read them.
     *
     * @return JourneyMap
     */
    public function handle(Project $project): array
    {
        $empty = ['journeys' => [], 'unparsed' => [], 'shared' => [], 'unreadable' => false, 'steps' => 0, 'shots' => 0];
        if ($project->sha === null) {
            return $empty;
        }

        try {
            $files = array_values(array_filter(
                $this->git->listFiles($project->path, $project->sha, self::DIR),
                // One level only, and not the folder's README: that describes journeys, it is not one.
                fn (string $f) => preg_match('#^docs/journeys/[^/]+\.md$#', $f) === 1 && strcasecmp(basename($f), 'README.md') !== 0,
            ));
            sort($files);
            $docs = $this->git->showMany($project->path, $project->sha, $files);
        } catch (GitReaderException $e) {
            Log::warning('board.app_map_unreadable', ['project' => $project->name, 'ref' => $project->sha, 'error' => $e->getMessage()]);

            return [...$empty, 'unreadable' => true];
        }

        $parsed = [];
        $unparsed = [];
        foreach ($docs as $file => $markdown) {
            $journey = $markdown !== null ? $this->parse($markdown, basename($file, '.md')) : null;
            if ($journey === null) {
                Log::warning('board.journey_unparsed', ['project' => $project->name, 'file' => $file]);
                $unparsed[] = $file;

                continue;
            }
            $parsed[$file] = $journey;
        }

        return [...$this->compose($project, $parsed), 'unparsed' => $unparsed, 'unreadable' => false];
    }

    /**
     * One journey doc as a name, its journey test path and its steps in order,
     * or null when it names no steps at all.
     *
     * Steps come from the numbered list under the flow heading (`## Flow (plain
     * steps)`, `## The flow`, `## The contract (per step)`), each linked to the
     * stories table's rows for its number (`| Step | ID | … |` or `| Step | Story
     * | … |`), else to the first story ID in its own text. Table rows whose step
     * the list lacks (`4b`) become steps of their own after the step they
     * follow; rows labelled `(substrate)` and the like are not screens and are
     * left out. With no numbered list the table's rows are the steps.
     *
     * @return array{name: string, test: string|null, steps: list<ParsedStep>}|null
     */
    public function parse(string $markdown, string $slug): ?array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $items = $this->flowItems($lines);
        $rows = $this->tableRows($lines);

        $steps = [];
        foreach ($items as [$label, $text]) {
            $matching = array_values(array_filter($rows, fn (array $row) => in_array($label, $row['labels'], true)));
            $stories = array_values(array_unique(array_merge(...array_map(fn (array $row) => $row['stories'], $matching)) ?: $this->ids($text, 1)));
            $steps[] = [
                'label' => $label, 'name' => $this->name($text), 'text' => $this->plain($text), 'stories' => $stories,
                'route' => $this->routeIn($text), 'status' => $matching[0]['status'] ?? null,
            ];
        }

        // Rows for steps the list does not have (`4b`, or every row when there is no list).
        $known = array_column($steps, 'label');
        foreach ($rows as $row) {
            $extra = array_values(array_diff($row['labels'], $known));
            if ($extra === [] || $row['stories'] === []) {
                continue;
            }
            $step = [
                'label' => $extra[0], 'name' => $row['title'] ?? $row['stories'][0], 'text' => $row['title'] ?? '',
                'stories' => $row['stories'], 'route' => $this->routeIn($row['raw']), 'status' => $row['status'],
            ];
            $steps = $this->insertAfter($steps, $step);
            $known[] = $extra[0];
        }

        if ($steps === []) {
            return null;
        }

        preg_match('#tests/Browser/Journeys/[\w/-]+\.php#', $markdown, $test);

        return ['name' => $this->title($lines, $slug), 'test' => $test[0] ?? null, 'steps' => $steps];
    }

    /**
     * Attach each parsed step's story row, route, state and picture, number the
     * steps, and find the routes more than one journey visits.
     *
     * @param  array<string, array{name: string, test: string|null, steps: list<ParsedStep>}>  $parsed  by file
     * @return array{journeys: list<MapJourney>, shared: list<MapShared>, steps: int, shots: int}
     */
    private function compose(Project $project, array $parsed): array
    {
        $ids = [];
        foreach ($parsed as $journey) {
            foreach ($journey['steps'] as $step) {
                array_push($ids, ...$step['stories']);
            }
        }
        $rows = $ids === [] ? collect() : Story::onRef()->where('project_id', $project->id)
            ->whereIn('story_id', array_values(array_unique($ids)))
            ->get(['id', 'project_id', 'story_id', 'title', 'status', 'path', 'sha', 'mockups'])
            ->keyBy('story_id');
        $routes = $this->storyRoutes($project, $rows->all());
        $sets = [];
        foreach ($this->sets->handle($project) as $set) {
            $sets[$set['story']] = $set;
        }
        $shots = $this->shots->handle($project);

        $journeys = [];
        foreach ($parsed as $file => $journey) {
            $slug = basename($file, '.md');
            $steps = [];
            foreach ($journey['steps'] as $i => $step) {
                // The story that pictures the step: the first one with a screen of its own, else the first.
                $story = collect($step['stories'])->first(fn (string $id) => ($routes[$id] ?? null) !== null) ?? $step['stories'][0] ?? null;
                $row = $story !== null ? $rows->get($story) : null;
                $route = $step['route'] ?? ($story !== null ? $routes[$story] ?? null : null);
                // The story file's own Status: line is the truth (git-owned); the journey table's copy is the fallback.
                $status = $row !== null ? $row->status : $step['status'];
                $state = $story === null ? 'unlinked' : ($status === 'built' ? 'built' : 'pending');

                $steps[] = [
                    'n' => $i + 1, 'label' => $step['label'], 'name' => $step['name'], 'text' => $step['text'],
                    'story' => $story, 'also' => array_values(array_diff($step['stories'], [$story])),
                    'title' => $row?->title, 'status' => $status, 'state' => $state, 'route' => $route,
                    'unlinked' => $story === null ? 'story' : ($route === null ? 'route' : null),
                    'shared' => null,
                    'picture' => $this->picture($project, $slug, $state, $story, $route, $sets, $shots),
                    'link' => $row !== null && $row->hasPage() ? route('stories.show', ['project' => $project->name, 'storyId' => $story]) : null,
                ];
            }
            $journeys[] = [
                'slug' => $slug, 'file' => $file, 'name' => $journey['name'], 'test' => $journey['test'], 'steps' => $steps,
                'built' => count(array_filter($steps, fn (array $s) => $s['state'] === 'built')), 'total' => count($steps),
            ];
        }

        [$journeys, $shared] = $this->share($journeys);
        $all = array_merge(...array_map(fn (array $j) => $j['steps'], $journeys) ?: [[]]);

        return [
            'journeys' => $journeys,
            'shared' => $shared,
            'steps' => count($all),
            'shots' => count(array_filter($all, fn (array $s) => $s['picture']['kind'] === 'shot')),
        ];
    }

    /**
     * What a step shows. Built: its journey shot, else the drawn placeholder.
     * Pending: the chosen mockup option, "awaiting pick", the set with no
     * readable pick, or "no mockup yet". No story: nothing to show.
     *
     * @param  array<string, array<string, mixed>>  $sets  mockup sets by story ID
     * @param  array<string, list<array<string, mixed>>>  $shots  what ReadJourneyShots::handle() returned
     * @return MapPicture
     */
    private function picture(Project $project, string $journey, string $state, ?string $story, ?string $route, array $sets, array $shots): array
    {
        $picture = ['kind' => 'none', 'url' => null, 'option' => null, 'captured_at' => null, 'gallery' => null];
        if ($story === null) {
            return [...$picture, 'kind' => 'unlinked'];
        }
        if ($state === 'built') {
            /** @var array<string, list<array{journey: string, step: string|null, route: string|null, story: string|null, captured_at: Carbon|null, commit: string|null, file: string, path: string}>> $shots */
            $shot = $this->shots->forStep($shots, $journey, $story, $route);

            return $shot === null ? [...$picture, 'kind' => 'placeholder'] : [
                ...$picture, 'kind' => 'shot', 'captured_at' => $shot['captured_at'],
                'url' => route('shots.file', ['project' => $project->name, 'journey' => $journey, 'file' => $shot['file']]),
            ];
        }

        $set = $sets[$story] ?? null;
        if ($set === null) {
            return $picture;
        }
        $gallery = route('mockups.show', ['project' => $project->name, 'story' => $story]);

        return match ($set['state']) {
            ReadMockupSets::PICKED => [...$picture, 'kind' => 'mockup', 'option' => $set['picked'], 'gallery' => $gallery,
                'url' => route('mockups.frame', ['project' => $project->name, 'story' => $story, 'file' => "option-{$set['picked']}.html"])],
            ReadMockupSets::AWAITING => [...$picture, 'kind' => 'awaiting', 'gallery' => $gallery],
            default => [...$picture, 'kind' => 'mockups', 'gallery' => $gallery],
        };
    }

    /**
     * Mark every route that more than one journey visits with a letter, A
     * onwards in order of first appearance, and list where each is used.
     *
     * @param  list<MapJourney>  $journeys
     * @return array{0: list<MapJourney>, 1: list<MapShared>}
     */
    private function share(array $journeys): array
    {
        $uses = [];
        foreach ($journeys as $journey) {
            foreach ($journey['steps'] as $step) {
                if ($step['route'] !== null) {
                    $uses[$step['route']][] = ['journey' => $journey['name'], 'slug' => $journey['slug'], 'n' => $step['n'], 'story' => $step['story']];
                }
            }
        }

        $shared = [];
        $letters = [];
        foreach ($uses as $route => $list) {
            if (count(array_unique(array_column($list, 'slug'))) > 1) {
                $letter = $this->letter(count($shared));
                $letters[$route] = $letter;
                $shared[] = ['route' => (string) $route, 'letter' => $letter, 'uses' => $list];
            }
        }

        $journeys = array_map(fn (array $journey) => [...$journey, 'steps' => array_map(
            fn (array $step) => [...$step, 'shared' => $step['route'] !== null ? $letters[$step['route']] ?? null : null],
            $journey['steps'],
        )], $journeys);

        return [$journeys, $shared];
    }

    /** A, B, … Z, then AA, AB — a coins-sized project can share more than 26 screens. */
    private function letter(int $n): string
    {
        return ($n >= 26 ? $this->letter(intdiv($n, 26) - 1) : '').chr(65 + $n % 26);
    }

    /**
     * The route each story names in its `- Routes:` line, read in one batch per
     * commit, for the steps whose journey doc names none. A read that fails
     * leaves those steps without a route (logged), not the map without steps.
     *
     * @param  array<string, Story>  $rows  by story ID
     * @return array<string, string|null>
     */
    private function storyRoutes(Project $project, array $rows): array
    {
        $routes = [];
        foreach (collect($rows)->groupBy('sha') as $sha => $group) {
            try {
                $read = $this->git->showMany($project->path, (string) $sha, array_values($group->pluck('path')->unique()->all()));
            } catch (GitReaderException $e) {
                Log::warning('board.app_map_unreadable', ['project' => $project->name, 'ref' => $sha, 'error' => $e->getMessage()]);

                continue;
            }
            foreach ($group as $row) {
                $markdown = $read[$row->path] ?? null;
                $routes[(string) $row->story_id] = $markdown !== null ? ReadMockupSets::where($markdown) : null;
            }
        }

        return $routes;
    }

    /**
     * The numbered items under the doc's flow heading, as [label, text] with
     * wrapped lines joined. The heading is the first that says flow, steps or
     * per step and is not about stories, tests or decisions.
     *
     * @param  list<string>  $lines
     * @return list<array{0: string, 1: string}>
     */
    private function flowItems(array $lines): array
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^#{2,4}\s+(.*)$/', $line, $h) && preg_match('/\b(flow|steps|per step)\b/i', $h[1]) && ! preg_match('/stor|test|decision/i', $h[1])) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return [];
        }

        $labels = [];
        $texts = [];
        for ($i = $start; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^#{1,4}\s/', $line)) {
                break;
            }
            if (preg_match('/^\s{0,3}(\d+[a-z]?)[.)]\s+(.*)$/', $line, $m)) {
                $labels[] = $m[1];
                $texts[] = trim($m[2]);
            } elseif ($texts !== [] && trim($line) !== '' && ! str_starts_with(ltrim($line), '|')) {
                // A wrapped line (or a nested bullet) belongs to the item above it.
                $texts[count($texts) - 1] .= ' '.trim($line);
            }
        }

        return array_map(fn (string $label, string $text) => [$label, $text], $labels, $texts);
    }

    /**
     * The rows of the first table whose first column is `Step`, each with its
     * step labels (`3, 5` is two), story IDs, title and status.
     *
     * @param  list<string>  $lines
     * @return list<array{labels: list<string>, stories: list<string>, title: string|null, status: string|null, raw: string}>
     */
    private function tableRows(array $lines): array
    {
        $rows = [];
        $columns = null;
        foreach ($lines as $line) {
            if (! str_starts_with(trim($line), '|')) {
                if ($columns !== null) {
                    break;
                }

                continue;
            }
            $cells = array_map(fn (string $c) => trim($c), explode('|', trim(trim($line), '|')));
            if ($columns === null) {
                if (strcasecmp(trim($cells[0], ' *_'), 'step') === 0) {
                    $columns = array_map(fn (string $c) => strtolower(trim($c, ' *_')), $cells);
                }

                continue;
            }
            if (preg_match('/^:?-{2,}/', $cells[0])) {
                continue;
            }

            $storyCol = $this->column($columns, ['id', 'story']);
            $statusCol = $this->column($columns, ['status']);
            $storyCell = $storyCol !== null ? $cells[$storyCol] ?? '' : '';
            $stories = $this->ids($storyCell) ?: $this->ids(implode(' ', array_slice($cells, 1)), 1);
            $title = trim((string) preg_replace(['/'.trim(self::ID_IN_TEXT, '/').'/', '/^\s*[—–:-]\s*/u', '/\*\(?stub\)?\*/i'], '', $this->plain($storyCell)));
            $status = $statusCol !== null && preg_match('/[a-z]+/', strtolower($this->plain($cells[$statusCol] ?? '')), $s) ? $s[0] : null;

            $rows[] = [
                // Only numbered steps are screens; `(substrate)`, `(framing)` and the like are not.
                'labels' => array_values(array_filter(array_map('trim', preg_split('/[,\/&]/', $this->plain($cells[0])) ?: []), fn (string $l) => preg_match('/^\d+[a-z]?$/', $l) === 1)),
                'stories' => $stories, 'title' => $title !== '' ? $title : null, 'status' => $status, 'raw' => $line,
            ];
        }

        return $rows;
    }

    /**
     * The index of the first column whose header is one of `$names`.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $names
     */
    private function column(array $columns, array $names): ?int
    {
        foreach ($columns as $i => $column) {
            if (in_array($column, $names, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Put a table-only step after the last step whose number is not above its
     * own (`4b` after `4`), or at the end.
     *
     * @param  list<ParsedStep>  $steps
     * @param  ParsedStep  $step
     * @return list<ParsedStep>
     */
    private function insertAfter(array $steps, array $step): array
    {
        $at = count($steps);
        foreach ($steps as $i => $existing) {
            if ((int) $existing['label'] > (int) $step['label']) {
                $at = $i;
                break;
            }
        }
        array_splice($steps, $at, 0, [$step]);

        return $steps;
    }

    /**
     * The journey's name from its `#` title: `Journey — See my collection` and
     * `Journey: Sale submit — "…"` both lose the prefix and the tagline.
     *
     * @param  list<string>  $lines
     */
    private function title(array $lines, string $slug): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/', $line, $m)) {
                $name = (string) preg_replace('/^journey\b\s*[:—–-]?\s*/iu', '', $this->plain($m[1]));
                $name = trim((preg_split('/\s+[—–]\s+/u', $name) ?: [$name])[0], " \"'“”");

                return $name !== '' ? $name : Str::headline($slug);
            }
        }

        return Str::headline($slug);
    }

    /**
     * A step's short name: its first clause, markdown removed — the text up to
     * the first arrow, dash, sentence end or parenthesis.
     */
    private function name(string $text): string
    {
        $plain = $this->plain($text);
        $first = trim((preg_split('/\s+(?:→|->|—|–)\s+|\.\s|;\s|\s\(|:\s/u', $plain.' ') ?: [$plain])[0]);
        $first = rtrim($first, '.');

        return Str::limit($first !== '' ? $first : $plain, self::NAME_LENGTH);
    }

    /** Markdown emphasis, code ticks and link targets removed, whitespace collapsed. */
    private function plain(string $text): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)([^*]+)(?<!\s)\*(?![\w*])/', '$1', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Story IDs in `$text`, first seen first, at most `$limit`.
     *
     * @return list<string>
     */
    private function ids(string $text, ?int $limit = null): array
    {
        preg_match_all(self::ID_IN_TEXT, $text, $m);

        return array_slice(array_values(array_unique($m[0])), 0, $limit);
    }

    /** The first code span in `$text` that is a route (`/register`, `GET /lots/{id}`), or null. */
    private function routeIn(string $text): ?string
    {
        preg_match_all('/`([^`]+)`/', $text, $codes);
        foreach ($codes[1] as $code) {
            // A repo file named from the root (`/MULTI_TENANCY_SCOPE.md`) is a path, not a screen.
            if (preg_match('#^(?:(?:GET|POST|PUT|PATCH|DELETE)\s+)?(/[^\s`]*)$#', trim($code), $m) && ! preg_match('/\.(md|php|json|js|html?|ya?ml|txt|csv)$/i', $m[1])) {
                return $m[1];
            }
        }

        return null;
    }
}
