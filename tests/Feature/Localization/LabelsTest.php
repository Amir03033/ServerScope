<?php

namespace Tests\Feature\Localization;

use App\Services\Reports\StatusPagePresenter;
use App\Support\Severity;
use Tests\TestCase;

/**
 * The views translate stored codes via lang/{en,nl}/*.php. The Dutch texts
 * must stay exactly what the constants in the code say.
 */
class LabelsTest extends TestCase
{
    public function test_dutch_labels_equal_the_constants(): void
    {
        $nl = fn (string $key) => trans($key, [], 'nl');

        $this->assertSame(Severity::LABELS, array_intersect_key($nl('labels.severity'), Severity::LABELS));
        $this->assertSame(array_keys(Severity::LABELS), array_keys(array_intersect_key($nl('labels.severity'), Severity::LABELS)));
        $this->assertEqualsCanonicalizing(array_keys(Severity::LABELS), array_keys($nl('labels.severity')));

        $this->assertSame(StatusPagePresenter::STATE_LABELS, $nl('status_page.state'));
    }

    public function test_dutch_model_labels_equal_the_model_constants(): void
    {
        foreach ($this->modelLabels() as $group => $constant) {
            $this->assertSame($constant, trans("labels.{$group}", [], 'nl'), "lang/nl/labels.php {$group}");
            $this->assertEqualsCanonicalizing(array_keys($constant), array_keys(trans("labels.{$group}", [], 'en')), "lang/en/labels.php {$group}");
        }
    }

    /** @return array<string, array<string, string>> */
    private function modelLabels(): array
    {
        return [
            'server_environment' => \App\Models\Server::ENVIRONMENTS,
            'server_status' => \App\Models\Server::STATUS_LABELS,
            'service_kind' => \App\Models\ServiceCheck::KINDS,
            'service_status' => \App\Models\ServiceCheck::STATUS_LABELS,
            'update_status' => \App\Models\AgentUpdateRequest::STATUS_LABELS,
            'update_reason' => \App\Models\AgentUpdateRequest::REASONS + \App\Models\AgentUpdateRequest::SERVER_REASONS,
            'prediction_metric' => \App\Models\Prediction::METRIC_LABELS,
            'prediction_confidence' => \App\Models\Prediction::CONFIDENCE_LABELS,
            'alert_metric' => \App\Models\AlertRule::METRIC_LABELS,
            'domain_status' => \App\Models\Domain::STATUS_LABELS,
            'page_source' => \App\Models\MonitoredPage::SOURCES,
            'page_failure' => \App\Models\MonitoredPage::FAILURE_LABELS,
            'page_status' => \App\Models\MonitoredPage::STATUS_LABELS,
            'incident_source' => \App\Models\Incident::SOURCE_LABELS,
            'incident_event' => \App\Models\IncidentEvent::TYPE_LABELS,
            'resource_type' => \App\Services\Dependencies\ResourceCatalog::TYPES,
            'webhook_event' => \App\Models\WebhookEndpoint::EVENTS,
            'webhook_type' => \App\Models\WebhookEndpoint::TYPES,
            'api_ability' => \App\Models\ApiToken::ABILITIES,
        ];
    }

    public function test_english_has_a_label_for_every_code(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Severity::LABELS), array_keys(trans('labels.severity', [], 'en')));
        $this->assertEqualsCanonicalizing(array_keys(StatusPagePresenter::STATE_LABELS), array_keys(trans('status_page.state', [], 'en')));
    }

    public function test_status_badges_keep_their_dutch_texts(): void
    {
        // The labels that x-status had inline before the revamp.
        $before = [
            'online' => 'Online', 'offline' => 'Offline', 'pending' => 'In afwachting', 'maintenance' => 'Onderhoud',
            'healthy' => 'Gezond', 'up' => 'Bereikbaar', 'down' => 'Onbereikbaar', 'warning' => 'Waarschuwing',
            'critical' => 'Kritiek', 'unknown' => 'Onbekend', 'paused' => 'Gepauzeerd',
            'open' => 'Open', 'acknowledged' => 'Bevestigd', 'investigating' => 'In onderzoek',
            'resolved' => 'Opgelost', 'closed' => 'Gesloten', 'info' => 'Info',
            'valid' => 'Geldig', 'invalid' => 'Ongeldig', 'expired' => 'Verlopen', 'expiring' => 'Verloopt binnenkort',
            'operational' => 'Operationeel', 'degraded' => 'Verminderde prestaties',
            'partial_outage' => 'Gedeeltelijke storing', 'major_outage' => 'Grote storing',
            'sent' => 'Verzonden', 'failed' => 'Mislukt', 'queued' => 'In wachtrij', 'error' => 'Fout',
            'scheduled' => 'Gepland', 'enabled' => 'Ingeschakeld', 'disabled' => 'Uitgeschakeld',
        ];

        $this->assertSame($before, trans('status', [], 'nl'));
    }
}
