<?php

namespace App\Services\Deployments;

use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\MonitoredPage;
use App\Models\Server;
use App\Services\Analytics\MetricHistory;
use App\Services\Analytics\Statistics;
use App\Services\Dependencies\ResourceCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Compares what was measured before and after a deployment on the
 * resources it is linked to. It only reports measured changes with their
 * numbers; it never alerts and never claims the deployment caused them
 * (other changes, traffic or upstream problems can coincide).
 *
 * - Windows: WINDOW_MINUTES before and after the deployment. Until the
 *   after-window has passed the result is "pending".
 * - Websites/pages: availability, median response time and HTTP 5xx share
 *   from their own checks (at least MIN_CHECKS on each side).
 * - Servers: CPU and memory from raw samples (at least MIN_SAMPLES on each
 *   side); when raw samples are no longer kept, hourly averages (rollups)
 *   over FALLBACK_HOURS, reported as lower resolution.
 * - Incidents opened on the linked resources after the deployment, when
 *   none opened in the same time before it.
 * A change is only a regression when it exceeds both an absolute minimum
 * and, for samples, the normal spread before the deployment.
 */
class DeployJudge
{
    public const WINDOW_MINUTES = 60;

    public const FALLBACK_HOURS = 3;

    public const MIN_CHECKS = 10;

    public const MIN_SAMPLES = 20;

    /** metric => [absolute minimum change, MAD floor] (percentage points) */
    private const SERVER_RULES = ['cpu_percent' => [15.0, 2.0], 'memory_percent' => [10.0, 1.0]];

    public function __construct(private MetricHistory $history) {}

