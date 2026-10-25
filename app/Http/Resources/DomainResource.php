<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Domain */
class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'url' => $this->url(),
            'status' => $this->displayStatus(),
            'monitoring_active' => $this->is_active,
            'http' => [
                'enabled' => $this->http_enabled,
                'status' => $this->http_status,
                'last_status_code' => $this->http_last_status_code,
                'last_response_ms' => $this->http_last_response_ms,
                'consecutive_failures' => $this->http_consecutive_failures,
                'last_checked_at' => $this->http_last_checked_at?->toIso8601String(),
            ],
            'ssl' => [
                'enabled' => $this->ssl_enabled,
                'status' => $this->ssl_status,
                'issuer' => $this->ssl_issuer,
                'valid_to' => $this->ssl_valid_to?->toIso8601String(),
                'days_remaining' => $this->sslDaysRemaining(),
            ],
            'dns' => [
                'enabled' => $this->dns_enabled,
                'status' => $this->dns_status,
                'last_changed_at' => $this->dns_last_changed_at?->toIso8601String(),
            ],
            'registration' => [
                'enabled' => $this->expiry_enabled,
                // "verified" only when the registry published the date.
                'status' => $this->expiry_status,
                'expires_at' => $this->registration_expires_at?->toIso8601String(),
                'registrar' => $this->registrar,
                'source' => $this->expiry_source,
                'checked_at' => $this->expiry_checked_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
