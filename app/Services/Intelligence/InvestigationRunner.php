<?php

namespace App\Services\Intelligence;

use App\Models\Incident;
use App\Models\Investigation;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use App\Services\Incidents\IncidentInvestigator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one queued investigation: evidence snapshot (ContextBuilder),
 * hypotheses (HypothesisEngine), impact of the leading cause
 * (ImpactAnalyzer), the team check (TeamIntegrityGuard), then stores the
 * immutable result. No AI and no external calls.
 *
 * - The team comes from the stored investigation, never from the request
 *   or job payload; every query filters on it explicitly.
 * - Claiming moves queued (or a run left "running" by a crashed worker)
 *   to running, so a duplicate or retried job does not run twice.
 * - A collector that fails makes the result "partial", never complete.
 * - A completed run is never rewritten; earlier runs of the same incident
 *   become "superseded" (their result is kept unchanged).
 */
class InvestigationRunner
{
    public const PIPELINE_VERSION = 1;

    public function __construct(
        private ContextBuilder $contexts,
        private HypothesisEngine $hypotheses,
        private ImpactAnalyzer $impact,
        private TeamIntegrityGuard $guard,
        private DependencyGraph $graph,
    ) {}

    public function run(int $investigationId): void
    {
        $stale = now()->subSeconds(2 * max(30, (int) config('serverscope.intelligence.job_timeout_seconds', 45)));
        $claimed = Investigation::query()->withoutGlobalScopes()->whereKey($investigationId)
            ->where(fn ($q) => $q->where('status', 'queued')->orWhere(fn ($q) => $q->where('status', 'running')->where('started_at', '<', $stale)))
            ->update(['status' => 'running', 'started_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $investigation = Investigation::query()->withoutGlobalScopes()->findOrFail($investigationId);
        $incident = Incident::query()->withoutGlobalScopes()->where('team_id', $investigation->team_id)->find($investigation->incident_id);

        if ($incident === null) {
            $this->fail($investigation, 'incident_missing');

            return;
        }

        try {
            $asOf = CarbonImmutable::instance($investigation->as_of ?? now());
            $result = $this->investigate($incident, $asOf);
            $this->guard->assert((int) $investigation->team_id, $result);
        } catch (IntegrityViolation $e) {
            Log::error('Investigation integrity violation', ['investigation' => $investigation->id, 'reason' => $e->getMessage()]);
            $this->fail($investigation, 'integrity_violation');

            return;
        } catch (Throwable $e) {
            Log::warning('Investigation failed', ['investigation' => $investigation->id, 'exception' => $e::class]);
            report($e);
            $this->fail($investigation, 'internal_error');

            return;
        }

        DB::transaction(function () use ($investigation, $result, $asOf) {
            $partial = in_array('failed', $result['collectors'], true);
            $investigation->forceFill([
                'status' => $partial ? 'partial' : 'completed',
                'as_of' => $asOf,
                'window_start' => $result['window']['from'],
                'window_end' => $result['window']['to'],
                'scope' => ['resources' => count($result['resources']), 'truncated' => $result['truncated'], 'impact_truncated' => $result['impact']['truncated']],
                'result' => $result,
                'summary' => self::summary($result, $partial),
                'input_digest' => self::digest($result),
                'error_code' => null,
                'finished_at' => now(),
            ])->save();

            Investigation::query()->withoutGlobalScopes()->where('team_id', $investigation->team_id)->where('incident_id', $investigation->incident_id)
                ->whereKeyNot($investigation->id)->whereIn('status', ['completed', 'partial'])->where('id', '<', $investigation->id)
                ->update(['status' => 'superseded', 'updated_at' => now()]);
        });

        $this->afterRun($investigation);
    }

    /**
     * The automatic AI analysis that follows the run of a newly opened
     * incident (IncidentInvestigator decides whether it is due). Never
     * affects the investigation itself.
     */
    private function afterRun(Investigation $investigation): void
    {
        try {
            app(IncidentInvestigator::class)->afterInvestigation($investigation->fresh());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The complete, language-independent result for an incident at $asOf. @return array<string, mixed> */
    public function investigate(Incident $incident, CarbonImmutable $asOf): array
    {
        $context = $this->contexts->build($incident, $asOf);
        $outcome = $this->hypotheses->evaluate($context);
        $primary = $outcome['hypotheses'][0];
        $teamId = (int) $incident->team_id;

        // Impact of the leading cause; without one, of the incident's own resource.
        $cause = $primary['code'] !== 'insufficient_evidence' && $primary['resource'] !== null ? $primary['resource'] : $context->subject();
        $via = null;

        // Nothing depends on a service record itself; what depends on its server is exposed.
        if ($cause !== null && str_starts_with($cause, 'service:') && ($server = $context->resources[$cause]['path'] ?? null) !== null && count($server) > 1) {
            $via = $cause;
            $cause = $server[count($server) - 2];
        }

        $impact = $this->impact->analyze($teamId, $cause, $context->onset(), $context->end(), max(1, (int) config('serverscope.intelligence.impact_depth', 4)), max(1, (int) config('serverscope.intelligence.max_resources', 150)));
        $impact['via'] = $via;
        $impact['inferred'] = $this->inferred($incident, $teamId);

        $w = $context->window;

        return [
            'pipeline_version' => self::PIPELINE_VERSION,
            'as_of' => $asOf->toIso8601String(),
            'window' => ['onset' => $w['onset']->toIso8601String(), 'opened' => $w['opened']->toIso8601String(), 'end' => $w['end']?->toIso8601String(), 'from' => $w['from']->toIso8601String(), 'to' => $w['to']->toIso8601String(), 'truncated' => $w['truncated']],
            'incident' => $context->incident,
            'resources' => array_values($context->resources),
            'evidence' => array_values($context->evidence),
            'hypotheses' => $outcome['hypotheses'],
            'rejected' => $outcome['rejected'],
            'primary' => $outcome['primary'],
            'impact' => $impact,
            'gaps' => $context->gaps,
            'collectors' => $context->collectors,
            'truncated' => $context->truncated,
        ];
    }

    /** Hash of what the conclusions were drawn from (same data and as_of: same digest). */
    public static function digest(array $result): string
    {
        return hash('sha256', json_encode([$result['pipeline_version'], $result['incident'], $result['window'], $result['resources'], $result['evidence'], $result['gaps'], $result['collectors']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public static function summary(array $result, bool $partial): array
    {
        $primary = collect($result['hypotheses'])->firstWhere('key', $result['primary']);
        $assessments = array_count_values(array_column($result['impact']['items'], 'assessment'));

        return [
            'primary' => ['code' => $primary['code'], 'confidence' => $primary['confidence'], 'resource' => $primary['resource'], 'label' => collect($result['resources'])->firstWhere('key', $primary['resource'])['label'] ?? null, 'incident_id' => $primary['params']['incident_id'] ?? null],
            'hypotheses' => count($result['hypotheses']) - 1,
            'rejected' => count($result['rejected']),
            'evidence' => count($result['evidence']),
            'gaps' => count($result['gaps']),
            'impact' => ['failing' => $assessments['failing'] ?? 0, 'healthy' => $assessments['healthy'] ?? 0, 'unobserved' => $assessments['unobserved'] ?? 0],
            'partial' => $partial,
            'truncated' => $result['truncated'] !== [] || $result['impact']['truncated'],
        ];
    }

    /** Unconfirmed relations (DNS points to a server's IP): shown, never used as evidence. @return array<int, array{type: string, id: int, direction: string}> */
    private function inferred(Incident $incident, int $teamId): array
    {
        $subject = $incident->subject_type ? ResourceCatalog::find($incident->subject_type, $incident->subject_id, $teamId) : null;

        if ($subject === null || ! in_array($incident->subject_type, ['server', 'domain'], true)) {
            return [];
        }

        return array_slice(array_map(fn ($s) => ['type' => $s['type'], 'id' => (int) $s['id'], 'direction' => $s['direction']], $this->graph->inferred($subject)), 0, 20);
    }

    private function fail(Investigation $investigation, string $code): void
    {
        $investigation->forceFill(['status' => 'failed', 'error_code' => $code, 'finished_at' => now()])->save();

        // Without a result, an automatic analysis falls back to the legacy contract.
        if ($code !== 'integrity_violation') {
            $this->afterRun($investigation);
        }
    }
}
