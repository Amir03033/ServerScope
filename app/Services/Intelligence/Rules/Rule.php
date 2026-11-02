<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * One deterministic hypothesis rule. Rules only read the context (the
 * evidence snapshot); they never query the database, so their outcome is
 * fully determined by the stored evidence.
 */
interface Rule
{
    /** @return array<int, Candidate> */
    public function evaluate(InvestigationContext $context): array;
}
