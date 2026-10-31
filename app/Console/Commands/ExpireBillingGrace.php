<?php

namespace App\Console\Commands;

use App\Billing\BillingManager;
use Illuminate\Console\Command;

class ExpireBillingGrace extends Command
{
    protected $signature = 'serverscope:billing:expire-grace';

    protected $description = 'Return teams to Free when a failed payment outlived the grace period';

    public function handle(BillingManager $billing): int
    {
        $this->info('Teruggezet naar Free: '.$billing->expireGrace());

        return self::SUCCESS;
    }
}
