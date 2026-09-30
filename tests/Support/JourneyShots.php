<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pest\Browser\Support\Screenshot;
use Symfony\Component\Process\Process;

/**
 * Journey step screenshots (SB-22, a dev-standards kit helper): a journey test
 * calls `journeyStep()` at each step it walks, and with `JOURNEY_SHOTS=1` this
 * saves the page as a desktop and a 375 px PNG under
 * `storage/app/journey-shots/<journey>/` plus a `manifest.json` listing both.
 * The board (story-board's `ReadJourneyShots`) reads that manifest to show what
 * each screen looks like today, so its shape is a contract: a JSON list, one
 * entry per PNG, with `journey`, `step`, `route`, `story`, `file` (bare name),
 * `width`, `captured_at` and `commit`.
 *
 * Off by default, so a normal test run (and preflight) pays nothing for it.
 */
class JourneyShots
{
    /** Viewport of the desktop shot, in CSS px. */
    public const DESKTOP = ['width' => 1280, 'height' => 800];

    /** Viewport of the phone shot; the board treats any width <= 480 as the phone one. */
    public const PHONE = ['width' => 375, 'height' => 812];

    /** Where shots go, under the project root. */
    public const FOLDER = 'storage/app/journey-shots';

    /**
     * The project root the shots, journey docs and commit are read from and
     * written to. Null means the app's own `base_path()`; the helper's own tests
     * point it at a throwaway repo so they never touch the real folder.
     */
    public static ?string $project = null;

    /** @var array<string, int> journey => steps captured so far in this run */
    private static array $started = [];

    /** @var array<string, string> project root => HEAD, read once per run ('' when git cannot say) */
    private static array $commits = [];

