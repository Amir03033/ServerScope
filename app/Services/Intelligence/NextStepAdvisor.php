<?php

namespace App\Services\Intelligence;

/**
 * Read-only next steps for a hypothesis, as codes with a resource key
 * (rendered in the reader's language). Suggestions for a person to check;
 * ServerScope never runs commands or changes anything itself, and no step
 * names commands, configuration or log contents it does not know.
 */
class NextStepAdvisor
{
    public const STEPS = [
        'upstream_incident' => ['open_related_incident', 'check_upstream_recovery'],
        'upstream_signal' => ['open_related_incident'],
        'service_failed' => ['inspect_service_logs', 'check_service_restarts'],
        'agent_unhealthy' => ['check_agent_service', 'check_agent_connectivity'],
        'host_unreachable' => ['check_host_console', 'check_network'],
        'agent_offline_mixed' => ['compare_websites', 'check_host_console'],
        'agent_offline_unknown' => ['confirm_dependency', 'check_host_console'],
        'disk_exhaustion' => ['review_disk_growth'],
        'memory_exhaustion' => ['review_memory'],
        'swap_pressure' => ['review_memory'],
        'cpu_saturation' => ['review_cpu'],
        'reboot' => ['check_reboot_reason'],
        'dns_change' => ['verify_dns_records'],
        'dns_resolution_failure' => ['verify_dns_records'],
        'certificate_problem' => ['check_certificate'],
        'registration_expiry' => ['renew_registration'],
        'recent_deployment' => ['review_deployment'],
        'recent_config_change' => ['review_audit_log'],
        'recent_agent_update' => ['inspect_agent_update'],
        'application_response' => ['inspect_application_logs'],
        'planned_maintenance' => ['confirm_maintenance'],
        'insufficient_evidence' => ['confirm_dependency', 'add_monitoring'],
    ];

    /**
     * @param  array<string, mixed>  $hypothesis
     * @return array<int, array{code: string, resource: ?string, incident_id: ?int}>
     */
    public function forHypothesis(array $hypothesis, InvestigationContext $context): array
    {
        $steps = [];

        foreach (self::STEPS[$hypothesis['code']] ?? [] as $code) {
            $steps[] = ['code' => $code, 'resource' => $hypothesis['resource'], 'incident_id' => isset($hypothesis['params']['incident_id']) ? (int) $hypothesis['params']['incident_id'] : null];
        }

        // Missing metrics make every hypothesis weaker; fixing that helps any future investigation.
        if ($hypothesis['code'] === 'insufficient_evidence' && in_array('metrics', $hypothesis['missing'], true)) {
            $steps[] = ['code' => 'check_agent_reporting', 'resource' => null, 'incident_id' => null];
        }

        return $steps;
    }
}
