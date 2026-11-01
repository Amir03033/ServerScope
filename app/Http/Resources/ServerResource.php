<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Server */
class ServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sample = $this->whenLoaded('latestMetricSample');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'environment' => $this->environment,
            'operating_system' => $this->operating_system,
            'agent_version' => $this->agent_version,
            'cpu_count' => $this->cpu_count,
            'status' => $this->displayStatus(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'maintenance' => [
                'active' => $this->isInMaintenance(),
                'starts_at' => $this->maintenance_starts_at?->toIso8601String(),
                'ends_at' => $this->maintenance_ends_at?->toIso8601String(),
            ],
            'latest_metrics' => $this->relationLoaded('latestMetricSample') && $sample ? [
                'collected_at' => $sample->collected_at->toIso8601String(),
                'cpu_percent' => $sample->cpu_percent,
                'memory_percent' => $sample->memory_total_bytes ? round($sample->memory_used_bytes / $sample->memory_total_bytes * 100, 2) : null,
                'load_1' => $sample->load_1,
                'uptime_seconds' => $sample->uptime_seconds,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
