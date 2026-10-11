<?php

/**
 * RD-1: kept backend code stands on its own, untied from the pages the redesign
 * replaces. ListWhatNeedsMe and ReadProjectProgress serve the old home and
 * project pages; nothing kept may reach into them for shared rules.
 */
arch('ReadProjectStories depends on neither ListWhatNeedsMe nor ReadProjectProgress')
    ->expect('App\Actions\Board\ReadProjectStories')
    ->not->toUse(['App\Actions\Board\ListWhatNeedsMe', 'App\Actions\Board\ReadProjectProgress']);

arch('StoryPickWriter depends on neither ListWhatNeedsMe nor ReadProjectProgress')
    ->expect('App\Services\StoryPickWriter')
    ->not->toUse(['App\Actions\Board\ListWhatNeedsMe', 'App\Actions\Board\ReadProjectProgress']);

arch('ReadMockupSets reads the gate through the parser, not the writer')
    ->expect('App\Actions\Board\ReadMockupSets')
    ->not->toUse('App\Services\StoryPickWriter');

arch('the gate parser and the status order are pure: no git, no database, no log')
    ->expect(['App\Services\MockupGateParser', 'App\Services\StoryStatusOrder'])
    ->not->toUse(['App\Services\GitReader', 'Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Log', 'App\Models']);
