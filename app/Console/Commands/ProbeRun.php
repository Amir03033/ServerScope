<?php

namespace App\Console\Commands;

use App\Services\Probes\ProbeAgent;
use Illuminate\Console\Command;
use Throwable;

/** Runs the remote probe on its own host: poll, check, report. */
class ProbeRun extends Command
{
    protected $signature = 'serverscope:probe:run {--once : One poll instead of running continuously}';

    protected $description = 'Run the remote confirmation probe (on the probe host)';

    public function handle(ProbeAgent $agent): int
    {
        do {
            try {
                $result = $agent->runOnce();
                $this->line(now()->toIso8601String()." jobs: {$result['jobs']}, accepted: {$result['accepted']}");
            } catch (Throwable $exception) {
                // Never print secrets or response bodies.
                $this->error(now()->toIso8601String().' probe run failed: '.class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 200));

                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }

            if (! $this->option('once')) {
                sleep(max(10, (int) config('serverscope.probe.poll_seconds', 30)));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
