<?php

namespace App\Services\Incidents;

use App\Events\IncidentEscalated;
use App\Events\IncidentOpened;
use App\Events\IncidentReopened;
use App\Events\IncidentResolved;
use App\Events\IncidentSeverityIncreased;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\User;
use App\Support\Severity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns monitoring signals into incidents.
 *
 * - One active incident per fingerprint (unique active_fingerprint column
 *   plus row locks); repeated failures only bump occurrence_count.
 * - A recurrence within the reopen window reopens the last resolved
 *   incident instead of creating a new one.
 * - Recovery resolves the active incident automatically.
 * - Lifecycle events are dispatched after the transaction commits.
 */
class IncidentManager
{
    public function trigger(Signal $signal): Incident
    {
        $this->assertSeverity($signal->severity);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->triggerLocked($signal));
            } catch (QueryException $exception) {
                // Concurrent trigger inserted the active incident first.
                if ($attempt >= 3 || ! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }
    }

    private function triggerLocked(Signal $signal): Incident
    {
        $now = now();

        $active = Incident::query()
            ->where('active_fingerprint', $signal->fingerprint)
            ->lockForUpdate()
            ->first();

        if ($active !== null) {
            $active->occurrence_count++;
            $active->last_occurrence_at = $now;
            $active->context = [...($active->context ?? []), ...$signal->context];

            $escalated = Severity::rank($signal->severity) > Severity::rank($active->severity);

            if ($escalated) {
                $this->event($active, 'severity_changed', null, "Ernst verhoogd van {$active->severity} naar {$signal->severity}.", [
                    'from' => $active->severity,
                    'to' => $signal->severity,
                ]);
                $active->severity = $signal->severity;
                $active->title = $signal->title;
                $active->description = $signal->description ?? $active->description;
            }

            $previousKey = $active->context['escalation_key'] ?? null;
            $newKey = $signal->escalationKey !== null && $signal->escalationKey !== $previousKey;

            if ($signal->escalationKey !== null) {
                $active->context = [...$active->context, 'escalation_key' => $signal->escalationKey];
            }

            if ($newKey && ! $escalated) {
                $active->title = $signal->title;
                $active->description = $signal->description ?? $active->description;
                $this->event($active, 'escalated', null, $signal->title, ['escalation_key' => $signal->escalationKey]);
            }

            $active->save();

            if ($escalated) {
                DB::afterCommit(fn () => IncidentSeverityIncreased::dispatch($active));
            } elseif ($newKey) {
                DB::afterCommit(fn () => IncidentEscalated::dispatch($active));
            }

            return $active;
        }

        $window = (int) config('serverscope.incidents.reopen_window_minutes', 60);

        $recent = $window > 0
            ? Incident::query()
                ->where('fingerprint', $signal->fingerprint)
                ->where('status', 'resolved')
                ->where('resolved_at', '>=', $now->copy()->subMinutes($window))
                ->latest('resolved_at')
                ->lockForUpdate()
                ->first()
            : null;

        if ($recent !== null) {
            $recent->fill([
                'status' => 'open',
                'active_fingerprint' => $signal->fingerprint,
                // Re-evaluated below: the previous root may be resolved.
                'parent_id' => null,
                'severity' => $signal->severity,
                'title' => $signal->title,
                'description' => $signal->description ?? $recent->description,
                'resolved_at' => null,
                'resolved_by' => null,
                'last_occurrence_at' => $now,
                'occurrence_count' => $recent->occurrence_count + 1,
                'reopened_count' => $recent->reopened_count + 1,
                'context' => [...($recent->context ?? []), ...$signal->context, 'escalation_key' => $signal->escalationKey],
            ])->save();

            $this->correlate($recent);

            $this->event($recent, 'reopened', null, 'Het probleem trad opnieuw op binnen '.$window.' minuten na herstel.', $signal->context ?: null);

            DB::afterCommit(fn () => IncidentReopened::dispatch($recent));

            return $recent;
        }

        $incident = Incident::query()->create([
            'fingerprint' => $signal->fingerprint,
            'active_fingerprint' => $signal->fingerprint,
            'subject_type' => $signal->subject?->getMorphClass(),
            'subject_id' => $signal->subject?->getKey(),
            'source' => $signal->source,
            'severity' => $signal->severity,
            'status' => 'open',
            'title' => mb_substr($signal->title, 0, 255),
            'description' => $signal->description,
            'opened_at' => $now,
            'last_occurrence_at' => $now,
            'context' => ($signal->escalationKey !== null ? [...$signal->context, 'escalation_key' => $signal->escalationKey] : $signal->context) ?: null,
            'started_at' => $signal->startedAt !== null && $signal->startedAt->lte($now) ? $signal->startedAt : $now,
        ]);

        $this->event($incident, 'opened', null, $signal->description, $signal->context ?: null);
        $this->correlate($incident);

        DB::afterCommit(fn () => IncidentOpened::dispatch($incident));

        return $incident;
    }

    /**
     * Automatic recovery: resolve the active incident for a fingerprint.
     */
    public function recover(string $fingerprint, string $message, array $context = [], bool $notify = true): ?Incident
    {
        return DB::transaction(function () use ($fingerprint, $message, $context, $notify): ?Incident {
            $incident = Incident::query()
                ->where('active_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($incident === null) {
                return null;
            }

            $incident->fill([
                'status' => 'resolved',
                'active_fingerprint' => null,
                'resolved_at' => now(),
                'resolved_by' => null,
            ])->save();

            $this->event($incident, 'recovered', null, $message, $context ?: null);

            if ($notify) {
                DB::afterCommit(fn () => IncidentResolved::dispatch($incident));
            }

            return $incident;
        });
    }

    private function correlate(Incident $incident): void
    {
        app(CorrelationEngine::class)->correlate($incident);
    }

    public function isActive(string $fingerprint): bool
    {
        return Incident::query()->where('active_fingerprint', $fingerprint)->exists();
    }

    /**
     * Manual status change by a user, enforcing Incident::TRANSITIONS.
     */
    public function transition(Incident $incident, string $status, User $user, ?string $notes = null): Incident
    {
        return DB::transaction(function () use ($incident, $status, $user, $notes): Incident {
            /** @var Incident $locked */
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo($status)) {
                throw new InvalidArgumentException("Overgang van {$locked->status} naar {$status} is niet toegestaan.");
            }

            $now = now();
            $from = $locked->status;

            switch ($status) {
                case 'acknowledged':
                case 'investigating':
                    // The first human response counts for MTTA.
                    if ($locked->acknowledged_at === null) {
                        $locked->acknowledged_at = $now;
                        $locked->acknowledged_by = $user->id;
                    }
                    break;

                case 'resolved':
                    $locked->resolved_at = $now;
                    $locked->resolved_by = $user->id;
                    $locked->active_fingerprint = null;
                    $locked->resolution_notes = $notes ?? $locked->resolution_notes;
                    break;

                case 'closed':
                    $locked->closed_at = $now;
                    $locked->closed_by = $user->id;
                    $locked->resolution_notes = $notes ?? $locked->resolution_notes;
                    break;

                case 'open':
                    // Manual reopen of a resolved incident.
                    if (Incident::query()->where('active_fingerprint', $locked->fingerprint)->exists()) {
                        throw new InvalidArgumentException('Er is al een actief incident voor dit probleem.');
                    }

                    $locked->active_fingerprint = $locked->fingerprint;
                    $locked->resolved_at = null;
                    $locked->resolved_by = null;
                    $locked->reopened_count++;
                    break;
            }

            $locked->status = $status;
            $locked->save();

            $this->event($locked, $status === 'open' ? 'reopened' : $status, $user, $notes, ['from' => $from, 'to' => $status]);

            if ($status === 'resolved') {
                DB::afterCommit(fn () => IncidentResolved::dispatch($locked));
            }

            return $locked;
        });
    }

    public function assign(Incident $incident, ?User $assignee, User $by): Incident
    {
        $incident->assigned_to = $assignee?->id;
        $incident->save();

        $this->event($incident, 'assigned', $by, $assignee ? "Toegewezen aan {$assignee->name}." : 'Toewijzing verwijderd.', [
            'assigned_to' => $assignee?->id,
        ]);

        return $incident;
    }

    public function comment(Incident $incident, User $user, string $message): IncidentEvent
    {
        return $this->event($incident, 'comment', $user, $message);
    }

    public function changeSeverity(Incident $incident, string $severity, User $by): Incident
    {
        $this->assertSeverity($severity);

        if ($incident->severity !== $severity) {
            $this->event($incident, 'severity_changed', $by, "Ernst gewijzigd van {$incident->severity} naar {$severity}.", [
                'from' => $incident->severity,
                'to' => $severity,
            ]);
            $incident->severity = $severity;
            $incident->save();
        }

        return $incident;
    }

    public function event(Incident $incident, string $type, ?User $user = null, ?string $message = null, ?array $data = null): IncidentEvent
    {
        return $incident->events()->create([
            'type' => $type,
            'user_id' => $user?->id,
            'message' => $message,
            'data' => $data,
            'created_at' => now(),
        ]);
    }

    private function assertSeverity(string $severity): void
    {
        if (! Severity::isValid($severity)) {
            throw new InvalidArgumentException("Unknown severity {$severity}.");
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
