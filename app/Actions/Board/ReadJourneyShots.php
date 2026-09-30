<?php

namespace App\Actions\Board;

use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The journey shots a project's own journey tests saved (SB-22's format): each
 * `storage/app/journey-shots/<journey>/manifest.json` in the project's working
 * tree, and the PNGs it lists. Read-only, and optional — a project that never
 * adopted SB-22 simply has none, and every caller falls back to a placeholder.
 *
 * The manifest is the whitelist. A shot is served only when a manifest names
 * it, its path resolves (symlinks included) inside that project's
 * `journey-shots/` folder, and the file is a PNG, JPEG or WebP. Anything else is
 * refused and logged as `board.journey_shot_refused`. Callers never pass a
 * path to this class: the file route names a journey folder and a file name,
 * and both are looked up among the entries read here — never joined onto a
 * path. The app map (SB-24) and the gallery's Current pane (SB-23) read shots
 * from here.
 *
 * @phpstan-type JourneyShot array{
 *     journey: string, step: string|null, route: string|null, story: string|null,
 *     captured_at: Carbon|null, commit: string|null, file: string, path: string
 * }
 */
class ReadJourneyShots
{
    /** The folder under the project's `storage/app/` that SB-22 writes to. */
    public const FOLDER = 'journey-shots';

    /** Picture types a journey test plausibly saves, with the type they are served as. */
    public const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    /** A width at or below this is SB-22's phone shot; the map shows the desktop one when there is one. */
    private const PHONE_WIDTH = 480;

    /** @var array<string, array<string, list<JourneyShot>>> per project path, so one page load reads each manifest once */
    private array $read = [];

    /**
     * Every usable shot of the project, grouped by journey folder name, each
     * journey's shots in manifest order. Empty when the project has no
     * `journey-shots/` folder (it has not adopted SB-22) or no manifest in it.
     *
     * Side effects: reads the working tree only; logs `board.journey_shot_refused`
     * (warning) once per manifest that is not JSON and per entry refused.
     *
     * @return array<string, list<JourneyShot>>
     */
    public function handle(Project $project): array
    {
        if (isset($this->read[$project->path])) {
            return $this->read[$project->path];
        }

        $root = realpath($project->path.'/storage/app/'.self::FOLDER);
        $shots = [];
        if ($root !== false && is_dir($root)) {
            foreach (glob($root.'/*/manifest.json') ?: [] as $manifest) {
                $journey = basename(dirname($manifest));
                $entries = $this->entries($project, $root, $journey, $manifest);
                if ($entries !== []) {
                    $shots[$journey] = $entries;
                }
            }
            ksort($shots);
        }

        return $this->read[$project->path] = $shots;
    }

    /**
     * The shot that pictures one journey step, or null: the entry naming the
     * step's story, else the one naming its route. A desktop shot is preferred
     * over SB-22's 375 px one for the same step.
     *
     * @param  array<string, list<JourneyShot>>  $shots  what handle() returned
     * @return JourneyShot|null
     */
    public function forStep(array $shots, string $journey, ?string $story, ?string $route): ?array
    {
        $candidates = $shots[$journey] ?? [];
        foreach ([['story', $story], ['route', $route]] as [$key, $value]) {
            if ($value === null) {
                continue;
            }
            $hits = array_values(array_filter($candidates, fn (array $shot) => $shot[$key] === $value));
            if ($hits !== []) {
                return $hits[0];
            }
        }

        return null;
    }

    /**
     * The absolute path of the file a manifest lists as `$file` in journey
     * folder `$journey`, for the shot route — or null (logged as refused) when no
     * manifest lists it. Both names are only compared with what handle() read,
     * so `../`, an encoded slash or a file sitting unlisted beside the manifest
     * can never be named.
     */
    public function file(Project $project, string $journey, string $file): ?string
    {
        foreach ($this->handle($project)[$journey] ?? [] as $shot) {
            if ($shot['file'] === $file) {
                return $shot['path'];
            }
        }

        Log::warning('board.journey_shot_refused', ['project' => $project->name, 'path' => self::FOLDER.'/'.$journey.'/'.$file, 'reason' => 'not in a manifest']);

        return null;
    }

    /**
     * One manifest's usable entries. Tolerant of shape: a list of entries or an
     * object holding one under `shots`, `steps` or `entries`; the file under
     * `file`, `path` or `png`.
     *
     * @return list<JourneyShot>
     */
    private function entries(Project $project, string $root, string $journey, string $manifest): array
    {
        $json = json_decode((string) file_get_contents($manifest), true);
        if (is_array($json) && ! array_is_list($json)) {
            $json = $json['shots'] ?? $json['steps'] ?? $json['entries'] ?? null;
        }
        if (! is_array($json) || ! array_is_list($json)) {
            Log::warning('board.journey_shot_refused', ['project' => $project->name, 'path' => self::FOLDER.'/'.$journey.'/manifest.json', 'reason' => 'not a JSON list of shots']);

            return [];
        }

        /** @var array<int, list<JourneyShot>> $shots desktop (0) and phone (1) shots */
        $shots = [];
        foreach ($json as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $named = $entry['file'] ?? $entry['path'] ?? $entry['png'] ?? null;
            if (! is_string($named) || $named === '') {
                continue;
            }
            $path = $this->resolve($root, $journey, $named);
            if ($path === null) {
                Log::warning('board.journey_shot_refused', ['project' => $project->name, 'path' => $named, 'reason' => 'outside '.self::FOLDER.'/']);

                continue;
            }
            $phone = is_numeric($entry['width'] ?? null) && (int) $entry['width'] <= self::PHONE_WIDTH;
            $shots[$phone ? 1 : 0][] = [
                'journey' => $journey,
                'step' => $this->text($entry['step'] ?? null),
                'route' => $this->text($entry['route'] ?? null),
                'story' => $this->text($entry['story'] ?? null),
                'captured_at' => $this->time($entry['captured_at'] ?? null),
                'commit' => $this->text($entry['commit'] ?? null),
                'file' => basename($path),
                'path' => $path,
            ];
        }

        // Desktop shots first, so forStep() picks one when a step has both widths.
        return [...$shots[0] ?? [], ...$shots[1] ?? []];
    }

    /**
     * The real path of a manifest's file, or null unless it is an existing
     * picture inside `$root` (the project's own journey-shots folder). A bare
     * name is relative to the manifest's folder, as SB-22 writes it; a path is
     * relative to the project's `storage/app/` (`journey-shots/<j>/01.png`) or
     * given from the project root (`storage/app/journey-shots/...`).
     */
    private function resolve(string $root, string $journey, string $named): ?string
    {
        if (str_contains($named, "\0") || str_contains($named, '\\') || str_starts_with($named, '/')) {
            return null;
        }
        $relative = preg_replace('#^storage/app/#', '', $named);
        $candidate = str_contains((string) $relative, '/')
            ? dirname($root).'/'.$relative
            : $root.'/'.$journey.'/'.$relative;

        // realpath() follows symlinks and `..`, so the containment check sees where the bytes really are.
        $real = realpath($candidate);
        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root.'/')) {
            return null;
        }

        return isset(self::TYPES[strtolower(pathinfo($real, PATHINFO_EXTENSION))]) ? $real : null;
    }

    /** A manifest string field, trimmed, or null. */
    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A manifest's `captured_at`, or null when it is missing or not a date. */
    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A capture time is a caption, not a gate: an unreadable one shows no time, the shot still shows.
            return null;
        }
    }
}
