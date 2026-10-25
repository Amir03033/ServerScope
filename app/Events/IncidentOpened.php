<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentOpened
{
    use Dispatchable;

    public function __construct(
        public Incident $incident,
    ) {}
}
