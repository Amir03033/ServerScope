<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Domains\DomainCheckRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one check type for one domain. Unique per domain and type so
 * checks never overlap; not retried, because the next scheduled run is
 * the retry and a stale result would be misleading.
 */
class RunDomainCheck implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public int $domainId,
        public string $type,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->domainId}:{$this->type}";
    }

    public function handle(DomainCheckRunner $runner): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain === null || ! $domain->is_active || ! DomainCheckRunner::applies($domain, $this->type)) {
            return;
        }

        $runner->run($domain, $this->type);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Domain check failed', [
            'domain_id' => $this->domainId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }
}
