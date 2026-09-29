<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SB-18: a production snapshot every day just before local midnight, so a day with no visit to /prod
// still has a point. Runs only while the scheduler does (`php artisan schedule:work`).
Schedule::command('board:prod-snapshot')
    ->dailyAt('23:55')
    ->timezone((string) (config('board.timezone') ?: config('app.timezone')))
    ->withoutOverlapping();
