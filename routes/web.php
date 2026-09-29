<?php

use App\Http\Controllers\MockupFileController;
use App\Http\Middleware\EnsureProjectIsShown;
use App\Http\Middleware\RedirectProjectFilter;
use App\Livewire\Board\Home;
use App\Livewire\Board\StoryPage;
use App\Models\Story;
use Illuminate\Support\Facades\Route;

// The pre-SB-7 `/?project=x` filter URL now lives at /p/x.
Route::livewire('/', Home::class)->middleware(RedirectProjectFilter::class)->name('home');

// One project (SB-7): the home content pinned to that project until SB-10 replaces it.
// EnsureProjectIsShown is the one place an unknown or disabled project is refused.
Route::livewire('/p/{project:name}', Home::class)
    ->middleware(EnsureProjectIsShown::class)
    ->name('projects.show');

// One story above its mockups (SB-4). Only a well-formed story ID reaches the component.
Route::livewire('/p/{project:name}/s/{storyId}', StoryPage::class)
    ->where('storyId', Story::ID_ROUTE)
    ->middleware(EnsureProjectIsShown::class)
    ->name('stories.show');

// Raw mockup bytes from a project's ref (SB-4). `file` may contain slashes (shots/01.png);
// ReadMockupFile, not this pattern, decides what is allowed.
Route::get('/p/{project:name}/m/{storyId}/{file}', MockupFileController::class)
    ->where('file', '.*')
    ->name('mockups.file');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
