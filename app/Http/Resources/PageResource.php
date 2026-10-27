<?php

namespace App\Http\Resources;

use App\Models\MonitoredPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MonitoredPage */
class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'url' => $this->url,
            'title' => $this->title,
            'source' => $this->source,
            'approval_status' => $this->approval_status,
            'monitoring_active' => $this->is_active,
            'status' => $this->displayStatus(),
            'last_failure_type' => $this->last_failure_type,
            'consecutive_failures' => $this->consecutive_failures,
            'last_status_code' => $this->last_status_code,
            'last_response_ms' => $this->last_response_ms,
            'last_error' => $this->last_error,
            'tls_valid' => $this->tls_valid,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_failure_at' => $this->last_failure_at?->toIso8601String(),
            'next_check_at' => $this->next_check_at?->toIso8601String(),
            'content_changed_at' => $this->content_changed_at?->toIso8601String(),
            'settings' => [
                'check_interval_minutes' => $this->check_interval_minutes,
                'timeout_seconds' => $this->timeout_seconds,
                'failure_threshold' => $this->failure_threshold,
                'recovery_threshold' => $this->recovery_threshold,
                'expected_status_codes' => $this->expected_status_codes,
                'max_response_ms' => $this->max_response_ms,
                'follow_redirects' => $this->follow_redirects,
                'expected_final_url' => $this->expected_final_url,
                'keyword_present' => $this->keyword_present,
                'keyword_absent' => $this->keyword_absent,
                'header_assertions' => $this->header_assertions ?? [],
                'monitored_headers' => $this->monitored_headers ?? [],
                'content_monitoring' => $this->content_monitoring,
                'content_selector' => $this->content_selector,
                'content_exclusions' => $this->content_exclusions ?? [],
                'alerts_enabled' => $this->alerts_enabled,
                'alert_severity' => $this->alert_severity,
                'notify_content_changes' => $this->notify_content_changes,
                'sla_target' => $this->sla_target,
            ],
        ];
    }
}
