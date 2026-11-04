<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\LogEntry;
use App\Models\Server;
use App\Services\Logs\IncidentLogs;
use App\Services\Logs\LogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Logs: errors and warnings from the log files the agents follow. Issues
 * (the same problem grouped) or the raw stream, for a period, a server, a
 * source, a level or one incident. Read-only for every team member.
 */
class LogController extends Controller
{
    public function index(Request $request, LogQuery $logs, IncidentLogs $incidentLogs): View
    {
        $v = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(LogQuery::PERIODS))],
            'server' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:50'],
            'level' => ['nullable', Rule::in(['errors', 'warning'])],
            'q' => ['nullable', 'string', 'max:200'],
            'view' => ['nullable', Rule::in(['issues', 'stream'])],
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9a-f]{40}$/'],
            'incident' => ['nullable', 'integer'],
        ]);

        $period = $v['period'] ?? '24h';
        [$minutes, $bucket] = LogQuery::PERIODS[$period];
        $to = CarbonImmutable::now();
        $from = $to->subMinutes($minutes);
        $filters = $v;
        $incident = null;

        // Around one incident: its servers, from just before it started until just after it ended.
        if (isset($v['incident'])) {
            $incident = Incident::query()->findOrFail($v['incident']);
            [$from, $to] = $incidentLogs->window($incident);
            $filters['servers'] = $incidentLogs->servers($incident) ?: [0];
            $bucket = max(1, (int) ceil($from->diffInMinutes($to) / 40));
        }

        $base = $logs->base($filters, $from, $to);
        $view = $v['view'] ?? (isset($v['fingerprint']) ? 'stream' : 'issues');

        // Counters for the period, and the same period before it for the trend.
        $counts = (clone $base)->toBase()
            ->selectRaw("sum(case when level in ('critical', 'error') then 1 else 0 end) as errors, sum(case when level = 'warning' then 1 else 0 end) as warnings, count(distinct fingerprint) as issues, count(distinct server_id) as servers")
            ->first();
        $previousErrors = $incident ? null : $logs->base($filters, $from->subMinutes($minutes), $from)->whereIn('level', LogEntry::ERROR_LEVELS)->count();

        $issues = $view === 'issues' ? $logs->issues($base) : collect();

        return view('logs.index', [
            'filters' => $v,
            'period' => $period,
            'view' => $view,
            'incident' => $incident,
            'from' => $from,
            'to' => $to,
            'counts' => ['errors' => (int) ($counts->errors ?? 0), 'warnings' => (int) ($counts->warnings ?? 0), 'issues' => (int) ($counts->issues ?? 0), 'servers' => (int) ($counts->servers ?? 0)],
            'previousErrors' => $previousErrors,
            'histogram' => $logs->histogram($base, $from, $to, $bucket),
            'bucket' => $bucket,
            'issues' => $issues,
            'sparklines' => $view === 'issues' ? $logs->sparklines($base, $issues->pluck('fingerprint')->all(), $from, $to, $bucket * 2) : [],
            'stream' => $view === 'stream' ? (clone $base)->with('server:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50)->withQueryString() : null,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'sources' => LogEntry::query()->where('occurred_at', '>=', now()->subDays(7))->distinct()->orderBy('source')->pluck('source'),
            'hasAny' => LogEntry::query()->exists(),
        ]);
    }
}
