<?php

use App\Http\Controllers\MockupFileController;
use App\Http\Middleware\EnsureProjectIsShown;
use App\Http\Middleware\RedirectProjectFilter;
use App\Livewire\Board\Home;
use App\Livewire\Board\ManageProjects;
use App\Livewire\Board\ProductionDashboard;
use App\Livewire\Board\ProjectHandbook;
use App\Livewire\Board\ProjectPage;
use App\Livewire\Board\StoryPage;
use App\Models\Story;
use Illuminate\Support\Facades\Route;

// The pre-SB-7 `/?project=x` filter URL now lives at /p/x.
Route::livewire('/', Home::class)->middleware(RedirectProjectFilter::class)->name('home');

// One project's dashboard (SB-10; the route is SB-7's). EnsureProjectIsShown is the one
// place an unknown or disabled project is refused, before binding (ADR-013).
Route::livewire('/p/{project:name}', ProjectPage::class)
    ->middleware(EnsureProjectIsShown::class)
    ->name('projects.show');

// One project's handbook (SB-14): its rules, lessons, standards, runbook, decisions and skills,
// with each standards file and kit skill badged against the dev-standards kit.
Route::livewire('/p/{project:name}/handbook', ProjectHandbook::class)
    ->middleware(EnsureProjectIsShown::class)
    ->name('projects.handbook');

// One story above its mockups (SB-4). Only a well-formed story ID reaches the component.
Route::livewire('/p/{project:name}/s/{storyId}', StoryPage::class)
    ->where('storyId', Story::ID_ROUTE)
    ->middleware(EnsureProjectIsShown::class)
    ->name('stories.show');

// Switch projects on and off, add and remove them (SB-12). No auth: the board is localhost-only;
// a hosted board (SB-6) would need auth in front of this page first.
Route::livewire('/projects', ManageProjects::class)->name('projects.manage');

// Every connected project's production numbers side by side (SB-18). Reads go through the queue and
// ProductionReader; the page itself only reads the board's own database.
Route::livewire('/prod', ProductionDashboard::class)->name('prod');

// Raw mockup bytes from a project's ref (SB-4). `file` may contain slashes (shots/01.png);
// ReadMockupFile, not this pattern, decides what is allowed.
Route::get('/p/{project:name}/m/{storyId}/{file}', MockupFileController::class)
    ->where('file', '.*')
    ->name('mockups.file');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
