<?php

namespace App\Jobs;

use App\Models\IncidentAnalysis;
use App\Services\Incidents\IncidentInvestigator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one queued AI analysis. Not retried automatically (each attempt
 * costs money); IncidentInvestigator::run() only acts on a queued
 * analysis, so a duplicate delivery does nothing.
 */
class RunIncidentAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $analysisId) {}

    public function uniqueId(): string
    {
        return (string) $this->analysisId;
    }

    public function handle(IncidentInvestigator $investigator): void
    {
        $investigator->run($this->analysisId);
    }

    /** A crashed or timed-out worker leaves the analysis failed, never stuck. */
    public function failed(?Throwable $exception): void
    {
        IncidentAnalysis::query()->withoutGlobalScopes()->whereKey($this->analysisId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_code' => 'queue_failure', 'completed_at' => now(), 'updated_at' => now()]);
    }
}
