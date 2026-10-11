<?php

/**
 * RD-1: kept backend code stands on its own. RD-9 deleted the old home and project
 * pages' actions that the first two arches here fenced off; RetireOldPagesTest now
 * holds that nothing references them.
 */
arch('ReadMockupSets reads the gate through the parser, not the writer')
    ->expect('App\Actions\Board\ReadMockupSets')
    ->not->toUse('App\Services\StoryPickWriter');

arch('the gate parser and the status order are pure: no git, no database, no log')
    ->expect(['App\Services\MockupGateParser', 'App\Services\StoryStatusOrder'])
    ->not->toUse(['App\Services\GitReader', 'Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Log', 'App\Models']);
