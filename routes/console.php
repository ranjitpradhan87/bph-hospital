<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Frees times held for a booking whose advance was never paid. On shared hosting a cron job runs `php artisan schedule:run` every minute.
Artisan::command('appointments:release-holds', function () {
    $this->info(App\Services\BookingService::releaseExpiredHolds().' unpaid hold(s) released');
})->purpose('Release times held for unpaid booking advances');
Illuminate\Support\Facades\Schedule::command('appointments:release-holds')->everyMinute();
