<?php

namespace App\Services\Logs;

use App\Models\LogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the log lines of the current team for the Logs page and the
 * incident page: filtered lines, counts per time bucket, and issues
 * (lines grouped by fingerprint). Works on SQLite, MySQL/MariaDB and
 * PostgreSQL (bucketing differs per driver).
 */
class LogQuery
{
    public const PERIODS = ['1h' => [60, 5], '24h' => [1440, 60], '7d' => [10080, 360]];

    /**
     * @param  array{server?: ?int, servers?: ?array, source?: ?string, level?: ?string, q?: ?string, fingerprint?: ?string}  $filters
     */
    public function base(array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return LogEntry::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($filters['server'] ?? null, fn ($q, $id) => $q->where('server_id', $id))
            ->when($filters['servers'] ?? null, fn ($q, $ids) => $q->whereIn('server_id', $ids))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when(($filters['level'] ?? null) === 'errors', fn ($q) => $q->whereIn('level', LogEntry::ERROR_LEVELS))
            ->when(($filters['level'] ?? null) === 'warning', fn ($q) => $q->where('level', 'warning'))
            ->when($filters['fingerprint'] ?? null, fn ($q, $f) => $q->where('fingerprint', $f))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('message', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('source', $term)));
    }

    /**
     * Errors and warnings per bucket, oldest first, every bucket present.
     *
     * @return array<int, array{at: CarbonImmutable, errors: int, warnings: int}>
     */
    public function histogram(Builder $base, CarbonImmutable $from, CarbonImmutable $to, int $bucketMinutes): array
    {
        $seconds = $bucketMinutes * 60;
        $start = intdiv($from->getTimestamp(), $seconds) * $seconds;
        $rows = (clone $base)->toBase()
            ->selectRaw($this->bucket($seconds).' as bucket')
            ->selectRaw("sum(case when level in ('critical', 'error') then 1 else 0 end) as errors")
            ->selectRaw("sum(case when level = 'warning' then 1 else 0 end) as warnings")
            ->groupBy('bucket')
            ->get()->keyBy(fn ($r) => (int) $r->bucket);

        $buckets = [];

        for ($t = $start; $t <= $to->getTimestamp(); $t += $seconds) {
            $row = $rows->get(intdiv($t, $seconds));
            $buckets[] = ['at' => CarbonImmutable::createFromTimestampUTC($t), 'errors' => (int) ($row->errors ?? 0), 'warnings' => (int) ($row->warnings ?? 0)];
        }

        return $buckets;
    }

    /**
     * The same problem grouped: count, first and last time, and the newest line.
     *
     * @return Collection<int, array{fingerprint: string, count: int, first: CarbonImmutable, last: CarbonImmutable, entry: LogEntry, level: string, servers: int}>
     */
    public function issues(Builder $base, int $limit = 50): Collection
    {
        $groups = (clone $base)->toBase()
            ->select('fingerprint')
            ->selectRaw('count(*) as total, min(occurred_at) as first_at, max(occurred_at) as last_at, max(id) as latest_id, count(distinct server_id) as servers')
            ->selectRaw("max(case when level = 'critical' then 3 when level = 'error' then 2 else 1 end) as rank")
            ->groupBy('fingerprint')
            ->orderByDesc('rank')->orderByDesc('total')
            ->limit($limit)
            ->get();

        $entries = LogEntry::query()->with('server:id,name')->whereIn('id', $groups->pluck('latest_id'))->get()->keyBy('id');

        return $groups->map(fn ($g) => [
            'fingerprint' => $g->fingerprint,
            'count' => (int) $g->total,
            'first' => CarbonImmutable::parse($g->first_at),
            'last' => CarbonImmutable::parse($g->last_at),
            'entry' => $entries->get($g->latest_id),
            'level' => [3 => 'critical', 2 => 'error', 1 => 'warning'][(int) $g->rank],
            'servers' => (int) $g->servers,
        ])->filter(fn ($i) => $i['entry'] !== null)->values();
    }

    /**
     * Per issue, counts per bucket (for the small bar charts).
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, array<int, int>>
     */
    public function sparklines(Builder $base, array $fingerprints, CarbonImmutable $from, CarbonImmutable $to, int $bucketMinutes): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $seconds = $bucketMinutes * 60;
        $start = intdiv($from->getTimestamp(), $seconds);
        $count = intdiv($to->getTimestamp(), $seconds) - $start + 1;

        $rows = (clone $base)->toBase()->whereIn('fingerprint', $fingerprints)
            ->select('fingerprint')->selectRaw($this->bucket($seconds).' as bucket, count(*) as total')
            ->groupBy('fingerprint', 'bucket')->get();

        $lines = array_fill_keys($fingerprints, array_fill(0, $count, 0));

        foreach ($rows as $row) {
            $index = (int) $row->bucket - $start;
            if ($index >= 0 && $index < $count) {
                $lines[$row->fingerprint][$index] = (int) $row->total;
            }
        }

        return $lines;
    }

    /** The bucket number of occurred_at for this driver (seconds since 1970 / bucket size). */
    private function bucket(int $seconds): string
    {
        return match (DB::connection()->getDriverName()) {
            // timestampdiff, not unix_timestamp: no session time zone conversion.
            'mysql', 'mariadb' => "floor(timestampdiff(second, '1970-01-01 00:00:00', occurred_at) / {$seconds})",
            'pgsql' => "floor(extract(epoch from occurred_at) / {$seconds})",
            default => "cast(strftime('%s', occurred_at) / {$seconds} as integer)",
        };
    }
}
