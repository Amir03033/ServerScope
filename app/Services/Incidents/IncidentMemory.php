<?php

namespace App\Services\Incidents;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\IncidentLearning;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Incident Memory: records what was learned from an incident and finds
 * earlier incidents of the same team that look alike.
 *
 * Matching is rule-based on data ServerScope already has (no AI): same
 * fingerprint (the same underlying problem), same resource, same kind of
 * check, the same failure details and shared symptoms. Every match lists
 * its reasons; a recorded cause is shown as confirmed or as a hypothesis.
 * Every query is limited to the incident's own team explicitly, so the
 * service is also safe outside a tenant (jobs, scheduler).
 */
class IncidentMemory
{
    public const CANDIDATES = 200;

    /** Lower-case tags derived from an incident: what kind of failure, which check, which details. */
    public static function symptomsOf(Incident $incident): array
    {
        $context = $incident->context ?? [];
        $tags = ['source:'.$incident->source];

        foreach (['error', 'failure_type', 'metric', 'confirmation'] as $key) {
            if (is_scalar($context[$key] ?? null) && $context[$key] !== '') {
                $tags[] = $key.':'.mb_strtolower((string) $context[$key]);
            }
        }

        return array_values(array_unique($tags));
    }

    /** @return array<int, string> normalised free-text symptoms */
    public static function normaliseSymptoms(array $symptoms): array
    {
        $clean = [];

        foreach ($symptoms as $symptom) {
            $symptom = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $symptom)));

            if ($symptom !== '' && mb_strlen($symptom) <= 60 && ! in_array($symptom, $clean, true)) {
                $clean[] = $symptom;
            }
        }

        return array_slice($clean, 0, IncidentLearning::MAX_SYMPTOMS);
    }

    /**
     * Create or update the incident's learning. $expectedVersion is the
     * version the user edited (null for a new record); a different current
     * version means someone else saved in between.
     *
     * @throws StaleLearningException|InvalidArgumentException
     */
    public function save(Incident $incident, array $data, User $user, ?int $expectedVersion): IncidentLearning
    {
        if (isset($data['deployment_id']) && ! Deployment::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->whereKey($data['deployment_id'])->exists()) {
            throw new InvalidArgumentException('Unknown deployment.');
        }

        try {
            return DB::transaction(function () use ($incident, $data, $user, $expectedVersion) {
                // Serialises concurrent saves for this incident.
                $locked = Incident::query()->withoutGlobalScopes()->whereKey($incident->id)->where('team_id', $incident->team_id)->lockForUpdate()->firstOrFail();
                $learning = IncidentLearning::query()->withoutGlobalScopes()->where('incident_id', $locked->id)->first();

                if (($learning?->version) !== $expectedVersion) {
                    throw new StaleLearningException;
                }

                $start = $locked->started_at ?? $locked->opened_at;
                $attributes = [
                    'cause_category' => $data['cause_category'],
                    'cause_status' => $data['cause_status'],
                    'root_cause' => $data['root_cause'],
                    'symptoms' => self::normaliseSymptoms($data['symptoms'] ?? []),
                    'resolution_steps' => $data['resolution_steps'] ?? null,
                    'outcome' => $data['outcome'],
                    'deployment_id' => $data['deployment_id'] ?? null,
                    'time_to_resolve_minutes' => $locked->resolved_at && $start ? max(0, (int) round($start->diffInMinutes($locked->resolved_at))) : null,
                    'updated_by' => $user->id,
                ];

                $learning ??= (new IncidentLearning)->forceFill(['team_id' => $locked->team_id, 'incident_id' => $locked->id, 'recorded_by' => $user->id, 'version' => 0]);
                $learning->forceFill([...$attributes, 'version' => $learning->version + 1])->save();

                return $learning;
            });
        } catch (UniqueConstraintViolationException) {
            // Two first saves at the same moment: the other one won.
            throw new StaleLearningException;
        }
    }

    /**
     * Earlier incidents of the same team that resemble this one, best first.
     *
     * @return Collection<int, array{incident: Incident, learning: ?IncidentLearning, score: int, reasons: array<int, string>}>
     */
    public function similar(Incident $incident, int $limit = 5): Collection
    {
        $symptoms = self::symptomsOf($incident);
        $own = $incident->learning?->symptoms ?? [];
        $candidates = Incident::query()->withoutGlobalScopes()
            ->where('team_id', $incident->team_id)
            ->whereKeyNot($incident->id)
            ->where('opened_at', '<=', $incident->opened_at)
            ->where(function ($q) use ($incident) {
                $q->where('fingerprint', $incident->fingerprint)->orWhere('source', $incident->source);

                if ($incident->subject_type !== null) {
                    $q->orWhere(fn ($q) => $q->where('subject_type', $incident->subject_type)->where('subject_id', $incident->subject_id));
                }
            })
            ->with(['learning' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest('opened_at')->latest('id')
            ->limit(self::CANDIDATES)
            ->get();

        return $candidates->map(function (Incident $candidate) use ($incident, $symptoms, $own) {
            $reasons = [];
            $score = 0;
            $learning = $candidate->learning;

            if ($candidate->fingerprint === $incident->fingerprint) {
                [$score, $reasons[]] = [$score + 50, 'same_problem'];
            } elseif ($incident->subject_type !== null && $candidate->subject_type === $incident->subject_type && (int) $candidate->subject_id === (int) $incident->subject_id) {
                [$score, $reasons[]] = [$score + 25, 'same_resource'];
            }

            if ($candidate->source === $incident->source) {
                $score += 10;
                $reasons[] = 'same_check';
            }

            $shared = array_values(array_intersect(array_diff($symptoms, ['source:'.$incident->source]), self::symptomsOf($candidate)));
            $sharedText = array_values(array_intersect($own, $learning?->symptoms ?? []));

            if ($shared !== [] || $sharedText !== []) {
                $score += 5 * (count($shared) + count($sharedText));
                $reasons[] = 'shared_symptoms';
            }

            if ($learning !== null) {
                $score += $learning->isConfirmed() ? 10 : 3;
                $score += $learning->outcome === 'successful' ? 5 : 0;
            }

            return ['incident' => $candidate, 'learning' => $learning, 'score' => $score, 'reasons' => $reasons, 'symptoms' => [...$shared, ...$sharedText]];
        })
            // A match on the same kind of check alone is too weak.
            ->filter(fn ($m) => array_diff($m['reasons'], ['same_check']) !== [] || $m['learning'] !== null)
            ->sortByDesc(fn ($m) => [$m['score'], $m['incident']->opened_at->getTimestamp()])
            ->take($limit)
            ->values();
    }

    /** Deployments linked to the incident's resource in the 24 hours before it started (same team). */
    public function deploymentCandidates(Incident $incident): Collection
    {
        if ($incident->subject_type === null) {
            return collect();
        }

        $start = $incident->started_at ?? $incident->opened_at;

        return Deployment::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)
            ->whereIn('id', DB::table('deployment_resources')->select('deployment_id')->where('resource_type', $incident->subject_type)->where('resource_id', $incident->subject_id))
            ->whereBetween('deployed_at', [$start->copy()->subDay(), $start->copy()->addHour()])
            ->latest('deployed_at')->limit(10)->get();
    }
}
