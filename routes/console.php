<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Clean up old board invitations daily at 2 AM
Schedule::command('invitations:cleanup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();