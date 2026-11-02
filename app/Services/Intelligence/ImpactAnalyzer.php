<?php

namespace App\Services\Intelligence;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use App\Services\Incidents\Evidence\Collectors\ServiceCollector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What depends on a resource (DependencyGraph::descendants, the edges
 * correlation uses, within one team), with the path to each dependent and
 * what was observed of it during the incident:
 *
 * - failing: its own checks failed, or it had an incident, in the period;
 * - healthy: its checks only succeeded in the period;
 * - unobserved: no checks or incidents in the period (structural exposure,
 *   not an observed impact).
 *
 * A fixed number of queries per call (labels and observations per type),
 * whatever the number of dependents; bounded by max_resources.
 */
class ImpactAnalyzer
{
    /** Service transitions this long before the period tell its state at the start. */
    private const SERVICE_LOOKBACK_HOURS = 6;

    public function __construct(private DependencyGraph $graph) {}

    /**
     * @return array{cause: ?string, items: array<int, array<string, mixed>>, truncated: bool}
     */
    public function analyze(int $teamId, ?string $cause, CarbonImmutable $from, CarbonImmutable $to, int $depth, int $limit): array
    {
        if ($cause === null || ! preg_match('/^([a-z_]+):(\d+)$/', $cause, $m) || ! ResourceCatalog::supports($m[1])) {
            return ['cause' => $cause, 'items' => [], 'truncated' => false];
        }

        $nodes = $this->graph->descendants($m[1], (int) $m[2], $depth, $teamId);
        unset($nodes[$cause]);

        uasort($nodes, fn ($a, $b) => [$a['depth'], $a['type'], $a['id']] <=> [$b['depth'], $b['type'], $b['id']]);
        $truncated = count($nodes) > $limit;
        $nodes = array_slice($nodes, 0, $limit, true);

        $labels = $this->labels($teamId, $nodes);
        $observed = $this->observe($teamId, array_keys($labels), $from, $to);
        $items = [];

        foreach ($nodes as $key => $node) {
            // Not found within the team: never shown (DependencyGraph already skips these).
            if (! isset($labels[$key])) {
                continue;
            }

            $o = $observed[$key] ?? ['ok' => 0, 'failed' => 0, 'incidents' => []];
            $items[] = [
                'key' => $key,
                'type' => $node['type'],
                'id' => $node['id'],
                'label' => $labels[$key],
                'depth' => $node['depth'],
                'path' => $node['path'],
                'edges' => self::edges($node['path']),
                'assessment' => $o['failed'] > 0 || $o['incidents'] !== [] ? 'failing' : ($o['ok'] > 0 ? 'healthy' : 'unobserved'),
                'observed' => $o,
            ];
        }

        return ['cause' => $cause, 'items' => $items, 'truncated' => $truncated];
    }

    /**
     * Checks of the websites and pages on a server (excluding the given
     * resources) during [from, to]: independent evidence of whether the
     * host answered while its agent was silent.
     *
     * @param  array<int, string>  $exclude
     * @return array<string, array{ok: int, failed: int, incidents: array<int, int>}>
     */
    public function websitesOn(int $teamId, string $server, CarbonImmutable $from, CarbonImmutable $to, array $exclude, int $limit): array
    {
        $nodes = array_filter($this->graph->descendants('server', (int) substr($server, 7), 2, $teamId), fn ($n) => in_array($n['type'], ['domain', 'page'], true));
        $keys = array_slice(array_diff(array_keys($nodes), $exclude), 0, $limit);
        $observed = $this->observe($teamId, $keys, $from, $to, false);

        return array_filter($observed, fn ($o) => $o['ok'] + $o['failed'] > 0);
    }

    /** Edge kinds along a path from a dependency down to a dependent. @return array<int, string> */
    public static function edges(array $path): array
    {
        $edges = [];

        for ($i = 1; $i < count($path); $i++) {
            $parent = explode(':', $path[$i - 1])[0];
            $child = explode(':', $path[$i])[0];
            $edges[] = ($child === 'page' && $parent === 'domain') || ($child === 'service' && $parent === 'server') ? 'structural' : 'confirmed';
        }

        return $edges;
    }

    /**
     * Names of the nodes that belong to the team, one query per type.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, string>
     */
    private function labels(int $teamId, array $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            $ids[$node['type']][] = $node['id'];
        }

