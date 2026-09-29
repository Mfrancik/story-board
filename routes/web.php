<?php

use App\Http\Controllers\MockupFileController;
use App\Livewire\Board\Home;
use App\Livewire\Board\StoryPage;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Home::class)->name('home');

// One story above its mockups (SB-4). Only a well-formed story ID reaches the component.
Route::livewire('/p/{project:name}/s/{storyId}', StoryPage::class)
    ->where('storyId', '[A-Z]{2,}-[0-9]+[a-z]?')
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
