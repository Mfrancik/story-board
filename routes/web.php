<?php

use App\Http\Controllers\JourneyShotController;
use App\Http\Controllers\MockupFileController;
use App\Http\Controllers\MockupSetFileController;
use App\Http\Middleware\EnsureProjectIsShown;
use App\Http\Middleware\RefreshStaleProjects;
use App\Livewire\Board\ManageProjects;
use App\Livewire\Board\MockupGallery;
use App\Livewire\Board\MockupViewer;
use App\Livewire\Board\Overview;
use App\Livewire\Board\ProjectAppMap;
use App\Livewire\Board\ProjectHandbook;
use App\Livewire\Board\ProjectPreflight;
use App\Livewire\Board\ProjectStories;
use App\Livewire\Board\StoryPage;
use App\Models\Story;
use Illuminate\Support\Facades\Route;

// The board's pages (RD-2): every page load queues a refresh of each stale enabled project. The file routes
// below are left out on purpose — a page's frames and images hit them many times per load.
Route::middleware(RefreshStaleProjects::class)->group(function () {
    // The home overview (RD-5). RD-9 retired the old dashboard, the bare `/p/{project}` page and the legacy
    // `/?project=` redirect: a stray `project` query is now ignored and the overview renders unfiltered.
    Route::livewire('/', Overview::class)->name('home');

    // EnsureProjectIsShown, on each project page below, is the one place an unknown or disabled project is
    // refused, before binding (ADR-013).

    // One project's handbook (SB-14): its rules, lessons, standards, runbook, decisions and skills,
    // with each standards file and kit skill badged against the dev-standards kit.
    Route::livewire('/p/{project:name}/handbook', ProjectHandbook::class)
        ->middleware(EnsureProjectIsShown::class)
        ->name('projects.handbook');

    // One project's stories grouped by initiative (SB-15), each tagged with its status. The whole list
    // renders once; selecting an initiative, the filters and Expand all are Alpine.
    Route::livewire('/p/{project:name}/stories', ProjectStories::class)
        ->middleware(EnsureProjectIsShown::class)
        ->name('projects.stories');

    // One project's preflight runs (SB-16), read from its preflight-cost.csv files under the Claude
    // projects root on each load. The filters and charts are Alpine over the page's own data.
    Route::livewire('/p/{project:name}/preflight', ProjectPreflight::class)
        ->middleware(EnsureProjectIsShown::class)
        ->name('projects.preflight');

    // One project's app map (SB-24): its journeys as screens, built from its journey docs, story files, mockups
    // and — when it has adopted SB-22 — its journey shots. Stepping through a flow is Alpine.
    Route::livewire('/p/{project:name}/map', ProjectAppMap::class)
        ->middleware(EnsureProjectIsShown::class)
        ->name('projects.map');

    // One story above its mockups (SB-4). Only a well-formed story ID reaches the component.
    Route::livewire('/p/{project:name}/s/{storyId}', StoryPage::class)
        ->where('storyId', Story::ID_ROUTE)
        ->middleware(EnsureProjectIsShown::class)
        ->name('stories.show');

    // Switch projects on and off, add and remove them (SB-12). No auth: the board is localhost-only;
    // a hosted board (SB-6) would need auth in front of this page first.
    Route::livewire('/projects', ManageProjects::class)->name('projects.manage');

    // Every enabled project's mockup sets (SB-21) and a full-screen viewer per set; the set's files are
    // served by `mockups.frame` below.
    Route::livewire('/mockups', MockupGallery::class)->name('mockups');
    Route::livewire('/mockups/{project:name}/{story}', MockupViewer::class)
        ->where('story', Story::ID_ROUTE)
        ->middleware(EnsureProjectIsShown::class)
        ->name('mockups.show');
});

// One journey shot from a project's working tree (SB-24). `file` accepts anything, slashes included, so a
// traversal reaches ReadJourneyShots and is refused and logged there: only names a manifest lists are served.
Route::get('/shots/{project:name}/{journey}/{file}', JourneyShotController::class)
    ->where('file', '.*')
    ->middleware(EnsureProjectIsShown::class)
    ->name('shots.file');

// Raw mockup bytes from a project's ref (SB-4). `file` may contain slashes (shots/01.png);
// ReadMockupFile, not this pattern, decides what is allowed.
Route::get('/p/{project:name}/m/{storyId}/{file}', MockupFileController::class)
    ->where('file', '.*')
    ->name('mockups.file');

// A mockup set's files for its sandboxed viewer frames (SB-21). The file route answers only names in the set
// directory's own listing at the ref; `mockups.file` was already SB-4's name for the story page's route.
Route::get('/mockups/{project:name}/{story}/file/{file}', MockupSetFileController::class)
    ->where(['story' => Story::ID_ROUTE, 'file' => '.*'])
    ->middleware(EnsureProjectIsShown::class)
    ->name('mockups.frame');
