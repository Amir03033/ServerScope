<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Server;
use App\Services\Analytics\PredictiveAnalyzer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly anomaly detection and capacity forecasting for servers and
 * websites. A failure for one resource is logged and does not stop the
 * others.
 */
class AnalyzeMetrics extends Command
{
    protected $signature = 'serverscope:analyze';

    protected $description = 'Detect anomalies and forecast capacity from monitoring history';

    public function handle(PredictiveAnalyzer $analyzer): int
    {
        if (! config('serverscope.predictive.enabled', true)) {
            $this->info('Voorspellende monitoring staat uit.');

            return self::SUCCESS;
        }

        $totals = ['anomalies' => 0, 'forecasts' => 0, 'errors' => 0];

        foreach (Server::query()->whereNotNull('last_seen_at')->lazyById(100) as $server) {
            $this->analyze(fn () => $analyzer->analyzeServer($server), $totals, "server {$server->id}");
        }

        foreach (Domain::query()->where('is_active', true)->lazyById(100) as $domain) {
            $this->analyze(fn () => $analyzer->analyzeDomain($domain), $totals, "domain {$domain->id}");
        }

        $this->info("Afwijkingen: {$totals['anomalies']}, prognoses: {$totals['forecasts']}, fouten: {$totals['errors']}");

        return self::SUCCESS;
    }

    private function analyze(callable $analysis, array &$totals, string $label): void
    {
        try {
            $result = $analysis();
            $totals['anomalies'] += $result['anomalies'];
            $totals['forecasts'] += $result['forecasts'];
        } catch (Throwable $exception) {
            report($exception);
            $totals['errors']++;
            $this->warn("Analyse mislukt voor {$label}.");
        }
    }
}
