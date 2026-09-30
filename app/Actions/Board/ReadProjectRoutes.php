<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The URIs a registered project's Laravel app defines (SB-23), read from its
 * `routes/*.php` at a commit — so the mockup viewer can tell a story for a page
 * that does not exist yet ("new page") from one whose page exists but no journey
 * test has screenshotted.
 *
 * The files are tokenised with PHP's own tokenizer, never executed: the board
 * does not run a project's code, so `artisan route:list` is out. That makes this
 * a static reading with known blind spots — routes registered from a service
 * provider, a loop, a variable URI or a package are not seen — so a caller must
 * treat "not found" as "probably new", and "could not read" as "unknown".
 */
class ReadProjectRoutes
{
    /** How long a commit's routes are kept. A commit never changes, so this only bounds the cache's size. */
    private const SECONDS = 86400;

    /** Registrar calls whose first string argument (after an optional verb list) is a URI. */
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'view', 'redirect',
        'permanentRedirect', 'livewire', 'resource', 'route'];

    /** Laravel's default for routes/api.php (bootstrap/app.php `withRouting(api: …)`). */
    private const FILE_PREFIXES = ['routes/api.php' => 'api'];

    /**
     * @param  GitReader  $git  the project's route files at the commit
     */
    public function __construct(private readonly GitReader $git) {}

    /**
     * Every route URI under the project's `routes/` at `$sha`, each with a
     * leading slash and its `{parameters}` kept (`/p/{project}/preflight`), or
     * null when the files cannot be read. Cached per commit.
     *
     * Side effects: two git reads on a cold cache; logs
     * `board.project_routes_unreadable` (warning) when git fails.
     *
     * @return list<string>|null
     */
    public function handle(Project $project, string $sha): ?array
    {
        $key = "board:project-routes:{$project->id}:{$sha}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            /** @var list<string> $cached */
            return $cached;
        }

        try {
            $files = array_values(array_filter($this->git->listFiles($project->path, $sha, 'routes/'), fn (string $f) => str_ends_with($f, '.php')));
            $read = $this->git->showMany($project->path, $sha, $files);
        } catch (GitReaderException $e) {
            Log::warning('board.project_routes_unreadable', ['project' => $project->name, 'ref' => $sha, 'error' => $e->getMessage()]);

            return null;
        }

        $uris = [];
        foreach ($read as $file => $php) {
            if ($php !== null) {
                array_push($uris, ...$this->parse($php, $file));
            }
        }
        $uris = array_values(array_unique($uris));
        Cache::put($key, $uris, self::SECONDS);

        return $uris;
    }

    /**
     * The route URIs one route file defines, in file order. Follows
     * `prefix('x')` on a registrar chain and into its `group(function () { … })`,
     * nested; a `Route::resource('photos')` yields its index, show, create and
     * edit URIs. Pure: no git, no side effects.
     *
     * @return list<string>
     */
    public function parse(string $php, string $file): array
    {
        $tokens = array_values(array_filter(
            token_get_all($php),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $base = isset(self::FILE_PREFIXES[$file]) ? [self::FILE_PREFIXES[$file]] : [];
        /** @var list<array{depth: int, prefixes: list<string>}> $scopes open groups, innermost last */
        $scopes = [];
        $pending = [];   // prefixes on the chain being read, not yet attached to a group or a route
        $grouping = null; // the pending prefixes, once `->group(` is seen, waiting for the closure's `{`
        $depth = 0;
        $uris = [];

        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($grouping !== null) {
                    $scopes[] = ['depth' => $depth, 'prefixes' => $grouping];
                    $grouping = null;
                }

                continue;
            }
            if ($text === '}') {
                if ($scopes !== [] && $scopes[array_key_last($scopes)]['depth'] === $depth) {
                    array_pop($scopes);
                }
                $depth--;

                continue;
            }
            if ($text === ';') {
                // A statement ends: a chain's prefix never outlives it, and a `group(base_path(...))` opened no scope.
                $pending = [];
                $grouping = null;

                continue;
            }
            // An identifier — `match` is a keyword token (T_MATCH) even in `Route::match(`, so not only T_STRING.
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_MATCH], true)) {
                continue;
            }

            $name = $token[1];
            $args = $this->stringArgument($tokens, $i);
            if ($name === 'prefix' && $args !== null) {
                $pending[] = $args;
            } elseif ($name === 'group' && ($tokens[$i + 1] ?? null) === '(') {
                $grouping = $pending;
                $pending = [];
            } elseif (in_array($name, self::VERBS, true) && $args !== null && $this->isRegistrarCall($tokens, $i)) {
                $prefixes = [...$base, ...array_merge(...array_column($scopes, 'prefixes')), ...$pending];
                $uri = $this->join([...$prefixes, $args]);
                if ($name !== 'resource') {
                    $uris[] = $uri;

                    continue;
                }
                // Resource URIs as Laravel names them: the parameter is the resource name.
                $param = '{'.Str::singular(basename($args)).'}';
                array_push($uris, $uri, "{$uri}/{$param}", "{$uri}/create", "{$uri}/{$param}/edit");
            }
        }

        return $uris;
    }

    /**
     * Whether the identifier at `$i` is a call on the router: `Route::get(` or a
     * chained `->get(` — not a function or a method of something else.
     *
     * @param  list<mixed>  $tokens
     */
    private function isRegistrarCall(array $tokens, int $i): bool
    {
        $before = $tokens[$i - 1] ?? null;

        return is_array($before) && in_array($before[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * The first string argument of the call whose name is at `$i`, skipping a
     * leading verb list (`match(['get', 'post'], 'uri')`), or null when it has
     * none — a URI built from a variable cannot be read statically.
     *
     * @param  list<mixed>  $tokens
     */
    private function stringArgument(array $tokens, int $i): ?string
    {
        if (($tokens[$i + 1] ?? null) !== '(') {
            return null;
        }
        $j = $i + 2;
        if (($tokens[$j] ?? null) === '[') {
            while (isset($tokens[$j]) && $tokens[$j] !== ']') {
                $j++;
            }
            $j += 2; // past `]` and `,`
        }
        $arg = $tokens[$j] ?? null;
        // A string followed by `.` is concatenated with something unknown: not a URI we can read.
        if (! is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$j + 1] ?? null) === '.') {
            return null;
        }

        return stripslashes(substr($arg[1], 1, -1));
    }

    /**
     * Join URI parts into one path with a single leading slash and none trailing.
     *
     * @param  list<string>  $parts
     */
    private function join(array $parts): string
    {
        $parts = array_values(array_filter(array_map(fn (string $p) => trim($p, '/'), $parts), fn (string $p) => $p !== ''));

        return '/'.implode('/', $parts);
    }
}
