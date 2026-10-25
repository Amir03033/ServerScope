<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;

/**
 * Connects server alerts to incidents.
 *
 * An alert is the state of one rule on one server (and mountpoint); the
 * incident is the actionable record people work with. Repeated
 * evaluations of an active alert do not touch the incident. Notifications
 * are sent for incident lifecycle events (see IncidentNotifier).
 */
class AlertObserver
{
    public function __construct(
        private IncidentManager $incidents,
    ) {}

    public static function fingerprint(Alert $alert): string
    {
        return implode(':', ['alert', $alert->alert_rule_id, $alert->server_id, $alert->metric, $alert->dimension ?? '-']);
    }

    public function created(Alert $alert): void
    {
        if ($alert->status !== 'active') {
            return;
        }

        $alert->loadMissing(['server', 'alertRule']);
        $rule = $alert->alertRule;
        $server = $alert->server;
        $serverName = $server?->name ?? 'onbekende server';
        $unit = AlertRule::metricUnit($alert->metric);
        $isAgent = $alert->metric === AlertRule::METRIC_AGENT_OFFLINE;

        $incident = $this->incidents->trigger(new Signal(
            fingerprint: self::fingerprint($alert),
            source: $isAgent ? 'agent' : 'alert',
            severity: $rule?->severity ?? ($isAgent ? 'critical' : 'warning'),
            title: $isAgent
                ? "{$serverName} reageert niet (agent offline)"
                : ($rule?->name ?? AlertRule::metricLabel($alert->metric))." op {$serverName}".($alert->dimension ? " ({$alert->dimension})" : ''),
            description: $isAgent
                ? 'Geen metingen ontvangen sinds '.$alert->triggered_at?->format('d-m-Y H:i').' UTC (drempel '.number_format($alert->threshold, 0).' minuten).'
                : AlertRule::metricLabel($alert->metric).' is '.number_format($alert->observed_value, 1, ',', '.').$unit
                    .' (drempel '.($rule?->operator ?? '>=').' '.number_format($alert->threshold, 1, ',', '.').$unit.').',
            subject: $server,
            context: ['alert_id' => $alert->id, 'metric' => $alert->metric, 'dimension' => $alert->dimension],
        ));

        $alert->forceFill(['incident_id' => $incident->id])->saveQuietly();
    }

    public function updated(Alert $alert): void
    {
        if (! $alert->wasChanged('status') || $alert->getOriginal('status') !== 'active') {
            return;
        }

        $message = match ($alert->status) {
            'resolved' => 'De alert is hersteld: waarde '.number_format($alert->observed_value, 1, ',', '.').AlertRule::metricUnit($alert->metric).'.',
            'disabled' => 'De alertregel is uitgeschakeld.',
            default => 'De alert is beëindigd.',
        };

        // Disabling a rule is a configuration change, not a recovery.
        $this->incidents->recover(self::fingerprint($alert), $message, notify: $alert->status !== 'disabled');
    }
}
