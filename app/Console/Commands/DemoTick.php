<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoTicker;
use Illuminate\Console\Command;

class DemoTick extends Command
{
    protected $signature = 'serverscope:demo:tick';

    protected $description = 'Add the next minute of sample measurements and passed checks to the demo teams';

    public function handle(DemoTicker $ticker): int
    {
        $done = $ticker->tick();
        $this->info("Metingen: {$done['samples']}, controles: {$done['checks']}");

        return self::SUCCESS;
    }
}
