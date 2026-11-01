<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a cron entry running `php artisan schedule:run` every minute.
// The heartbeat lets ServerScope report whether its own scheduler runs.
Schedule::call(fn () => \App\Services\SystemHealth::beat())
    ->everyMinute()
    ->name('serverscope:heartbeat');

Schedule::command('serverscope:check-agents')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('serverscope:dispatch-domain-checks')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('serverscope:dispatch-page-checks')
    ->everyMinute()
    ->withoutOverlapping(5);

// Unanswered remote-probe confirmations fall back to a normal incident.
Schedule::command('serverscope:probe:expire')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('serverscope:escalate-incidents')
    ->everyMinute()
    ->withoutOverlapping(5);

// Aggregates the previous closed hour (hours close 11 minutes after
// they end, see MetricRollups) into hourly and daily rollups.
Schedule::command('serverscope:rollup')
    ->hourlyAt(12)
    ->withoutOverlapping(55);

Schedule::command('serverscope:analyze')
    ->hourlyAt(7)
    ->withoutOverlapping(55);

Schedule::command('serverscope:prune')
    ->dailyAt('03:17')
    ->withoutOverlapping(120);

// Processes queued jobs (e.g. alert e-mails) where no supervised worker
// runs, which is common on Plesk. See config/serverscope.php.
if (config('serverscope.scheduler_queue_worker')) {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(5);
}

Schedule::command('serverscope:billing:expire-grace')->daily();

Schedule::command('serverscope:invoices:issue')->daily();
