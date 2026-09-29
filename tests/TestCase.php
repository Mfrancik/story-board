<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Laravel\Fortify\Features;

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
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
