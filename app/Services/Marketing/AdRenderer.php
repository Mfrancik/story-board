<?php

namespace App\Services\Marketing;

use App\Exceptions\MarketingRenderException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Renders ad HTML to PNG with the Playwright Chromium the project already installs (MK-5), through one Node script,
 * `bin/render-ad.mjs`. The HTML goes to a temporary file and is opened over `file://`: no route, no server, and the
 * script refuses every request that is not a file or data URL, so a render can never reach the network.
 *
 * The timeout and the Node binary are constants here, not config (config/ is not this story's to change): Node is
 * found on the PATH of whoever runs the queue worker.
 */
class AdRenderer implements AdRendererClient
{
    /** The Node binary, looked up on the worker's PATH. */
    public const NODE_BINARY = 'node';

    /** The script that drives Chromium, relative to the app's root. */
    public const SCRIPT = 'bin/render-ad.mjs';

    /** Seconds one render (every size of one post, one browser start) may take before it is killed. */
    public const TIMEOUT_SECONDS = 60;

    /**
     * Render `$pages` in one browser run.
     *
     * Side effects: runs `node bin/render-ad.mjs` with fixed arguments (never a shell string); writes the HTML and PNGs
     * to a private temporary directory and removes it.
     *
     * @param  array<string, array{html: string, width: int, height: int}>  $pages
     * @return array<string, array{png: string, overflow: bool}>
     *
     * @throws MarketingRenderException `timeout`, `process_failed` or `invalid_output`
     */
    public function render(array $pages): array
    {
        $dir = storage_path('app/private/ad-render/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            $args = [self::NODE_BINARY, base_path(self::SCRIPT)];
            foreach ($pages as $key => $page) {
                $name = Str::slug((string) $key) ?: 'page';
                File::put("{$dir}/{$name}.html", $page['html']);
                array_push($args, "{$dir}/{$name}.html", "{$dir}/{$name}.png", (string) $page['width'], (string) $page['height']);
            }

            try {
                $result = Process::path(base_path())->timeout(self::TIMEOUT_SECONDS)->run($args);
            } catch (ProcessTimedOutException) {
                throw new MarketingRenderException('Rendering took longer than '.self::TIMEOUT_SECONDS.' seconds.', 'timeout');
            }
            if ($result->failed()) {
                // stderr is the script's own one-line reason (or Node's), never page content: safe to carry to the log.
                throw new MarketingRenderException(Str::limit(trim($result->errorOutput()) ?: 'The renderer exited with '.$result->exitCode().'.', 300), 'process_failed');
            }

            return $this->collect($pages, $dir, $result->output());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Read each page's PNG back and pair it with the script's overflow report (one JSON line on stdout).
     *
     * @param  array<string, array{html: string, width: int, height: int}>  $pages
     * @return array<string, array{png: string, overflow: bool}>
     *
     * @throws MarketingRenderException `invalid_output` when a PNG is missing or not the asked size
     */
    private function collect(array $pages, string $dir, string $stdout): array
    {
        $report = json_decode(trim((string) Str::of($stdout)->explode("\n")->filter()->last()), true);
        $overflows = is_array($report['overflow'] ?? null) ? $report['overflow'] : [];

        $out = [];
        $i = 0;
        foreach ($pages as $key => $page) {
            $name = Str::slug((string) $key) ?: 'page';
            $png = is_file("{$dir}/{$name}.png") ? (string) file_get_contents("{$dir}/{$name}.png") : '';
            $size = $png === '' ? false : getimagesizefromstring($png);
            if ($size === false || $size[0] !== $page['width'] || $size[1] !== $page['height']) {
                throw new MarketingRenderException("The renderer wrote no {$page['width']}×{$page['height']} image for {$key}.", 'invalid_output');
            }
            $out[$key] = ['png' => $png, 'overflow' => (bool) ($overflows[$i] ?? false)];
            $i++;
        }

        return $out;
    }
}
