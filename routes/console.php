<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a cron entry running `php artisan schedule:run` every minute.
Schedule::command('serverscope:check-agents')
    ->everyMinute()
    ->withoutOverlapping(5);
