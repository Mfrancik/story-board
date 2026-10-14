<?php

namespace Tests;

use App\Services\Marketing\AdRendererClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeAdRenderer;

abstract class TestCase extends BaseTestCase
{
    /**
     * Point the Live sessions panel (SB-11) at an empty transcripts root. Every board
     * page renders the panel and the sidebar badge, and without this they would read
     * the developer's real `~/.claude/projects`, which tests must never touch.
     * A test that needs sessions sets `board.sessions_path` to its own fixture root.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $empty = sys_get_temp_dir().'/story-board-sessions/empty';
        File::ensureDirectoryExists($empty);
        config(['board.sessions_path' => $empty]);

        // MK-5: renders run inline on the sync queue after Fill, Revise and photo changes; a real one would start
        // Chromium in every such test. The one real render is tests/Browser/Marketing/AdRendererTest.php.
        $this->app->instance(AdRendererClient::class, new FakeAdRenderer);
    }
}
