<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Incident */
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
            'occurrence_count' => $this->occurrence_count,
            'reopened_count' => $this->reopened_count,
            'assigned_to' => $this->assigned_to,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'last_occurrence_at' => $this->last_occurrence_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'type' => $e->type,
                'message' => $e->message,
                'user_id' => $e->user_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])),
        ];
    }
}
