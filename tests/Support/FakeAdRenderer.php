<?php

namespace Tests\Support;

use App\Exceptions\MarketingRenderException;
use App\Services\Marketing\AdRendererClient;

/**
 * The ad renderer every test gets (MK-5), bound in Tests\TestCase: it never starts a browser. Each page comes back as
 * a plain PNG of exactly the asked size (GD), so stored renders have real dimensions and an <img> of one shows in a
 * Browser test. `failWith()` makes the next renders throw, as a crashed or timed-out Chromium would. The one real
 * render lives in tests/Browser/Marketing/AdRendererTest.php.
 */
class FakeAdRenderer implements AdRendererClient
{
    /** @var list<array<string, array{html: string, width: int, height: int}>> every render asked for, in order */
    public array $calls = [];

    /** The reason the next renders fail with, or null to succeed. */
    public ?string $failure = null;

    /**
     * Make every following render fail with `$reason` (e.g. `timeout`, `process_failed`).
     */
    public function failWith(?string $reason): static
    {
        $this->failure = $reason;

        return $this;
    }

    /**
     * Record `$pages` and answer each with a flat PNG of its size, or throw the set failure.
     *
     * @param  array<string, array{html: string, width: int, height: int}>  $pages
     * @return array<string, array{png: string, overflow: bool}>
     *
     * @throws MarketingRenderException when failWith() was given a reason
     */
    public function render(array $pages): array
    {
        $this->calls[] = $pages;
        if ($this->failure !== null) {
            throw new MarketingRenderException('Fake renderer failure.', $this->failure);
        }

        return array_map(fn (array $page) => ['png' => self::png($page['width'], $page['height']), 'overflow' => false], $pages);
    }

    /**
     * A flat PNG of `$width` × `$height`.
     */
    public static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 183, 159, 130));
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
