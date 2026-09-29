<?php

use App\Http\Controllers\BoardController;
use Illuminate\Support\Facades\Route;

Route::get('/', BoardController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
