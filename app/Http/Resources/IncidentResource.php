<?php

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Incident */
class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'status' => $this->status,
            'source' => $this->source,
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id, 'name' => $this->subjectName()] : null,
            'parent_id' => $this->parent_id,
            'correlation' => $this->correlation ? [
                'reason_code' => $this->correlation['reason_code'] ?? null,
                'reason' => $this->correlation['reason'] ?? null,
                'path' => $this->correlation['path'] ?? [],
            ] : null,
            'occurrence_count' => $this->occurrence_count,
            'reopened_count' => $this->reopened_count,
            'assigned_to' => $this->assigned_to,
            'started_at' => $this->started_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'last_occurrence_at' => $this->last_occurrence_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'time_to_detect_seconds' => $this->timeToDetect(),
            'impacts' => $this->whenLoaded('impacts', fn () => $this->impacts->map(fn ($i) => [
                'resource_type' => $i->resource_type,
                'resource_id' => $i->resource_id,
                'role' => $i->role,
                'status' => $i->status,
                'failure_type' => $i->failure_type,
                'message' => $i->message,
                'first_failed_at' => $i->first_failed_at?->toIso8601String(),
                'recovered_at' => $i->recovered_at?->toIso8601String(),
            ])),
            'children' => $this->whenLoaded('children', fn () => $this->children->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'status' => $c->status])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'type' => $e->type,
                'message' => $e->message,
                'user_id' => $e->user_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])),
        ];
    }
}
