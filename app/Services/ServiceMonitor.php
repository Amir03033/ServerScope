<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServiceCheck;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Support\Severity;
use Carbon\CarbonInterface;

/**
 * Processes service states reported by the agent.
 *
 * - A service exists once the agent reports it (the operator lists the
 *   units to watch in the agent configuration).
 * - A service that the agent stops reporting, while it still reports
 *   others, becomes "stale"; that is not counted as a failure.
 * - stopped, failed and not_found count as failures; an incident opens
 *   after failure_threshold reports in a row and resolves when the
 *   service runs again. A service failure is never confused with an
 *   unreachable host: without agent data there are no service results.
 */
class ServiceMonitor
{
    public const MAX_SERVICES = 50;

    public function __construct(
        private IncidentManager $incidents,
    ) {}

    public static function fingerprint(ServiceCheck $service): string
    {
        return "service:{$service->id}";
    }

    /**
     * @param  array<int, array{name: string, state: string, detail?: ?string}>  $reports
     */
    public function record(Server $server, array $reports, CarbonInterface $now): void
    {
        $reported = [];

        foreach (array_slice($reports, 0, self::MAX_SERVICES) as $report) {
            $service = ServiceCheck::query()->firstOrCreate(
                ['server_id' => $server->id, 'name' => $report['name']],
                ['kind' => ServiceCheck::guessKind($report['name'])],
            );
            $reported[] = $service->id;

            if (! $service->is_enabled) {
                continue;
            }

            $this->apply($server, $service, $report['state'], $report['detail'] ?? null, $now);
        }

        // Enabled services missing from this report have no current data.
        ServiceCheck::query()
            ->where('server_id', $server->id)
            ->whereNotIn('id', $reported)
            ->where('status', '!=', 'stale')
            ->get()
            ->each(fn (ServiceCheck $service) => $this->transition($service, 'stale', 'Niet meer gerapporteerd door de agent', $now));
    }

    private function apply(Server $server, ServiceCheck $service, string $state, ?string $detail, CarbonInterface $now): void
    {
        $this->transition($service, $state, $detail, $now);
        $service->last_reported_at = $now;

        if ($state === 'running') {
            $service->last_running_at = $now;
            $service->consecutive_failures = 0;
            $service->failing_since = null;
            $service->save();

            $this->incidents->recover(self::fingerprint($service), "{$service->displayName()} draait weer.");

            return;
        }

        if (! in_array($state, ServiceCheck::FAILED_STATES, true)) {
            // starting/reloading/unknown: no conclusion yet.
            $service->save();

            return;
        }

        $service->consecutive_failures++;
        $service->failing_since ??= $now;
        $service->save();

        if ($service->consecutive_failures >= max(1, $service->failure_threshold) && ! $server->isInMaintenance($now)) {
            $label = ServiceCheck::STATUS_LABELS[$state] ?? $state;

            $this->incidents->trigger(new Signal(
                fingerprint: self::fingerprint($service),
                source: 'service',
                severity: Severity::isValid($service->severity) ? $service->severity : Severity::HIGH,
                title: "Service {$service->displayName()}: ".mb_strtolower($label),
                description: "De agent meldt dat {$service->name} ".mb_strtolower($label).' is'.($detail ? " ({$detail})" : '')." ({$service->consecutive_failures}× op rij). De server zelf rapporteert nog, dus dit is geen onbereikbare host.",
                subject: $service,
                context: ['state' => $state, 'detail' => $detail, 'server_id' => $server->id],
                escalationKey: $state,
                startedAt: $service->failing_since,
            ));
        }
    }

    private function transition(ServiceCheck $service, string $status, ?string $detail, CarbonInterface $now): void
    {
        if ($service->status !== $status) {
            $service->events()->create([
                'status' => $status,
                'previous_status' => $service->status,
                'detail' => $detail !== null ? mb_substr($detail, 0, 100) : null,
                'recorded_at' => $now,
            ]);
        }

        $service->status = $status;
        $service->state_detail = $detail !== null ? mb_substr($detail, 0, 100) : null;

        if ($status === 'stale') {
            $service->save();
        }
    }
}
