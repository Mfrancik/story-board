<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\MockupFileController;
use Illuminate\Support\Facades\Route;

Route::get('/', BoardController::class)->name('home');

// Raw mockup bytes from a project's ref (SB-4). `file` may contain slashes (shots/01.png);
// ReadMockupFile, not this pattern, decides what is allowed.
Route::get('/p/{project:name}/m/{storyId}/{file}', MockupFileController::class)
    ->where('file', '.*')
    ->name('mockups.file');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
