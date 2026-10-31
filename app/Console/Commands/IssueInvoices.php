<?php

namespace App\Console\Commands;

use App\Billing\InvoiceService;
use Illuminate\Console\Command;

class IssueInvoices extends Command
{
    protected $signature = 'serverscope:invoices:issue';

    protected $description = 'Issue draft invoices once the seller details (SERVERSCOPE_COMPANY_*) are complete';

    public function handle(InvoiceService $invoices): int
    {
        if (! $invoices->sellerComplete()) {
            $this->warn('Seller details are incomplete (SERVERSCOPE_COMPANY_NAME, _KVK, _ADDRESS, _EMAIL): nothing issued.');

            return self::SUCCESS;
        }

        $this->info('Facturen uitgegeven: '.$invoices->issueDrafts());

        return self::SUCCESS;
    }
}
