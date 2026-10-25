<?php

namespace App\Events;

use App\Models\DnsChange;
use App\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;

class DnsRecordsChanged
{
    use Dispatchable;

    /**
     * @param  array<int, DnsChange>  $changes  only the record types configured as important
     */
    public function __construct(
        public Domain $domain,
        public array $changes,
    ) {}
}
