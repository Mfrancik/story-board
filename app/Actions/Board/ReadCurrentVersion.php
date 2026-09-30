<?php

namespace App\Actions\Board;

use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * What the mockup viewer's Current pane shows for one mockup set (SB-23): the
 * page as it is today — the newest journey shot (SB-22) whose route matches the
 * story's Where — or why there is none. Shots come through SB-24's
 * ReadJourneyShots (the manifest whitelist, refusals logged there); whether a
 * page exists at all comes from ReadProjectRoutes.
 *
 * Three answers, never a blank pane:
 * - `shot`: a shot matched, with its capture time and commit, `stale` when it
 *   was captured more than STALE_DAYS ago;
 * - `new`: no shot, and the project's route files define no matching route —
 *   the story makes a page that does not exist yet;
 * - `uncovered`: no shot, and the route exists, or the story names no route,
 *   or the routes could not be read (so "new page" is never claimed on a guess).
 *
 * @phpstan-import-type JourneyShot from ReadJourneyShots
 *
 * @phpstan-type CurrentVersion array{
 *     state: 'shot'|'new'|'uncovered', where: string|null, shot: JourneyShot|null, stale: bool
 * }
 */
class ReadCurrentVersion
{
    /** A shot the page was captured at, the Current pane shows as it was. */
    public const SHOT = 'shot';

    /** The story's route does not exist in the app yet. */
    public const NEW_PAGE = 'new';

    /** The route exists (or cannot be told) but no journey shot shows it. */
    public const UNCOVERED = 'uncovered';

    /** Older than this and the shot is labelled "may be out of date" (the story's 14 days). */
    public const STALE_DAYS = 14;

    /**
     * @param  ReadJourneyShots  $shots  SB-22's shots, whitelisted by manifest
     * @param  ReadProjectRoutes  $routes  the project's route URIs, to tell a new page from an uncovered one
     */
    public function __construct(
        private readonly ReadJourneyShots $shots,
        private readonly ReadProjectRoutes $routes,
    ) {}

    /**
     * The Current pane for a story whose Where is `$where`, read against the
     * project's routes at `$sha` (the set's snapshot commit).
     *
     * Side effects: reads the project's working tree (shots) and, only when no
     * shot matches, its route files at `$sha` (cached per commit). Refused
     * manifest entries are logged by ReadJourneyShots.
     *
     * @return CurrentVersion
     */
    public function handle(Project $project, ?string $where, string $sha): array
    {
        if ($where === null) {
            return ['state' => self::UNCOVERED, 'where' => null, 'shot' => null, 'stale' => false];
        }

        $shot = $this->newest($this->shots->handle($project), $where);
        if ($shot !== null) {
            $stale = $shot['captured_at'] !== null && $shot['captured_at']->lt(Carbon::now()->subDays(self::STALE_DAYS));

            return ['state' => self::SHOT, 'where' => $where, 'shot' => $shot, 'stale' => $stale];
        }

        $routes = $this->routes->handle($project, $sha);
        // Unreadable (null) or unparseable (empty) route files cannot prove a page is missing.
        $new = $routes !== null && $routes !== []
            && array_filter($routes, fn (string $route) => self::matches($where, $route)) === [];

        return ['state' => $new ? self::NEW_PAGE : self::UNCOVERED, 'where' => $where, 'shot' => null, 'stale' => false];
    }

    /**
     * The newest shot, across every journey, whose route is `$where` exactly —
     * else the newest whose route matches it as a pattern. Among equally new
     * shots the first in manifest order wins, which is the desktop one.
     *
     * @param  array<string, list<JourneyShot>>  $shots  what ReadJourneyShots::handle() returned
     * @return JourneyShot|null
     */
    public function newest(array $shots, string $where): ?array
    {
        $all = array_merge(...array_values($shots));
        $target = self::normalise($where);
        $exact = array_filter($all, fn (array $shot) => $shot['route'] !== null && self::normalise($shot['route']) === $target);
        $hits = $exact !== [] ? $exact : array_filter($all, fn (array $shot) => $shot['route'] !== null && self::matches($where, $shot['route']));

        $best = null;
        foreach ($hits as $shot) {
            // Strictly newer only, so a tie keeps the earlier (desktop) entry; an undated shot is the oldest.
            if ($best === null || ($shot['captured_at'] !== null && ($best['captured_at'] === null || $shot['captured_at']->gt($best['captured_at'])))) {
                $best = $shot;
            }
        }

        return $best;
    }

    /**
     * Whether two routes name the same page, either written as a pattern:
     * `/p/{project}/preflight` matches `/p/coins/preflight` and the other way
     * round. A `{parameter}` (or bound `{project:name}`) stands for one segment (`{x?}` for one or none);
     * a query string, fragment and trailing slash are ignored.
     */
    public static function matches(string $a, string $b): bool
    {
        [$a, $b] = [self::normalise($a), self::normalise($b)];

        return $a === $b || preg_match(self::pattern($a), $b) === 1 || preg_match(self::pattern($b), $a) === 1;
    }

    /**
     * A route as a comparable path: no query or fragment, one leading slash, no trailing one.
     */
    private static function normalise(string $route): string
    {
        $path = trim((string) preg_replace('/[?#].*$/', '', trim($route)), '/');

        return '/'.$path;
    }

    /**
     * A regex matching the concrete paths a normalised route stands for.
     */
    private static function pattern(string $route): string
    {
        $regex = '';
        foreach (array_filter(explode('/', $route), fn (string $s) => $s !== '') as $segment) {
            $regex .= match (true) {
                // `{x?}` optional; `{x}` or a bound `{project:name}` one segment.
                (bool) preg_match('/^\{[^{}\/]+\?\}$/', $segment) => '(?:/[^/]+)?',
                (bool) preg_match('/^\{[^{}\/]+\}$/', $segment) => '/[^/]+',
                default => '/'.preg_quote($segment, '#'),
            };
        }

        return '#^'.($regex === '' ? '/' : $regex).'$#';
    }
}
