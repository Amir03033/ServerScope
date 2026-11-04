<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Heartbeat;
use App\Models\Incident;
use App\Models\LogEntry;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\Intelligence\Investigations;
use App\Services\Intelligence\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * A short look at a server, website, incident or scheduled job for the side
 * panel and the hover cards (read-only). Route model binding runs under the
 * tenant scope, so another team's resource is a 404. One shape for all:
 * title, status, stats, an optional sparkline or run strip, highlights (the
 * few facts you would otherwise open the page for) and sections of items.
 */
class PeekController extends Controller
{
    private const SEVERITY_TONE = ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'info'];

    public function server(Server $server): JsonResponse
    {
        $status = $server->displayStatus(now());
        $sample = $server->latestMetricSample;
        $disk = collect($sample?->disks ?? [])->map(fn ($d) => is_array($d) ? ($d['percent'] ?? null) : null)->filter(fn ($v) => is_numeric($v))->max();
        $cpu = MetricSample::query()->where('server_id', $server->id)->where('collected_at', '>=', now()->subHours(2))
            ->orderBy('collected_at')->limit(240)->pluck('cpu_percent')->filter(fn ($v) => $v !== null)->map(fn ($v) => round((float) $v, 1))->values();

        return $this->peek([
            'type' => 'server',
            'title' => $server->name,
            'subtitle' => collect([$server->hostname, $server->operating_system, $server->environmentLabel()])->filter()->implode(' · '),
            'url' => route('servers.show', $server),
            'status' => ['label' => __('peek.server_status.'.$status), 'tone' => ['online' => 'ok', 'offline' => 'bad', 'maintenance' => 'info'][$status] ?? 'muted'],
            'stats' => [
                ['label' => __('peek.cpu'), 'value' => $sample?->cpu_percent === null ? '—' : round($sample->cpu_percent).'%', 'tone' => $this->load($sample?->cpu_percent)],
                ['label' => __('peek.memory'), 'value' => $sample && $sample->memory_total_bytes ? round($sample->memory_used_bytes / $sample->memory_total_bytes * 100).'%' : '—'],
                ['label' => __('peek.disk'), 'value' => $disk === null ? '—' : round((float) $disk).'%', 'tone' => $this->load($disk)],
                ['label' => __('peek.last_seen'), 'value' => $server->last_seen_at?->diffForHumans() ?? '—'],
            ],
            'spark' => $cpu->count() > 1 ? ['label' => __('peek.cpu_2h'), 'values' => $cpu->all(), 'max' => 100] : null,
            'highlights' => $this->serverHighlights($server),
            'sections' => array_values(array_filter([
                $this->incidents('server', $server->id),
                $this->section(__('peek.jobs'), Heartbeat::query()->where('server_id', $server->id)->orderByRaw("case status when 'down' then 0 else 1 end")->orderBy('name')->limit(6)->get()->map(fn (Heartbeat $h) => [
                    'label' => $h->name,
                    'meta' => __('heartbeats.status.'.$h->status),
                    'tone' => ['up' => 'ok', 'down' => 'bad', 'paused' => 'info'][$h->status] ?? 'muted',
                    'url' => route('heartbeats.show', $h),
                ])->all()),
                $this->section(__('peek.services'), $server->serviceChecks()->orderBy('name')->limit(12)->get()->map(fn ($s) => [
                    'label' => $s->display_name ?: $s->name,
                    'meta' => __('peek.service_status.'.($s->is_enabled ? $s->status : 'paused')),
                    'tone' => ! $s->is_enabled ? 'muted' : (['running' => 'ok', 'stopped' => 'bad', 'failed' => 'bad', 'not_found' => 'bad', 'starting' => 'warn'][$s->status] ?? 'muted'),
                ])->all()),
            ])),
        ]);
    }

    public function domain(Domain $domain): JsonResponse
    {
        $status = $domain->displayStatus();
        $times = $domain->checks()->where('type', 'http')->whereNotNull('response_time_ms')->latest('checked_at')->limit(48)
            ->pluck('response_time_ms')->reverse()->map(fn ($v) => (int) $v)->values();
        $ssl = $domain->sslDaysRemaining();
        $expiry = $domain->expiryDaysRemaining();

        return $this->peek([
            'type' => 'domain',
            'title' => $domain->displayName(),
            'subtitle' => null,
            'url' => route('domains.show', $domain),
            'status' => ['label' => __('peek.domain_status.'.$status), 'tone' => ['healthy' => 'ok', 'warning' => 'warn', 'critical' => 'bad', 'maintenance' => 'info'][$status] ?? 'muted'],
            'stats' => [
                ['label' => __('peek.response'), 'value' => $domain->http_last_response_ms === null ? '—' : round($domain->http_last_response_ms).' ms', 'tone' => $domain->http_last_response_ms >= 1500 ? 'warn' : null],
                ['label' => 'HTTP', 'value' => $this->state($domain->http_status), 'tone' => $this->checkTone($domain->http_status)],
                ['label' => 'SSL', 'value' => $ssl === null ? $this->state($domain->ssl_status) : trans_choice('peek.days', $ssl, ['count' => $ssl]), 'tone' => $ssl !== null && $ssl <= 14 ? 'warn' : $this->checkTone($domain->ssl_status)],
                ['label' => __('peek.registration'), 'value' => $expiry === null ? '—' : trans_choice('peek.days', $expiry, ['count' => $expiry]), 'tone' => $expiry !== null && $expiry <= 30 ? 'warn' : null],
            ],
            'spark' => $times->count() > 1 ? ['label' => __('peek.response_recent'), 'values' => $times->all(), 'max' => null] : null,
            'highlights' => $this->domainHighlights($domain),
            'sections' => array_values(array_filter([$this->incidents('domain', $domain->id)])),
        ]);
    }

    public function incident(Incident $incident): JsonResponse
    {
        $tone = self::SEVERITY_TONE[$incident->severity] ?? 'warn';
        $subject = $incident->subject;

        return $this->peek([
            'type' => 'incident',
            'title' => $incident->title,
            'subtitle' => collect([$incident->subjectName(), __('peek.opened', ['time' => $incident->opened_at?->diffForHumans()])])->filter()->implode(' · '),
            'url' => route('incidents.show', $incident),
            'status' => ['label' => __('peek.incident_status.'.$incident->status), 'tone' => $incident->isActive() ? $tone : 'ok'],
            'stats' => [
                ['label' => __('peek.severity'), 'value' => __('peek.severity_level.'.$incident->severity), 'tone' => $tone],
                ['label' => __('peek.occurrences'), 'value' => (string) ($incident->occurrence_count ?? 1)],
                ['label' => __('peek.duration'), 'value' => $incident->opened_at ? $incident->opened_at->diffForHumans($incident->resolved_at ?? now(), true) : '—'],
                ['label' => __('peek.assignee'), 'value' => $incident->assignee?->name ?? __('peek.nobody')],
            ],
            'spark' => null,
            'highlights' => $this->incidentHighlights($incident),
            'text' => $incident->description ? Str::limit(strip_tags($incident->description), 280) : null,
            'sections' => array_values(array_filter([
                $subject && method_exists($subject, 'getKey') ? $this->section(__('peek.concerns'), [[
                    'label' => $incident->subjectName(),
                    'meta' => null,
                    'tone' => null,
                    'url' => match ($incident->subject_type) {
                        'server', Server::class => route('servers.show', $incident->subject_id),
                        'domain', Domain::class => route('domains.show', $incident->subject_id),
                        'heartbeat' => route('heartbeats.show', $incident->subject_id),
                        default => null,
                    },
                ]]) : null,
                $this->section(__('peek.timeline'), $incident->events()->latest('created_at')->limit(5)->get()->map(fn ($e) => [
                    'label' => $e->message ? Str::limit($e->message, 90) : __('peek.event'),
                    'meta' => $e->created_at?->diffForHumans(),
                    'tone' => null,
                ])->all()),
            ])),
        ]);
    }

    public function heartbeat(Heartbeat $heartbeat): JsonResponse
    {
        $runs = $heartbeat->pings()->where('kind', '!=', 'start')->orderByDesc('id')->limit(24)->pluck('kind')->reverse()->values();
        $lateAt = $heartbeat->lateAt();
        $late = $heartbeat->status === 'down' && $heartbeat->next_due_at;

        return $this->peek([
            'type' => 'heartbeat',
            'title' => $heartbeat->name,
            'subtitle' => collect([$heartbeat->scheduleLabel(), $heartbeat->server?->name])->filter()->implode(' · '),
            'url' => route('heartbeats.show', $heartbeat),
            'status' => ['label' => __('heartbeats.status.'.$heartbeat->status), 'tone' => ['up' => 'ok', 'down' => 'bad', 'paused' => 'info'][$heartbeat->status] ?? 'muted'],
            'stats' => [
                ['label' => __('heartbeats.show.facts.last'), 'value' => $heartbeat->last_ping_at?->diffForHumans() ?? __('heartbeats.never')],
                $late
                    ? ['label' => __('heartbeats.show.facts.late'), 'value' => $heartbeat->next_due_at->diffForHumans(), 'tone' => 'bad']
                    : ['label' => __('heartbeats.show.facts.next'), 'value' => $lateAt?->diffForHumans() ?? '—'],
                ['label' => __('heartbeats.show.facts.duration'), 'value' => $heartbeat->last_duration_ms === null ? '—' : ($heartbeat->last_duration_ms < 1000 ? $heartbeat->last_duration_ms.' ms' : round($heartbeat->last_duration_ms / 1000).' s')],
                ['label' => __('heartbeats.show.facts.grace'), 'value' => $heartbeat->grace_minutes.' min'],
            ],
            'spark' => null,
            'runs' => $runs->isEmpty() ? null : ['label' => __('heartbeats.show.runs_title'), 'kinds' => $runs->all()],
            'highlights' => array_values(array_filter([
                ($fail = $heartbeat->pings()->where('kind', 'fail')->latest('id')->first())
                    ? ['text' => __('peek.last_failure', ['time' => $fail->received_at->diffForHumans()]).($fail->exit_code !== null ? ' · '.__('heartbeats.show.exit', ['code' => $fail->exit_code]) : ''), 'tone' => $heartbeat->status === 'down' ? 'bad' : 'warn'] : null,
                $heartbeat->status === 'new' ? ['text' => __('heartbeats.show.waiting_text'), 'tone' => 'info'] : null,
            ])),
            'sections' => array_values(array_filter([$this->incidents('heartbeat', $heartbeat->id)])),
        ]);
    }

    /** @return array<int, array{text: string, tone: ?string}> */
    private function serverHighlights(Server $server): array
    {
        $failing = $server->serviceChecks()->where('is_enabled', true)->whereIn('status', ['stopped', 'failed', 'not_found'])->pluck('name');
        $errors = LogEntry::query()->where('server_id', $server->id)->whereIn('level', LogEntry::ERROR_LEVELS)->where('occurred_at', '>=', now()->subHour())->count();
        $late = Heartbeat::query()->where('server_id', $server->id)->where('status', 'down')->count();
        $deploy = Deployment::query()->forResource($server)->latest('deployed_at')->first();

        return array_values(array_filter([
            $failing->isNotEmpty() ? ['text' => __('peek.services_down', ['names' => $failing->take(3)->implode(', ')]), 'tone' => 'bad'] : null,
            $late > 0 ? ['text' => trans_choice('peek.jobs_down', $late, ['count' => $late]), 'tone' => 'bad'] : null,
            $errors > 0 ? ['text' => trans_choice('peek.log_errors', $errors, ['count' => $errors]), 'tone' => 'warn'] : null,
            $deploy ? ['text' => __('peek.last_deploy', ['label' => Str::limit($deploy->title ?: $deploy->label(), 60), 'time' => $deploy->deployed_at?->diffForHumans()]), 'tone' => null] : null,
        ]));
    }

    /** @return array<int, array{text: string, tone: ?string}> */
    private function domainHighlights(Domain $domain): array
    {
        $last = $domain->checks()->where('type', 'http')->latest('checked_at')->first();
        $total = $domain->checks()->where('type', 'http')->where('checked_at', '>=', now()->subDay())->count();
        $up = $total > 0 ? $domain->checks()->where('type', 'http')->where('checked_at', '>=', now()->subDay())->where('success', true)->count() : 0;
        $deploy = Deployment::query()->forResource($domain)->latest('deployed_at')->first();

        return array_values(array_filter([
            $last && ! $last->success && $last->message ? ['text' => Str::limit(strip_tags($last->message), 110), 'tone' => 'bad'] : null,
            $total > 0 ? ['text' => __('peek.uptime_24h', ['percent' => rtrim(rtrim(number_format($up / $total * 100, 2, ',', ''), '0'), ',')]), 'tone' => $up < $total ? 'warn' : 'ok'] : null,
            $last ? ['text' => __('peek.last_check', ['time' => $last->checked_at?->diffForHumans()]), 'tone' => null] : null,
            $deploy ? ['text' => __('peek.last_deploy', ['label' => Str::limit($deploy->title ?: $deploy->label(), 60), 'time' => $deploy->deployed_at?->diffForHumans()]), 'tone' => null] : null,
        ]));
    }

    /**
     * The probable cause (a recorded lesson first, otherwise the leading
     * rule-based hypothesis) and who handles it.
     *
     * @return array<int, array{text: string, tone: ?string}>
     */
    private function incidentHighlights(Incident $incident): array
    {
        $cause = null;

        if ($incident->learning) {
            $cause = ['text' => __('peek.cause_recorded', ['cause' => Str::limit($incident->learning->root_cause, 140)]), 'tone' => 'ok'];
        } elseif ($run = Investigations::latestWithResult($incident)) {
            $primary = ($presenter = new Presenter($run))->primary();

            if ($primary && $primary['code'] !== 'insufficient_evidence') {
                $cause = ['text' => __('peek.cause_likely', ['cause' => $presenter->hypothesis($primary)]), 'tone' => 'info'];
            }
        }

        return array_values(array_filter([
            $cause,
            $incident->acknowledged_at ? ['text' => __('peek.acknowledged_by', ['name' => $incident->acknowledger?->name ?? '—', 'time' => $incident->acknowledged_at->diffForHumans()]), 'tone' => null] : null,
            $incident->resolution_notes ? ['text' => __('peek.resolution', ['text' => Str::limit($incident->resolution_notes, 120)]), 'tone' => 'ok'] : null,
        ]));
    }

    /** Active incidents on a resource, newest first. */
    private function incidents(string $type, int $id): ?array
    {
        $items = Incident::query()->active()->where('subject_type', $type)->where('subject_id', $id)
            ->latest('opened_at')->limit(5)->get(['id', 'title', 'severity', 'opened_at'])
            ->map(fn (Incident $i) => [
                'label' => $i->title,
                'meta' => $i->opened_at?->diffForHumans(),
                'tone' => self::SEVERITY_TONE[$i->severity] ?? 'warn',
                'url' => route('incidents.show', $i),
            ])->all();

        return $this->section(__('peek.open_incidents'), $items);
    }

    private function section(string $title, array $items): ?array
    {
        return $items === [] ? null : ['title' => $title, 'items' => $items];
    }

    private function load(float|int|null $percent): ?string
    {
        return $percent === null ? null : ($percent >= 90 ? 'bad' : ($percent >= 75 ? 'warn' : null));
    }

    /** A check state in words (HTTP up/down, certificate states; anything else as "unknown"). */
    private function state(?string $status): string
    {
        $key = 'peek.check.'.$status;

        return __($key) === $key ? __('peek.check.unknown') : __($key);
    }

    private function checkTone(?string $status): ?string
    {
        if (in_array($status, Domain::SSL_INVALID_STATES, true) || in_array($status, ['down', 'handshake_failed'], true)) {
            return 'bad';
        }

        return ['up' => 'ok', 'valid' => 'ok', 'expiring' => 'warn'][$status] ?? null;
    }

    private function peek(array $data): JsonResponse
    {
        return response()->json(['data' => $data + ['text' => null, 'highlights' => [], 'runs' => null]])->header('Cache-Control', 'private, max-age=15');
    }
}
