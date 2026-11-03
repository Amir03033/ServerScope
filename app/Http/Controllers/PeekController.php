<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * A short look at a server, website or incident for the side panel and
 * the hover cards (read-only). Route model binding runs under the tenant
 * scope, so another team's resource is a 404. One shape for all three:
 * title, status, stats, an optional sparkline, sections of linked items.
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
            'sections' => array_values(array_filter([
                $this->incidents('server', $server->id),
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
            ],
            'spark' => null,
            'text' => $incident->description ? Str::limit(strip_tags($incident->description), 280) : null,
            'sections' => array_values(array_filter([
                $subject && method_exists($subject, 'getKey') ? $this->section(__('peek.concerns'), [[
                    'label' => $incident->subjectName(),
                    'meta' => null,
                    'tone' => null,
                    'url' => match ($incident->subject_type) {
                        'server', Server::class => route('servers.show', $incident->subject_id),
                        'domain', Domain::class => route('domains.show', $incident->subject_id),
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
        return response()->json(['data' => $data + ['text' => null]])->header('Cache-Control', 'private, max-age=15');
    }
}