        $labels = [];
        $servers = fn () => Server::query()->withoutGlobalScopes()->where('team_id', $teamId)->select('id');
        $queries = [
            'server' => fn ($ids) => Server::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereIn('id', $ids),
            'domain' => fn ($ids) => Domain::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereIn('id', $ids),
            'page' => fn ($ids) => MonitoredPage::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereIn('domain_id', Domain::query()->withoutGlobalScopes()->where('team_id', $teamId)->select('id'))->whereIn('id', $ids)->with(['domain' => fn ($q) => $q->withoutGlobalScopes()]),
            'service' => fn ($ids) => ServiceCheck::query()->withoutGlobalScopes()->whereIn('server_id', $servers())->whereIn('id', $ids)->with(['server' => fn ($q) => $q->withoutGlobalScopes()]),
        ];

        foreach ($ids as $type => $list) {
            if (! isset($queries[$type])) {
                continue;
            }

            foreach ($queries[$type]($list)->get() as $model) {
                /** @var Model $model */
                $labels[$type.':'.$model->getKey()] = ResourceCatalog::name($model);
            }
        }

        return $labels;
    }

    /**
     * Check results and incidents per resource in [from, to] (resources
     * already checked to belong to the team).
     *
     * @param  array<int, string>  $keys
     * @return array<string, array{ok: int, failed: int, incidents: array<int, int>}>
     */
    private function observe(int $teamId, array $keys, CarbonImmutable $from, CarbonImmutable $to, bool $incidents = true): array
    {
        $ids = [];

        foreach ($keys as $key) {
            [$type, $id] = explode(':', $key);
            $ids[$type][] = (int) $id;
        }

        $out = [];
        $count = function (string $type, iterable $rows) use (&$out) {
            foreach ($rows as $row) {
                $key = $type.':'.$row->id;
                $out[$key] ??= ['ok' => 0, 'failed' => 0, 'incidents' => []];
                $out[$key][$row->success ? 'ok' : 'failed'] += (int) $row->n;
            }
        };

        if (isset($ids['domain'])) {
            $count('domain', DB::table('domain_checks')->whereIn('domain_id', $ids['domain'])->whereBetween('checked_at', [$from, $to])
                ->selectRaw('domain_id as id, success, COUNT(*) as n')->groupBy('domain_id', 'success')->get());
        }

        if (isset($ids['page'])) {
            $count('page', DB::table('page_checks')->whereIn('monitored_page_id', $ids['page'])->whereBetween('checked_at', [$from, $to])
                ->selectRaw('monitored_page_id as id, success, COUNT(*) as n')->groupBy('monitored_page_id', 'success')->get());
        }

        if (isset($ids['service'])) {
            // Transitions, not checks: a service that was already failing at
            // the start of the period counts as failing, and a recovery in
            // the period means it was failing before it.
            $events = DB::table('service_check_events')->whereIn('service_check_id', $ids['service'])
                ->whereBetween('recorded_at', [$from->subHours(self::SERVICE_LOOKBACK_HOURS), $to])
                ->orderBy('recorded_at')->limit(5000)->get(['service_check_id', 'status', 'previous_status', 'recorded_at']);
            $rows = [];

            foreach ($events->groupBy('service_check_id') as $id => $list) {
                $failing = false;
                $seen = false;

                foreach ($list as $e) {
                    $at = CarbonImmutable::parse($e->recorded_at, 'UTC');
                    $bad = in_array($e->status, ServiceCollector::FAILING, true);

                    if ($at->lt($from)) {
                        $failing = $bad;

                        continue;
                    }

                    $seen = true;
                    $failing = $failing || $bad || in_array($e->previous_status, ServiceCollector::FAILING, true);
                }

                if ($seen || $failing) {
                    $rows[] = (object) ['id' => $id, 'success' => ! $failing, 'n' => 1];
                }
            }

            $count('service', $rows);
        }

        if ($incidents && $ids !== []) {
            $rows = Incident::query()->withoutGlobalScopes()->where('team_id', $teamId)
                ->where(function ($q) use ($ids) {
                    foreach ($ids as $type => $list) {
                        $q->orWhere(fn ($q) => $q->where('subject_type', $type)->whereIn('subject_id', $list));
                    }
                })
                ->where('opened_at', '<=', $to)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $from))
                ->orderBy('id')->limit(500)->get(['id', 'subject_type', 'subject_id']);

            foreach ($rows as $row) {
                $key = $row->subject_type.':'.$row->subject_id;
                $out[$key] ??= ['ok' => 0, 'failed' => 0, 'incidents' => []];
                $out[$key]['incidents'][] = (int) $row->id;
            }
        }

        return $out;
    }
}
