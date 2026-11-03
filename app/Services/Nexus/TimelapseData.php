<?php

namespace App\Services\Nexus;

use App\Models\DomainCheck;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Analytics\MetricHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * History for the time-travel film: the last 24 hours in 15-minute frames
 * or the last 7 days in hourly frames. Per frame the mean CPU of every
 * server and whether each website failed its HTTP checks; the incidents in
 * the period with the server tower they concern. Only the team's own data
 * (explicit team_id filters: also safe without a tenant).
 *
 * The tower of a website is the server it depends on (directly or through
 * a service), taken from the current NEXUS topology.
 */
class TimelapseData
{
    public const RANGES = ['24h' => [86400, 900], '7d' => [604800, 3600]];

    public const MAX_SERVERS = 60;

    public const MAX_SITES = 200;

    public const MAX_INCIDENTS = 300;

    public function __construct(private MetricHistory $history, private TopologyBuilder $topology) {}

    public function forTeam(int $teamId, string $range = '24h'): array
    {
        $range = isset(self::RANGES[$range]) ? $range : '24h';

        // Cached a few minutes: the film is history, a little delay is fine.
        return Cache::remember("nexus:timelapse:{$teamId}:{$range}", 120, fn () => $this->build($teamId, $range));
    }

    public function build(int $teamId, string $range): array
    {
        [$length, $size] = self::RANGES[$range];
        $to = CarbonImmutable::createFromTimestampUTC(intdiv(now()->getTimestamp(), $size) * $size + $size);
        $from = $to->subSeconds($length);
        $frames = range($from->getTimestamp(), $to->getTimestamp() - $size, $size);
        $index = array_flip($frames);

        // Servers: mean CPU per frame (null: no data in that frame).
        $servers = Server::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereNull('deleted_at')
            ->orderBy('name')->limit(self::MAX_SERVERS)->get();
        $serverRows = [];

        foreach ($servers as $server) {
            $cpu = array_fill(0, count($frames), null);

            foreach ($this->history->buckets($server, $from, $to, $size)['cpu_percent'] ?? [] as $ts => $bucket) {
                if (isset($index[$ts])) {
                    $cpu[$index[$ts]] = round($bucket['mean'], 1);
                }
            }

            $serverRows[] = ['key' => "server:{$server->id}", 'name' => $server->name, 'cpu' => $cpu];
        }

        // Towers of websites and services, from the topology.
        $graph = $this->topology->forTeam($teamId);
        $nodes = collect($graph['nodes'])->keyBy('key');
        $towerOf = function (?string $key) use ($nodes, $graph): ?string {
            $node = $key ? $nodes->get($key) : null;

            return match ($node['type'] ?? null) {
                'server' => $node['key'],
                'service' => $node['parent'],
                'page', 'page_group' => $node['parent'] ? ($this->siteServer($graph, $nodes, $node['parent'])) : null,
                'domain' => $this->siteServer($graph, $nodes, $node['key']),
                default => null,
            };
        };

        // Websites: per frame 1 when an HTTP check failed, 0 when all passed, null when not checked.
        $sites = $nodes->where('type', 'domain')->take(self::MAX_SITES);
        $down = [];
        $checks = ['total' => 0, 'ok' => 0];
        $domainIds = $sites->pluck('id')->all();

        if ($domainIds !== []) {
            DomainCheck::query()->whereIn('domain_id', $domainIds)->where('type', 'http')
                ->whereBetween('checked_at', [$from, $to])->select(['domain_id', 'success', 'checked_at'])
                ->orderBy('id')->lazy(5000)
                ->each(function (DomainCheck $check) use (&$down, &$checks, $index, $size) {
                    $frame = $index[intdiv($check->checked_at->getTimestamp(), $size) * $size] ?? null;

                    if ($frame === null) {
                        return;
                    }

                    $down[$check->domain_id][$frame] = max($down[$check->domain_id][$frame] ?? 0, $check->success ? 0 : 1);
                    $checks['total']++;
                    $checks['ok'] += $check->success ? 1 : 0;
                });
        }

        $siteRows = $sites->map(fn (array $site) => [
            'key' => $site['key'],
            'name' => $site['label'],
            'tower' => $towerOf($site['key']),
            'down' => array_map(fn (int $i) => $down[$site['id']][$i] ?? null, array_keys($frames)),
        ])->values()->all();

        // Incidents that overlap the period (resource keys as in the topology).
        $incidents = Incident::query()->withoutGlobalScopes()->where('team_id', $teamId)
            ->where('opened_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $from))
            ->orderBy('opened_at')->limit(self::MAX_INCIDENTS)
            ->get(['id', 'title', 'severity', 'subject_type', 'subject_id', 'opened_at', 'resolved_at']);

        $incidentRows = $incidents->map(fn (Incident $i) => [
            'id' => $i->id,
            'title' => $i->title,
            'severity' => $i->severity,
            'opened_at' => $i->opened_at?->getTimestamp(),
            'resolved_at' => $i->resolved_at?->getTimestamp(),
            'tower' => $i->subject_type && $i->subject_id ? $towerOf($this->resourceKey($i->subject_type, (int) $i->subject_id)) : null,
        ])->values()->all();

        return [
            'range' => $range,
            'from' => $from->getTimestamp(),
            'to' => $to->getTimestamp(),
            'size' => $size,
            'frames' => $frames,
            'servers' => $serverRows,
            'sites' => $siteRows,
            'incidents' => $incidentRows,
            'meta' => [
                'uptime' => $checks['total'] > 0 ? round($checks['ok'] / $checks['total'] * 100, 2) : null,
                'checks' => $checks['total'],
                'truncated' => $servers->count() >= self::MAX_SERVERS || $nodes->where('type', 'domain')->count() > self::MAX_SITES,
            ],
        ];
    }

    /** The server a website depends on: directly, or through a service on it. */
    private function siteServer(array $graph, $nodes, string $siteKey): ?string
    {
        foreach ($graph['edges'] as $edge) {
            if ($edge['source'] !== $siteKey) {
                continue;
            }

            $target = $nodes->get($edge['target']);

            if (($target['type'] ?? null) === 'server') {
                return $target['key'];
            }

            if (($target['type'] ?? null) === 'service' && $target['parent']) {
                return $target['parent'];
            }
        }

        return null;
    }

    /** Topology key of an incident subject (morph alias or class name). */
    private function resourceKey(string $type, int $id): ?string
    {
        $alias = class_exists($type) ? (new $type)->getMorphClass() : $type;

        return match ($alias) {
            'server' => "server:{$id}",
            'domain' => "domain:{$id}",
            'service' => "service:{$id}",
            'page' => "page:{$id}",
            default => null,
        };
    }
}
