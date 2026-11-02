<?php

namespace App\Jobs;

use App\Models\Investigation;
use App\Services\Incidents\IncidentInvestigator;
use App\Services\Intelligence\InvestigationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one NEXUS Intelligence investigation. Deterministic and free, so
 * one retry is allowed; InvestigationRunner::run() only acts on a queued
 * run (or one left running by a crashed worker), so a duplicate delivery
 * does nothing. The payload is only the id: the team comes from the row.
 */
class RunInvestigation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout;

    public int $uniqueFor = 600;

    public function __construct(public int $investigationId)
    {
        // Below the scheduler worker's ~55 second run (routes/console.php).
        $this->timeout = max(10, (int) config('serverscope.intelligence.job_timeout_seconds', 45));
    }

    public function uniqueId(): string
    {
        return (string) $this->investigationId;
    }

    public function handle(InvestigationRunner $runner): void
    {
        $runner->run($this->investigationId);
    }

    /** A crashed or timed-out worker leaves the run failed, never stuck. */
    public function failed(?Throwable $exception): void
    {
        $failed = Investigation::query()->withoutGlobalScopes()->whereKey($this->investigationId)->whereIn('status', Investigation::PENDING)
            ->update(['status' => 'failed', 'error_code' => 'queue_failure', 'finished_at' => now(), 'updated_at' => now()]);

        // The automatic AI analysis of a new incident then falls back to the legacy contract.
        if ($failed === 1) {
            try {
                app(IncidentInvestigator::class)->afterInvestigation(Investigation::query()->withoutGlobalScopes()->findOrFail($this->investigationId));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
