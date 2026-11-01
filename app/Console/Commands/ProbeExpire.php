<?php

namespace App\Console\Commands;

use App\Services\Probes\ProbeConfirmation;
use Illuminate\Console\Command;

/** Every minute: unanswered confirmation jobs fall back to the behaviour without a probe. */
class ProbeExpire extends Command
{
    protected $signature = 'serverscope:probe:expire';

    protected $description = 'Expire unanswered probe confirmation jobs';

    public function handle(ProbeConfirmation $confirmation): int
    {
        $this->info('Verlopen: '.$confirmation->expire());

        return self::SUCCESS;
    }
}