    /**
     * Visit `$route` (or use `$page`, the page a test is already on) and, when
     * `JOURNEY_SHOTS=1`, save it as step `$step` of `$journey`. Returns the page
     * so the test carries on asserting against it; with capture off the page
     * is returned untouched and nothing is written.
     *
     * Side effects (capture on only): the first step of a journey in a run
     * deletes that journey's PNGs and manifest, so a re-run replaces instead of
     * appending; each step writes two PNGs and rewrites the manifest. The
     * viewport is put back to its size before the capture.
     *
     * @param  string  $route  the path the step shows, recorded for the board to match against the journey doc
     *
     * @throws InvalidArgumentException when the journey or step slugs to nothing
     */
    public static function step(string $journey, string $step, string $route, ?object $page = null): object
    {
        $page ??= visit($route);
        if (! self::enabled()) {
            return $page;
        }

        $journey = self::slug($journey, 'journey');
        $name = self::slug($step, 'step');
        $dir = self::root().'/'.$journey;
        $number = self::begin($journey, $dir);
        $base = sprintf('%02d-%s', $number, $name);

        /** @var array{width: int, height: int} $was */
        $was = $page->script('({ width: window.innerWidth, height: window.innerHeight })');
        $entries = [];
        foreach ([[self::DESKTOP, $base.'.png'], [self::PHONE, $base.'-375.png']] as [$size, $file]) {
            $page->resize($size['width'], $size['height']);
            self::capture($page, $dir.'/'.$file);
            // `story` only when the journey doc names one, as the manifest format says.
            $entries[] = array_filter([
                'journey' => $journey,
                'step' => $step,
                'route' => $route,
                'story' => self::story($journey, $number, $route),
                'file' => $file,
                'width' => $size['width'],
                'captured_at' => now()->toIso8601String(),
                'commit' => self::commit(),
            ], fn (mixed $value) => $value !== null);
        }
        $page->resize((int) $was['width'], (int) $was['height']);

        $manifest = $dir.'/manifest.json';
        $existing = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : [];
        file_put_contents($manifest, json_encode([...(is_array($existing) ? $existing : []), ...$entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $page;
    }

    /**
     * Whether capture is on. Read from the process environment only (not
     * `$_SERVER`/`.env`), so `JOURNEY_SHOTS=1 php artisan test …` is the one
     * switch and a test can turn it off again with `putenv()`.
     */
    public static function enabled(): bool
    {
        return getenv('JOURNEY_SHOTS') === '1';
    }

    /**
     * Start a new run: the next step of every journey replaces that journey's
     * shots again. A run is one PHP process, so this is only needed by tests
     * that simulate a second run inside one process.
     */
    public static function newRun(): void
    {
        self::$started = [];
        self::$commits = [];
    }

    /** The absolute shots folder: `<project>/storage/app/journey-shots`. */
    public static function root(): string
    {
        return (self::$project ?? base_path()).'/'.self::FOLDER;
    }

    /**
     * A name as a file-safe slug. Slashes and backslashes become separators
     * first, because Str::slug() would otherwise glue `a/b` into `ab`; what is
     * left is `[a-z0-9-]`, so a name can never climb out of the journey folder.
     *
     * @throws InvalidArgumentException when nothing usable is left
     */
    public static function slug(string $name, string $what = 'name'): string
    {
        $slug = Str::slug(str_replace(['/', '\\'], ' ', $name));
        if ($slug === '') {
            throw new InvalidArgumentException("journeyStep(): the {$what} \"{$name}\" has no letters or digits to name a file with.");
        }

        return $slug;
    }

    /**
     * The step's number in this run, clearing the journey's previous shots on
     * its first step. Only PNGs and the manifest are deleted — never anything
     * else someone put in the folder.
     */
    private static function begin(string $journey, string $dir): int
    {
        if (! isset(self::$started[$journey])) {
            File::ensureDirectoryExists($dir);
            foreach ([...glob($dir.'/*.png') ?: [], $dir.'/manifest.json'] as $old) {
                File::delete($old);
            }
            self::$started[$journey] = 0;
        }

        return ++self::$started[$journey];
    }

    /**
     * Save a full-page PNG of the page's current viewport at `$target`. Pest
     * only writes screenshots into its own `tests/Browser/Screenshots/`, so the
     * shot is taken there under a unique name and moved out at once.
     */
    private static function capture(object $page, string $target): void
    {
        $temp = 'journey-shot-'.bin2hex(random_bytes(6));
        $page->screenshot(true, $temp);
        File::move(Screenshot::path($temp), $target);

        // Leave no empty Screenshots folder behind; rmdir refuses when Pest has put a failure shot there.
        @rmdir(Screenshot::dir());
    }

    /**
     * The story the journey doc (`docs/journeys/<journey>.md`) names for this
     * step, or null. Journey docs are free-form, so: the first story ID on a
     * flow-list item or table row that names the route as a code span; else
     * on the flow item (or table row) numbered like the step.
     */
    private static function story(string $journey, int $number, string $route): ?string
    {
        $doc = (self::$project ?? base_path()).'/docs/journeys/'.$journey.'.md';
        if (! is_file($doc)) {
            return null;
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($doc)), fn (string $l) => preg_match('/^\s*(\d+[a-z]?\.|\|)/', $l) === 1);
        $byRoute = array_filter($lines, fn (string $l) => str_contains($l, '`'.$route.'`'));
        // A flow item "3. …" or a stories-table row "| 3 | …".
        $byNumber = array_filter($lines, fn (string $l) => preg_match('/^\s*(?:'.$number.'\.|\|\s*'.$number.'\s*\|)/', $l) === 1);

        foreach ([$byRoute, $byNumber] as $candidates) {
            foreach ($candidates as $line) {
                if (preg_match('/\b[A-Z]{2,}-[0-9]+[a-z]?\b/', $line, $id) === 1) {
                    return $id[0];
                }
            }
        }

        return null;
    }

    /** The project's HEAD as a short hash, or null when git cannot say (not a repo, no commits). */
    private static function commit(): ?string
    {
        $root = self::$project ?? base_path();
        if (! isset(self::$commits[$root])) {
            $git = new Process(['git', 'rev-parse', '--short', 'HEAD'], $root);
            $git->run();
            self::$commits[$root] = $git->isSuccessful() ? trim($git->getOutput()) : '';
        }

        return self::$commits[$root] === '' ? null : self::$commits[$root];
    }
}