    /** @return array<string, mixed> */
    public function analyse(Deployment $deployment, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $at = CarbonImmutable::instance($deployment->deployed_at);
        $w = self::WINDOW_MINUTES;
        $before = [$at->subMinutes($w), $at];
        $after = [$at, $at->addMinutes($w)];
        $resources = [];

        foreach ($deployment->resourceRefs() as $ref) {
            $model = ResourceCatalog::find($ref['type'], $ref['id'], (int) $deployment->team_id);

            if ($model !== null) {
                $resources[] = ['type' => $ref['type'], 'model' => $model, 'label' => ResourceCatalog::name($model)];
            }
        }

        $result = [
            'status' => 'insufficient_data',
            'window_minutes' => $w,
            'resources' => $resources,
            'checks' => [],
            'incidents' => [],
            'overlaps' => Deployment::query()->withoutGlobalScopes()->where('team_id', $deployment->team_id)->whereKeyNot($deployment->id)
                ->whereBetween('deployed_at', [$before[0], $after[1]])->orderBy('deployed_at')->get(),
        ];

        if ($resources === []) {
            return [...$result, 'status' => 'no_resources'];
        }

        if ($now->lt($after[1])) {
            return [...$result, 'status' => 'pending', 'ready_at' => $after[1]];
        }

        foreach ($resources as $resource) {
            $model = $resource['model'];
            $checks = match (true) {
                $model instanceof Domain => $this->httpChecks(DB::table('domain_checks')->where('domain_id', $model->id)->where('type', 'http'), $before, $after),
                $model instanceof MonitoredPage => $this->httpChecks(DB::table('page_checks')->where('monitored_page_id', $model->id), $before, $after),
                $model instanceof Server => $this->serverMetrics($model, $at, $now),
                default => [],
            };

            foreach ($checks as $check) {
                $result['checks'][] = ['resource' => $resource['label'], ...$check];
            }

            $result['incidents'] = [...$result['incidents'], ...$this->incidents($deployment, $model, $resource['label'], $before, $after)];
        }

        $measured = array_filter($result['checks'], fn ($c) => $c['verdict'] !== 'insufficient_data');
        $regressed = array_filter($result['checks'], fn ($c) => $c['verdict'] === 'regression') !== [] || $result['incidents'] !== [];

        $result['status'] = match (true) {
            $regressed => 'regression',
            $measured === [] => 'insufficient_data',
            default => 'no_regression',
        };

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function httpChecks($query, array $before, array $after): array
    {
        $rows = (clone $query)->where('checked_at', '>=', $before[0])->where('checked_at', '<=', $after[1])
            ->orderBy('checked_at')->limit(20000)->get(['checked_at', 'success', 'response_time_ms', 'http_status_code']);
        $split = ['before' => [], 'after' => []];

        foreach ($rows as $row) {
            $split[CarbonImmutable::parse($row->checked_at, 'UTC')->lt($before[1]) ? 'before' : 'after'][] = $row;
        }

        $n = [count($split['before']), count($split['after'])];
        $enough = min($n) >= self::MIN_CHECKS;
        $stats = fn (array $rows) => [
            'availability' => $rows === [] ? null : count(array_filter($rows, fn ($r) => $r->success)) / count($rows) * 100,
            'failures' => count(array_filter($rows, fn ($r) => ! $r->success)),
            'errors' => count(array_filter($rows, fn ($r) => (int) $r->http_status_code >= 500)),
            'error_rate' => $rows === [] ? null : count(array_filter($rows, fn ($r) => (int) $r->http_status_code >= 500)) / count($rows) * 100,
            'times' => array_values(array_map(fn ($r) => (float) $r->response_time_ms, array_filter($rows, fn ($r) => $r->success && $r->response_time_ms !== null))),
        ];
        $b = $stats($split['before']);
        $a = $stats($split['after']);
        $out = [];

        $availabilityDrop = $enough ? $a['availability'] - $b['availability'] : null;
        $out[] = $this->check('availability', '%', $b['availability'], $a['availability'], $n, $enough ? match (true) {
            $availabilityDrop <= -5 && $a['failures'] >= 2 => 'regression',
            $availabilityDrop >= 5 && $b['failures'] >= 2 => 'improvement',
            default => 'no_change',
        } : 'insufficient_data', 'checks');

        $errorRise = $enough ? $a['error_rate'] - $b['error_rate'] : null;
        $out[] = $this->check('http_errors', '%', $b['error_rate'], $a['error_rate'], $n, $enough ? match (true) {
            $errorRise >= 5 && $a['errors'] >= 2 => 'regression',
            $errorRise <= -5 && $b['errors'] >= 2 => 'improvement',
            default => 'no_change',
        } : 'insufficient_data', 'checks');

        $tn = [count($b['times']), count($a['times'])];
        $mb = Statistics::median($b['times']);
        $ma = Statistics::median($a['times']);
        $timesEnough = min($tn) >= intdiv(self::MIN_CHECKS, 2) && $mb !== null && $ma !== null;
        $out[] = $this->check('response_time_ms', ' ms', $mb, $ma, $tn, $timesEnough ? match (true) {
            $ma >= $mb * 1.3 && $ma - $mb >= 100 => 'regression',
            $ma <= $mb / 1.3 && $mb - $ma >= 100 => 'improvement',
            default => 'no_change',
        } : 'insufficient_data', 'checks (median)');

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function serverMetrics(Server $server, CarbonImmutable $at, CarbonImmutable $now): array
    {
        $w = self::WINDOW_MINUTES;
        $samples = MetricSample::query()->where('server_id', $server->id)
            ->whereBetween('collected_at', [$at->subMinutes($w), $at->addMinutes($w)])
            ->get(['collected_at', 'cpu_percent', 'memory_total_bytes', 'memory_used_bytes']);
        $out = [];

        foreach (self::SERVER_RULES as $metric => [$minimum, $floor]) {
            $value = fn (MetricSample $s) => $metric === 'cpu_percent' ? $s->cpu_percent
                : ($s->memory_total_bytes > 0 && $s->memory_used_bytes !== null ? $s->memory_used_bytes / $s->memory_total_bytes * 100 : null);
            $b = $samples->filter(fn ($s) => $s->collected_at->lt($at))->map($value)->filter(fn ($v) => $v !== null)->values()->all();
            $a = $samples->filter(fn ($s) => $s->collected_at->gte($at))->map($value)->filter(fn ($v) => $v !== null)->values()->all();

            if (min(count($b), count($a)) >= self::MIN_SAMPLES) {
                $mb = Statistics::median($b);
                $ma = Statistics::median($a);
                $z = Statistics::robustZ($ma, $mb, Statistics::mad($b) ?? 0.0, $floor);
                $verdict = abs($ma - $mb) >= $minimum && abs($z) >= 3.5 ? ($ma > $mb ? 'regression' : 'improvement') : 'no_change';
                $out[] = $this->check($metric, '%', $mb, $ma, [count($b), count($a)], $verdict, 'samples (median)');

                continue;
            }

            // Raw samples gone (retention) or too few: hourly averages,
            // which come from the rollups for aggregated hours.
            $h = self::FALLBACK_HOURS;

            if ($now->lt($at->addHours($h))) {
                $out[] = $this->check($metric, '%', null, null, [count($b), count($a)], 'insufficient_data', 'samples');

                continue;
            }

            $bh = $this->history->buckets($server, $at->subHours($h), $at->subSecond(), 3600)[$metric] ?? [];
            $ah = $this->history->buckets($server, $at, $at->addHours($h), 3600)[$metric] ?? [];
            $mean = fn (array $buckets) => array_sum(array_column($buckets, 'count')) > 0 ? array_sum(array_map(fn ($x) => $x['mean'] * $x['count'], $buckets)) / array_sum(array_column($buckets, 'count')) : null;

            if (min(count($bh), count($ah)) < 2) {
                $out[] = $this->check($metric, '%', null, null, [count($bh), count($ah)], 'insufficient_data', 'hourly averages');

                continue;
            }

            $mb = $mean($bh);
            $ma = $mean($ah);
            $out[] = $this->check($metric, '%', $mb, $ma, [count($bh), count($ah)], abs($ma - $mb) >= $minimum ? ($ma > $mb ? 'regression' : 'improvement') : 'no_change', 'hourly averages');
        }

        return $out;
    }

    /** Incidents on the resource that opened after the deployment while none opened in the same time before. */
    private function incidents(Deployment $deployment, Model $resource, string $label, array $before, array $after): array
    {
        $query = fn () => Incident::query()->withoutGlobalScopes()->where('team_id', $deployment->team_id)
            ->where('subject_type', $resource->getMorphClass())->where('subject_id', $resource->getKey());

        if ($query()->whereBetween('opened_at', [$before[0], $before[1]])->where('opened_at', '<', $before[1])->exists()) {
            return [];
        }

        return $query()->where('opened_at', '>=', $after[0])->where('opened_at', '<=', $after[1])->orderBy('opened_at')->limit(20)->get()
            ->map(fn (Incident $i) => ['resource' => $label, 'incident' => $i, 'minutes' => (int) round($after[0]->diffInMinutes($i->opened_at))])->all();
    }

    private function check(string $metric, string $unit, ?float $before, ?float $after, array $n, string $verdict, string $basis): array
    {
        return [
            'metric' => $metric,
            'unit' => $unit,
            'before' => $before === null ? null : round($before, 2),
            'after' => $after === null ? null : round($after, 2),
            'change' => $before === null || $after === null ? null : round($after - $before, 2),
            'n_before' => $n[0],
            'n_after' => $n[1],
            'verdict' => $verdict,
            'basis' => $basis,
        ];
    }
}
