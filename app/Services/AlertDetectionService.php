<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\MetricSample;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AlertDetectionService
{
    public function evaluate(MetricSample $sample): void
    {
        $rules = AlertRule::query()
            ->where('is_enabled', true)
            ->get();

        foreach ($rules as $rule) {
            foreach ($this->getMetricValues($sample, $rule) as $metric => $value) {
                $dimension = null;

                if (str_starts_with($metric, 'disk_percent:')) {
                    $dimension = substr($metric, strlen('disk_percent:'));
                    $metric = 'disk_percent';
                }

                $this->evaluateRule(
                    sample: $sample,
                    rule: $rule,
                    metric: $metric,
                    dimension: $dimension,
                    value: $value,
                );
            }
        }
    }

    /** @return array<string, float> */
    private function getMetricValues(MetricSample $sample, AlertRule $rule): array
    {
        return match ($rule->metric) {
            'cpu_percent' => $sample->cpu_percent === null
                ? []
                : ['cpu_percent' => (float) $sample->cpu_percent],

            'memory_percent' => ($value = $this->memoryPercent($sample)) === null
                ? []
                : ['memory_percent' => $value],

            'disk_percent' => $this->diskPercentages($sample),

            default => [],
        };
    }

    private function memoryPercent(MetricSample $sample): ?float
    {
        if (
            $sample->memory_total_bytes === null
            || $sample->memory_total_bytes <= 0
            || $sample->memory_used_bytes === null
        ) {
            return null;
        }

        return ($sample->memory_used_bytes / $sample->memory_total_bytes) * 100;
    }

    /** @return array<string, float> */
    private function diskPercentages(MetricSample $sample): array
    {
        $values = [];

        foreach ($sample->disks ?? [] as $disk) {
            if (
                ! is_array($disk)
                || ! isset($disk['path'], $disk['percent'])
                || ! is_string($disk['path'])
                || ! is_numeric($disk['percent'])
            ) {
                continue;
            }

            $path = trim($disk['path']);
            $percent = (float) $disk['percent'];

            if (
                $path === ''
                || mb_strlen($path) > 255
                || $percent < 0
                || $percent > 100
            ) {
                continue;
            }

            $values['disk_percent:' . $path] = $percent;
        }

        return $values;
    }

    private function evaluateRule(
        MetricSample $sample,
        AlertRule $rule,
        string $metric,
        ?string $dimension,
        float $value,
    ): void {
        DB::transaction(function () use (
            $sample,
            $rule,
            $metric,
            $dimension,
            $value,
        ): void {
            // Serialise alert evaluation for the same server.
            Server::query()
                ->whereKey($sample->server_id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeAlert = Alert::query()
                ->where('server_id', $sample->server_id)
                ->where('alert_rule_id', $rule->id)
                ->where('metric', $metric)
                ->where('dimension', $dimension)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $isTriggered = $this->matchesThreshold($value, $rule);

            if (! $isTriggered) {
                if ($activeAlert !== null) {
                    $activeAlert->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                        'observed_value' => $value,
                    ]);
                }

                return;
            }

            if ($activeAlert !== null) {
                $activeAlert->update([
                    'observed_value' => $value,
                ]);

                return;
            }

            $startedAt = $this->thresholdStartedAt(
                sample: $sample,
                rule: $rule,
                metric: $metric,
                dimension: $dimension,
            );

            if ($startedAt === null) {
                return;
            }

            Alert::query()->create([
                'server_id' => $sample->server_id,
                'alert_rule_id' => $rule->id,
                'metric' => $metric,
                'dimension' => $dimension,
                'observed_value' => $value,
                'threshold' => $rule->threshold,
                'status' => 'active',
                'triggered_at' => $startedAt,
            ]);
        }, attempts: 3);
    }

    private function matchesThreshold(float $value, AlertRule $rule): bool
    {
        return match ($rule->operator) {
            '>=' => $value >= $rule->threshold,
            '>' => $value > $rule->threshold,
            '<=' => $value <= $rule->threshold,
            '<' => $value < $rule->threshold,
            default => false,
        };
    }

    private function thresholdStartedAt(
        MetricSample $sample,
        AlertRule $rule,
        string $metric,
        ?string $dimension,
    ): ?Carbon {
        $duration = max(1, $rule->duration_minutes);
        $start = $sample->collected_at->copy()->subMinutes($duration);

        $samples = MetricSample::query()
            ->where('server_id', $sample->server_id)
            ->where('collected_at', '>=', $start)
            ->where('collected_at', '<=', $sample->collected_at)
            ->orderBy('collected_at')
            ->get();

        if ($samples->isEmpty()) {
            return null;
        }

        $maximumGapSeconds = 90;
        $firstSample = $samples->first();
        $lastSample = $samples->last();

        // Allow minor timestamp drift from the agent's polling interval.
        if ($firstSample->collected_at->gt($start->copy()->addSeconds(30))) {
            return null;
        }

        if ($lastSample->collected_at->lt($sample->collected_at)) {
            return null;
        }

        $previousSample = null;

        foreach ($samples as $candidate) {
            if (
                $previousSample !== null
                && $previousSample->collected_at
                    ->diffInSeconds($candidate->collected_at) > $maximumGapSeconds
            ) {
                return null;
            }

            $candidateValue = $this->getValueForMetric(
                $candidate,
                $metric,
                $dimension,
            );

            if (
                $candidateValue === null
                || ! $this->matchesThreshold($candidateValue, $rule)
            ) {
                return null;
            }

            $previousSample = $candidate;
        }

        return $start;
    }

    private function getValueForMetric(
        MetricSample $sample,
        string $metric,
        ?string $dimension,
    ): ?float {
        if ($metric === 'cpu_percent') {
            return $sample->cpu_percent === null
                ? null
                : (float) $sample->cpu_percent;
        }

        if ($metric === 'memory_percent') {
            return $this->memoryPercent($sample);
        }

        if ($metric === 'disk_percent' && $dimension !== null) {
            foreach ($this->diskPercentages($sample) as $key => $value) {
                if ($key === 'disk_percent:' . $dimension) {
                    return $value;
                }
            }
        }

        return null;
    }
}
