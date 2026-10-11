<?php

namespace App\Actions\Board;

use App\Models\Project;
use App\Services\ProjectFilter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turn the home overview's `?projects=` value (RD-6, comma-separated project
 * names) into the ProjectFilter every home panel is handed. Names are matched
 * exactly against the board's projects; an unknown or disabled name is ignored,
 * so a stale bookmark or a typo narrows nothing rather than emptying the page.
 * Reads the projects table; writes nothing.
 */
class ResolveProjectFilter
{
    /** How many ignored names one warning lists: the URL is free text, and a log line must stay a line. */
    private const LOGGED_NAMES = 20;

    /**
     * The filter the raw value names, ignored names left out. With every name
     * ignored, or none given, the filter covers every enabled project.
     *
     * Side effects: with `$log`, one `board.overview_filter_ignored` warning per
     * reason (`unknown`, `disabled`) naming the names it dropped. The caller passes
     * `$log` false on a poll, which re-reads the same URL every 30 s.
     */
    public function handle(string $raw, bool $log = true): ProjectFilter
    {
        $asked = collect(explode(',', $raw))->map(fn (string $name) => trim($name))->filter(fn (string $name) => $name !== '')->unique()->values();
        if ($asked->isEmpty()) {
            return ProjectFilter::all();
        }

        // Exact names, compared in PHP: MySQL's collation would let `Coins` match `coins`.
        $known = Project::whereIn('name', $asked)->pluck('is_enabled', 'name');
        $enabled = $asked->filter(fn (string $name) => $known->get($name) === true);

        if ($log) {
            $this->logIgnored('unknown', $asked->reject(fn (string $name) => $known->has($name))->all());
            $this->logIgnored('disabled', $asked->filter(fn (string $name) => $known->get($name) === false)->all());
        }

        return ProjectFilter::only($enabled->all());
    }

    /**
     * Log one warning naming the names dropped for one reason, if there are any;
     * each name is cut short, since it came from the URL.
     *
     * @param  array<int, string>  $names
     */
    private function logIgnored(string $reason, array $names): void
    {
        if ($names === []) {
            return;
        }

        Log::warning('board.overview_filter_ignored', [
            'reason' => $reason,
            'names' => array_map(fn (string $name) => Str::limit($name, 60), array_slice(array_values($names), 0, self::LOGGED_NAMES)),
        ]);
    }
}
