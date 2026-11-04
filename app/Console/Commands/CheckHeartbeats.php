<?php

namespace App\Console\Commands;

use App\Services\Heartbeats\HeartbeatMonitor;
use Illuminate\Console\Command;

class CheckHeartbeats extends Command
{
    protected $signature = 'serverscope:check-heartbeats';

    protected $description = 'Open incidents for scheduled jobs that did not report in on time';

    public function handle(HeartbeatMonitor $monitor): int
    {
        $this->info('Te laat: '.$monitor->check());

        return self::SUCCESS;
    }
}
