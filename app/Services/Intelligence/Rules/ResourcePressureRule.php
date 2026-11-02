<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * Exhaustion or saturation of a server in scope just before the onset:
 * a high absolute level (metric_high) or a clear shift from the server's
 * own baseline (metric_shift, as computed for Time Machine). A matching
 * stored anomaly or forecast supports it. Never high: a busy server
 * before an outage is a correlation, not a proven cause.
 */
final class ResourcePressureRule implements Rule
{
    private const CODES = [
        'disk_percent' => 'disk_exhaustion',
        'memory_percent' => 'memory_exhaustion',
        'swap_percent' => 'swap_pressure',
        'cpu_percent' => 'cpu_saturation',
        'load_1' => 'cpu_saturation',
    ];

    public function evaluate(InvestigationContext $context): array
    {
        $groups = [];

        foreach ($context->items(['metric_high', 'metric_shift'], fn ($i) => $context->isUpstreamOrSubject($i['resource'])) as $key => $item) {
            $metric = explode(':', (string) $item['params']['metric'], 2)[0];
            $code = self::CODES[$metric] ?? null;

            if ($code !== null) {
                $groups[$item['resource'].'|'.$code][$key] = $item;
            }
        }

        $candidates = [];

        foreach ($groups as $group => $items) {
            [$server, $code] = explode('|', $group);
            $high = array_filter($items, fn ($i) => $i['code'] === 'metric_high') !== [];
            $metrics = array_keys(array_flip(array_map(fn ($i) => explode(':', (string) $i['params']['metric'], 2)[0], $items)));
            $predictions = array_keys($context->items(['anomaly', 'forecast'], fn ($i) => $i['resource'] === $server && in_array($i['params']['metric'], $metrics, true) && ! InvestigationContext::at($i)->gt($context->onset())));

            $base = ($high || $predictions !== []) ? 'medium' : 'low';

            if (! in_array($context->failureKind(), ['availability'], true) && $context->incident['source'] !== 'alert') {
                $base = 'low';
            }

            $candidates[] = new Candidate($code, $server, $base, array_values(array_filter(['precedes_onset', $high ? 'threshold_exceeded' : 'baseline_shift', $predictions !== [] ? 'predicted_earlier' : null, 'correlation_only'])),
                [...array_keys($items), ...$predictions], path: $context->path($server), sources: ['metrics', 'predictions'], metricsOf: $server, priority: 4);
        }

        return $candidates;
    }
}
