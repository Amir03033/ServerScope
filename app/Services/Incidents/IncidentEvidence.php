<?php

namespace App\Services\Incidents;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\ProbeCheck;
use App\Services\Deployments\DeployJudge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The evidence an AI analysis may see, built only from existing services
 * and only for the incident's own team: the incident, the Time Machine
 * timeline, findings and metric summaries, Deploy Judge results of
 * deployments in the window, remote probe results, and similar earlier
 * incidents with their recorded learnings (Incident Memory).
 *
 * Every item gets an id (E…, D…, P…, H…) that the model must cite. Text is
 * shortened and scrubbed of anything that looks like a credential, e-mail
 * or IP address. Resource names (server and website names of this team)
 * are included; URLs, credentials, configuration and environment values
 * are not. The result is capped at serverscope.ai.max_input_chars.
 */
class IncidentEvidence
{
    private const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]+-----.*?(-----END [A-Z ]+-----|$)/s',
        '/\b(ssk|sk|pk|ghp|gho|xox[abp])[-_][A-Za-z0-9_-]{8,}/',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i',
        '/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization)\b\s*[=:]\s*\S+/i',
        '/\b[a-f0-9]{40,}\b/i',
    ];

    public function __construct(
        private TimeMachine $timeMachine,
        private IncidentMemory $memory,
        private DeployJudge $judge,
    ) {}

    /** @return array<string, mixed> */
    public function build(Incident $incident): array
    {
        $locale = app()->getLocale();
        app()->setLocale('en');

        try {
            return $this->fit($this->collect($incident));
        } finally {
            app()->setLocale($locale);
        }
    }

    public static function hash(array $evidence): string
    {
        return hash('sha256', json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function clean(?string $text, int $max = 300): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $text = preg_replace(self::SECRET_PATTERNS, '[redacted]', $text) ?? '';
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-mail]', $text) ?? '';
        $text = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b|\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i', '[ip]', $text) ?? '';
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');

        return mb_strimwidth($text, 0, $max, '…');
    }

    /** @return array<string, mixed> */
    private function collect(Incident $incident): array
    {
        $tm = $this->timeMachine->build($incident);
        $onset = $tm['window']['onset'];
        $evidence = [];
        $n = 0;

        foreach ($tm['findings'] as $finding) {
            $evidence[] = ['id' => 'E'.++$n, 'kind' => 'finding', 'text' => self::clean($finding['text'])];
        }

        foreach ($tm['metrics'] as $m) {
            foreach ($m['hourly'] as $metric => $points) {
                $before = array_values(array_filter(array_map(fn ($p) => $p['flag'] ? null : $p['value'], array_slice($points, 0, 24)), fn ($v) => $v !== null));
                $during = array_values(array_filter(array_map(fn ($p) => $p['flag'] ? $p['value'] : null, $points), fn ($v) => $v !== null));

                if ($before === [] && $during === []) {
                    continue;
                }

                $evidence[] = ['id' => 'E'.++$n, 'kind' => 'metric_summary', 'resource' => self::clean($m['label'], 80), 'metric' => $metric,
                    'hourly_mean_24h_before' => $before ? round(array_sum($before) / count($before), 1) : null,
                    'hourly_max_24h_before' => $before ? max($before) : null,
                    'hourly_mean_during' => $during ? round(array_sum($during) / count($during), 1) : null,
                    'hourly_max_during' => $during ? max($during) : null];
            }
        }

        // Timeline: problems and changes first, the rest as room allows.
        $events = collect($tm['events'])->sortBy(fn ($e) => [$e['problem'] || $e['category'] === 'change' ? 0 : 1, $e['at']->getTimestamp()])->take(60)->sortBy(fn ($e) => $e['at']->getTimestamp());

        foreach ($events as $event) {
            $evidence[] = ['id' => 'E'.++$n, 'kind' => 'timeline', 'at' => $event['at']->toIso8601String(),
                'minutes_from_onset' => (int) round(($event['at']->getTimestamp() - $onset->getTimestamp()) / 60),
                'phase' => $event['phase'], 'category' => $event['category'], 'resource' => self::clean($event['resource'], 80),
                'text' => self::clean($event['title'], 200), 'detail' => self::clean($event['detail'], 200)];
        }

        return [
            'incident' => [
                'title' => self::clean($incident->title, 200),
                'source' => $incident->source,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'started_at' => $onset->toIso8601String(),
                'opened_at' => $incident->opened_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'description' => self::clean($incident->description, 500),
                'resource_type' => $incident->subject_type,
                'failure' => array_filter(array_map(fn ($v) => is_scalar($v) ? self::clean((string) $v, 60) : null, array_intersect_key($incident->context ?? [], array_flip(['error', 'failure_type', 'metric', 'dimension', 'status_code', 'confirmation'])))),
                'occurrences' => $incident->occurrence_count,
                'reopened' => $incident->reopened_count,
            ],
            'resources' => array_values(array_map(fn ($r) => ['type' => $r['type'], 'name' => self::clean($r['label'], 80), 'relation' => $r['relation']], $tm['resources'])),
            'evidence' => $evidence,
            'deployments' => $this->deployments($incident, $tm),
            'probe_checks' => $this->probeChecks($incident),
            'history' => $this->history($incident),
            'data_gaps' => array_values(array_map(fn ($g) => self::clean($g, 200), $tm['gaps'])),
        ];
    }

    private function deployments(Incident $incident, array $tm): array
    {
        $types = [];

        foreach ($tm['resources'] as $r) {
            $types[$r['type']][] = $r['model']->getKey();
        }

        if ($types === []) {
            return [];
        }

        $ids = DB::table('deployment_resources')->where(function ($q) use ($types) {
            foreach ($types as $type => $keys) {
                $q->orWhere(fn ($q) => $q->where('resource_type', $type)->whereIn('resource_id', $keys));
            }
        })->pluck('deployment_id');

        $onset = $tm['window']['onset'];
        $out = [];

        foreach (Deployment::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->whereIn('id', $ids)
            ->whereBetween('deployed_at', [$tm['window']['from'], $tm['window']['to']])->orderBy('deployed_at')->limit(3)->get() as $i => $deployment) {
            try {
                $analysis = $this->judge->analyse($deployment);
            } catch (Throwable) {
                $analysis = ['status' => 'unknown', 'checks' => []];
            }

            $out[] = ['id' => 'D'.($i + 1), 'label' => self::clean($deployment->label(), 120), 'environment' => $deployment->environment,
                'minutes_before_onset' => (int) round(($onset->getTimestamp() - CarbonImmutable::instance($deployment->deployed_at)->getTimestamp()) / 60),
                'deploy_judge_status' => $analysis['status'],
                'measured_changes' => array_values(array_map(fn ($c) => ['metric' => $c['metric'], 'before' => $c['before'], 'after' => $c['after'], 'verdict' => $c['verdict']],
                    array_filter($analysis['checks'], fn ($c) => $c['verdict'] !== 'insufficient_data')))];
        }

        return $out;
    }

    private function probeChecks(Incident $incident): array
    {
        if ($incident->subject_type !== 'domain') {
            return [];
        }

        $start = $incident->started_at ?? $incident->opened_at;

        return ProbeCheck::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->where('domain_id', $incident->subject_id)
            ->whereNotNull('outcome')->where('requested_at', '>=', $start->copy()->subHour())->orderBy('requested_at')->limit(5)->get()
            ->values()->map(fn (ProbeCheck $c, int $i) => ['id' => 'P'.($i + 1), 'at' => ($c->checked_at ?? $c->requested_at)->toIso8601String(), 'outcome' => $c->outcome,
                'http_status' => $c->http_status_code, 'response_ms' => $c->response_time_ms, 'error' => $c->error_code])->all();
    }

    private function history(Incident $incident): array
    {
        return $this->memory->similar($incident, 5)->map(fn (array $m) => [
            'id' => 'H'.$m['incident']->id,
            'incident_id' => $m['incident']->id,
            'title' => self::clean($m['incident']->title, 150),
            'opened_at' => $m['incident']->opened_at->toIso8601String(),
            'match_reasons' => $m['reasons'],
            'recorded_learning' => $m['learning'] ? [
                'cause_status' => $m['learning']->cause_status,
                'category' => $m['learning']->cause_category,
                'root_cause' => self::clean($m['learning']->root_cause, 300),
                'resolution' => self::clean($m['learning']->resolution_steps, 300),
                'fix_outcome' => $m['learning']->outcome,
            ] : null,
        ])->values()->all();
    }

    /** Drops the least important items until the JSON fits the input limit. */
    private function fit(array $evidence): array
    {
        $max = max(2000, (int) config('serverscope.ai.max_input_chars', 24000));

        while (strlen(json_encode($evidence)) > $max) {
            if (count($evidence['evidence']) > 10) {
                array_pop($evidence['evidence']);
            } elseif (count($evidence['history']) > 1) {
                array_pop($evidence['history']);
            } elseif ($evidence['evidence'] !== []) {
                array_pop($evidence['evidence']);
            } else {
                break;
            }
        }

        return $evidence;
    }
}
