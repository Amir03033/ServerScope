@php
    use App\Models\Domain;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;
    use Illuminate\Support\Str;

    $status = $domain->trashed() ? 'closed' : $domain->displayStatus();
    $sslDays = $domain->sslDaysRemaining();
    $expDays = $domain->expiry_status === 'verified' ? $domain->expiryDaysRemaining() : null;
    $pct = fn (?float $v, int $d = 2) => Format::percent($v, $d);
    $ms = fn (?float $v) => $v === null ? '—' : Format::number($v).' ms';
    $dayCount = fn (?int $d) => $d === null ? '—' : ($d < 0 ? __('domains.expired_cap') : __('domains.days', ['days' => $d]));
    $sslLabel = fn (?string $code) => Lang::has('domains.show.ssl.'.$code) ? __('domains.show.ssl.'.$code) : $code;
    $expiryLabel = fn (?string $code) => Lang::has('domains.show.expiry.'.$code) ? __('domains.show.expiry.'.$code) : $code;

    $chartPoints = array_map(fn ($p) => [
        'label' => Time::format(\Carbon\Carbon::parse($p['at']), 'H:i'),
        'value' => $p['avg_ms'],
        'flag' => $p['failures'] > 0,
    ], $series);

    $sslTone = match (true) {
        in_array($domain->ssl_status, Domain::SSL_INVALID_STATES, true) => 'critical',
        $domain->ssl_status === 'expiring' => 'warning',
        $domain->ssl_status === 'valid' => 'valid',
        default => 'unknown',
    };
    $statTone = ['warning' => 'is-warn', 'critical' => 'is-bad', 'healthy' => 'is-ok'][$status] ?? null;
    $expiryWarn = $expDays !== null && $expDays <= max($domain->expiryWarningDays());
    $lastCheck = $domain->http_last_checked_at ?? $domain->dns_last_checked_at;

    $sections = ['availability', 'certificate', 'dns', 'incidents', 'checks', 'history'];
@endphp

<x-layouts.app :title="$domain->displayName()">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('domains.index') }}">{{ __('domains.title') }}</a> <span>›</span> {{ $domain->displayName() }}
    </x-slot:breadcrumb>
    <x-slot:subtitle>
        <a href="{{ $domain->url() }}" target="_blank" rel="noopener noreferrer nofollow" class="text-accent [overflow-wrap:anywhere]">{{ $domain->url() }}</a>
        @if ($domain->displayName() !== $domain->name) · <span class="font-mono text-xs">{{ $domain->name }}</span> @endif
    </x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('domains.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('domains.overview') }}</a>
        @can('admin')
            @unless ($domain->trashed())
                @if ($domain->is_active)
                    <form method="POST" action="{{ route('domains.check', $domain) }}">
                        @csrf
                        <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('domains.show.check_now') }}</button>
                    </form>
                    <form method="POST" action="{{ route('domains.pause', $domain) }}">
                        @csrf
                        <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-pause"/></svg> {{ __('domains.show.pause') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('domains.resume', $domain) }}">
                        @csrf
                        <button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-play"/></svg> {{ __('domains.show.resume') }}</button>
                    </form>
                @endif
                <a href="{{ route('domains.edit', $domain) }}" class="ss-btn ss-btn-secondary"><svg class="ico"><use href="#i-edit"/></svg> {{ __('domains.edit_button') }}</a>
            @endunless
        @endcan
    </x-slot:actions>

    @include('websites._tabs')

    <div class="grid gap-4">
        @if ($domain->trashed())
            <div class="ss-alert ss-alert-warning" role="status">
                <svg class="ico"><use href="#i-trash"/></svg>
                <div>
                    <strong>{{ __('domains.show.deleted_on', ['date' => Time::dateTime($domain->deleted_at)]) }}</strong> {{ __('domains.show.deleted_text') }}
                    @can('admin')
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <form method="POST" action="{{ route('domains.restore', $domain) }}">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ __('domains.show.restore') }}</button></form>
                            <form method="POST" action="{{ route('domains.force-destroy', $domain) }}" class="flex flex-wrap items-center gap-2" data-confirm="{{ __('domains.show.force_delete_confirm') }}">
                                @csrf @method('DELETE')
                                <input name="confirm_name" class="ss-input !w-auto !py-1.5 !text-sm" placeholder="{{ __('domains.show.confirm_name_placeholder', ['name' => $domain->name]) }}" required aria-label="{{ __('domains.show.confirm_name_label') }}">
                                <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit">{{ __('domains.show.force_delete') }}</button>
                            </form>
                        </div>
                    @endcan
                </div>
            </div>
        @elseif (! $domain->is_active)
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-pause"/></svg><div>{{ __('domains.show.paused_since', ['date' => Time::dateTime($domain->paused_at)]) }}</div></div>
        @elseif ($domain->isInMaintenance())
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-wrench"/></svg><div><strong>{{ __('domains.show.maintenance') }}</strong> {{ $domain->maintenance_ends_at ? __('domains.show.maintenance_until', ['date' => Time::dateTime($domain->maintenance_ends_at)]) : __('domains.show.maintenance_open') }}. {{ __('domains.show.maintenance_text') }} {{ $domain->maintenance_reason }}</div></div>
        @endif

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5" aria-label="{{ __('domains.show.stats_label') }}">
            <article @class(['ss-stat col-span-2 lg:col-span-1', $statTone])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-pulse"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('domains.show.status') }}</div>
                    <div class="mt-1">@if ($domain->trashed())<x-status status="closed" :label="__('domains.deleted')" />@else<x-health :state="\App\Support\Health::domain($domain)" />@endif</div>
                    <div class="ss-stat-caption" title="{{ Time::format($lastCheck, 'd-m-Y H:i:s') }}">{{ __('domains.show.last_check', ['time' => Time::relative($lastCheck)]) }}</div>
                </div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('domains.show.uptime_30d') }}</div>
                    <div class="ss-stat-value">{{ $pct($stats['30d']['uptime']) }}</div>
                    <div class="ss-stat-caption">{{ __('domains.show.coverage', ['coverage' => $pct($stats['30d']['coverage'], 1), 'checks' => $stats['30d']['checks']]) }}</div>
                </div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('domains.show.response_24h') }}</div>
                    <div class="ss-stat-value">{{ $ms($stats['24h']['avg_ms']) }}</div>
                    <div class="ss-stat-caption">{{ __('domains.show.response_caption', ['last' => $ms($domain->http_last_response_ms), 'max' => $ms($stats['24h']['max_ms'])]) }}</div>
                </div>
            </article>
            <article @class(['ss-stat', 'is-bad' => $sslTone === 'critical', 'is-warn' => $sslTone === 'warning'])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-lock"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('domains.show.certificate') }}</div>
                    <div class="ss-stat-value">{{ $dayCount($sslDays) }}</div>
                    <div class="ss-stat-caption">{{ $domain->ssl_enabled ? $sslLabel($domain->ssl_status) : __('domains.show.not_checked') }}</div>
                </div>
            </article>
            <article @class(['ss-stat', 'is-bad' => $expDays !== null && $expDays < 0, 'is-warn' => $expDays !== null && $expDays >= 0 && $expiryWarn])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-calendar"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('domains.show.registration') }}</div>
                    <div class="ss-stat-value">{{ $dayCount($expDays) }}</div>
                    <div class="ss-stat-caption">{{ $domain->expiry_enabled ? $expiryLabel($domain->expiry_status) : __('domains.show.not_checked') }}</div>
                </div>
            </article>
        </section>
    </div>

    {{-- Section navigation: anchors, so it works without JavaScript; app.js marks the current section. --}}
    <nav class="ss-section-nav" aria-label="{{ __('domains.show.sections_label') }}" data-section-nav>
        @foreach ($sections as $id)
            <a href="#{{ $id }}">{{ __('domains.show.sections.'.$id) }}</a>
        @endforeach
    </nav>

    <div class="grid gap-10">
        {{-- Availability --}}
        <section class="ss-section ss-panel" id="availability" aria-labelledby="availability-title">
            <div class="ss-panel-head">
                <div>
                    <h2 id="availability-title" class="ss-panel-title">{{ __('domains.show.http_title') }}</h2>
                    <p class="ss-panel-sub">{{ __('domains.show.http_text') }}</p>
                    @php
                        // Next check from the domain's own interval; stale after two
                        // missed runs of the per-minute scheduler on top of it.
                        $next = $domain->is_active && $domain->http_enabled && $domain->http_last_checked_at ? $domain->http_last_checked_at->copy()->addMinutes((int) $domain->check_interval_minutes) : null;
                        $stale = $next && $next->lt(now()->subMinutes(2)) ? __('health.freshness.stale_checks', ['age' => \App\Support\Time::relative($domain->http_last_checked_at)]) : null;
                    @endphp
                    <x-freshness :rows="[
                        [__('health.freshness.last_check'), $domain->http_last_checked_at],
                        [__('health.freshness.last_success'), $domain->http_last_success_at],
                        [__('health.freshness.next_check'), $next],
                    ]" :stale="$stale" />
                </div>
                @if ($domain->http_enabled)<x-status :status="$domain->http_status" />@else<span class="ss-chip">{{ __('domains.off') }}</span>@endif
            </div>
            <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div class="min-w-0">
                    <x-line-chart :points="$chartPoints" unit=" ms" :label="__('domains.show.chart_label')" :markers="$deployMarkers ?? []" />

                    <h3 class="mt-5 mb-2 text-xs font-semibold text-muted">{{ __('domains.show.daily_title') }}</h3>
                    <div class="ss-strip" role="img" aria-label="{{ __('domains.show.daily_label') }}">
                        @foreach ($daily as $day)
                            <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                               title="{{ $day['date'] }}: {{ $day['uptime'] === null ? __('domains.show.day_none') : __('domains.show.day_value', ['uptime' => Format::percent($day['uptime'], 2), 'checks' => $day['checks']]) }}"></i>
                        @endforeach
                    </div>
                    <p class="mt-1.5 mb-0 text-xs text-muted">{{ __('domains.show.daily_legend') }}</p>
                </div>

                <dl class="ss-dl">
                    <dt>{{ __('domains.show.uptime_24h_7d') }}</dt><dd>{{ $pct($stats['24h']['uptime']) }} / {{ $pct($stats['7d']['uptime']) }}</dd>
                    <dt>{{ __('domains.show.downtime') }}</dt><dd>{{ $stats['30d']['downtime_minutes'] === null ? '—' : __('domains.show.minutes', ['count' => $stats['30d']['downtime_minutes']]) }}</dd>
                    <dt>{{ __('domains.show.last_status_code') }}</dt><dd>{{ $domain->http_last_status_code ?? '—' }}</dd>
                    <dt>{{ __('domains.show.consecutive_failures') }}</dt><dd>{{ __('domains.show.threshold', ['count' => $domain->http_consecutive_failures, 'threshold' => $domain->failure_threshold]) }}</dd>
                    <dt>{{ __('domains.show.last_error') }}</dt><dd>{{ $domain->http_last_error ?? '—' }}</dd>
                    <dt>{{ __('domains.show.interval_timeout') }}</dt><dd>{{ __('domains.show.interval_value', ['minutes' => $domain->check_interval_minutes, 'seconds' => $domain->timeout_seconds]) }}</dd>
                    <dt>{{ __('domains.show.expected') }}</dt><dd>{{ __('domains.show.expected_status', ['codes' => $domain->expected_status_codes ?: '200-399']) }}@if ($domain->expected_content){{ __('domains.show.expected_content', ['content' => $domain->expected_content]) }}@endif</dd>
                </dl>
            </div>
        </section>

        {{-- Certificate and registration --}}
        <div class="ss-section grid gap-4 lg:grid-cols-2" id="certificate">
            <section class="ss-panel" aria-labelledby="ssl-title">
                <div class="ss-panel-head">
                    <div><h2 id="ssl-title" class="ss-panel-title">{{ __('domains.show.ssl_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.ssl_text', ['time' => Time::relative($domain->ssl_last_checked_at)]) }}</p></div>
                    @if ($domain->ssl_enabled)<x-status :status="$sslTone" :label="$sslLabel($domain->ssl_status)" />@else<span class="ss-chip">{{ __('domains.off') }}</span>@endif
                </div>
                @if ($domain->ssl_message)<p @class(['mt-0 mb-3 text-sm', 'ss-text-bad' => $sslTone === 'critical', 'text-muted' => $sslTone !== 'critical'])>{{ $domain->ssl_message }}</p>@endif
                @php $sslDetails = $latestSsl?->details ?? []; @endphp
                <dl class="ss-dl">
                    <dt>{{ __('domains.show.subject') }}</dt><dd>{{ $domain->ssl_subject ?? '—' }}</dd>
                    <dt>{{ __('domains.show.issuer') }}</dt><dd>{{ $domain->ssl_issuer ?? '—' }}</dd>
                    <dt>{{ __('domains.show.valid') }}</dt><dd>{{ $domain->ssl_valid_from ? __('domains.show.valid_range', ['from' => Time::date($domain->ssl_valid_from), 'to' => Time::dateTime($domain->ssl_valid_to)]) : '—' }}</dd>
                    <dt>{{ __('domains.show.san') }}</dt><dd>{{ implode(', ', array_slice($sslDetails['san'] ?? [], 0, 10)) ?: '—' }}</dd>
                    <dt>{{ __('domains.show.protocol') }}</dt><dd>{{ ($sslDetails['protocol'] ?? '—').' / '.($sslDetails['cipher'] ?? '—') }}</dd>
                    <dt>{{ __('domains.show.fingerprint') }}</dt><dd class="font-mono text-[11px]">{{ $domain->ssl_fingerprint ?? '—' }}</dd>
                    <dt>{{ __('domains.show.warnings') }}</dt><dd>{{ __('domains.show.warnings_value', ['days' => implode(', ', $domain->sslWarningDays())]) }}</dd>
                </dl>
            </section>

            <section class="ss-panel" aria-labelledby="expiry-title">
                <div class="ss-panel-head">
                    <div><h2 id="expiry-title" class="ss-panel-title">{{ __('domains.show.expiry_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.expiry_text') }}</p></div>
                    @if ($domain->expiry_enabled)
                        <x-status :status="$domain->expiry_status === 'verified' ? ($expDays < 0 ? 'expired' : ($expiryWarn ? 'expiring' : 'valid')) : 'unknown'" :label="$expiryLabel($domain->expiry_status)" />
                    @else<span class="ss-chip">{{ __('domains.off') }}</span>@endif
                </div>
                @if ($domain->expiry_status !== 'verified' && $domain->expiry_message)
                    <p class="mt-0 mb-3 text-sm text-muted">{{ $domain->expiry_message }}</p>
                @endif
                <dl class="ss-dl">
                    <dt>{{ __('domains.show.expires') }}</dt><dd>{{ $domain->registration_expires_at ? Time::date($domain->registration_expires_at) : __('domains.show.expires_unknown') }}</dd>
                    <dt>{{ __('domains.show.registered') }}</dt><dd>{{ $domain->registered_at ? Time::date($domain->registered_at) : '—' }}</dd>
                    <dt>{{ __('domains.show.registrar') }}</dt><dd>{{ $domain->registrar ?? '—' }}</dd>
                    <dt>{{ __('domains.show.registrable_domain') }}</dt><dd>{{ $domain->registrable_domain ?? '—' }}</dd>
                    <dt>{{ __('domains.show.source') }}</dt><dd>{{ $domain->expiry_source ?? '—' }}</dd>
                    <dt>{{ __('domains.show.queried') }}</dt><dd>{{ $domain->expiry_checked_at ? Time::dateTime($domain->expiry_checked_at) : __('domains.show.queried_never') }}@if ($domain->expiry_next_check_at){{ __('domains.show.queried_next', ['date' => Time::dateTime($domain->expiry_next_check_at)]) }}@endif</dd>
                </dl>
            </section>
        </div>

        {{-- DNS --}}
        <div class="ss-section grid gap-4 lg:grid-cols-2" id="dns">
            <section class="ss-panel" aria-labelledby="dns-title">
                <div class="ss-panel-head">
                    <div><h2 id="dns-title" class="ss-panel-title">{{ __('domains.show.dns_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.dns_text', ['time' => Time::relative($domain->dns_last_checked_at)]) }}@if ($domain->dns_last_changed_at){{ __('domains.show.dns_changed', ['date' => Time::dateTime($domain->dns_last_changed_at)]) }}@endif.</p></div>
                    @if ($domain->dns_enabled)<x-status :status="['ok' => 'healthy', 'failed' => 'critical'][$domain->dns_status] ?? 'unknown'" :label="['ok' => __('domains.show.dns_ok'), 'failed' => __('domains.show.dns_failed')][$domain->dns_status] ?? __('domains.show.dns_unknown')" />@else<span class="ss-chip">{{ __('domains.off') }}</span>@endif
                </div>
                @if ($domain->dns_message)<p @class(['mt-0 mb-3 text-sm', 'ss-text-bad' => $domain->dns_status === 'failed', 'text-muted' => $domain->dns_status !== 'failed'])>{{ $domain->dns_message }}</p>@endif
                @if (collect($dnsRecords)->filter()->isEmpty())
                    <x-empty-state icon="i-dns" :title="__('domains.show.dns_empty_title')">{{ __('domains.show.dns_empty_text') }}</x-empty-state>
                @else
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('domains.show.col_type') }}</th><th scope="col">{{ __('domains.show.col_values') }}</th><th scope="col">{{ __('domains.show.col_since') }}</th></tr></thead>
                            <tbody>
                                @foreach ($dnsRecords as $recordType => $snapshot)
                                    <tr>
                                        <td class="is-primary"><span class="ss-chip">{{ $recordType }}</span></td>
                                        <td data-label="{{ __('domains.show.col_values') }}" class="font-mono text-xs [overflow-wrap:anywhere]">
                                            <span>
                                                @forelse ($snapshot?->records ?? [] as $value)
                                                    <span class="block">{{ Str::limit($value, 180) }}</span>
                                                @empty
                                                    <span class="text-muted">—</span>
                                                @endforelse
                                            </span>
                                        </td>
                                        <td data-label="{{ __('domains.show.col_since') }}" class="whitespace-nowrap text-xs text-muted">{{ $snapshot ? Time::date($snapshot->first_seen_at) : '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="ss-panel" aria-labelledby="dns-changes-title">
                <div class="ss-panel-head"><div><h2 id="dns-changes-title" class="ss-panel-title">{{ __('domains.show.dns_changes_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.dns_changes_text') }}</p></div></div>
                @if ($dnsChanges->isEmpty())
                    <x-empty-state icon="i-history" :title="__('domains.show.dns_changes_empty_title')">{{ __('domains.show.dns_changes_empty_text') }}</x-empty-state>
                @else
                    <ul class="ss-timeline">
                        @foreach ($dnsChanges as $change)
                            <li @class(['is-warn' => in_array($change->record_type, ['NS', 'A', 'AAAA', 'MX'], true), 'is-info' => ! in_array($change->record_type, ['NS', 'A', 'AAAA', 'MX'], true)])>
                                <div class="ss-timeline-when">{{ Time::dateTime($change->detected_at) }}</div>
                                <div class="ss-timeline-what"><span class="ss-chip">{{ $change->record_type }}</span> @if ($change->record_type === 'NS')<strong class="ss-text-warn">{{ __('domains.show.nameservers_changed') }}</strong>@endif</div>
                                <div class="ss-diff">
                                    @foreach ($change->added as $value)<div class="ss-diff-add">+ {{ Str::limit($value, 160) }}</div>@endforeach
                                    @foreach ($change->removed as $value)<div class="ss-diff-del">− {{ Str::limit($value, 160) }}</div>@endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Incidents --}}
        <section class="ss-section ss-panel" id="incidents" aria-labelledby="incidents-title">
            <div class="ss-panel-head">
                <div><h2 id="incidents-title" class="ss-panel-title">{{ __('domains.show.incidents_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.incidents_text') }}</p></div>
                @if (Route::has('incidents.index'))<a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('incidents.index', ['domain' => $domain->id]) }}">{{ __('domains.show.all_incidents') }}</a>@endif
            </div>
            @if ($incidents->isEmpty())
                <x-empty-state icon="i-check" :title="__('domains.show.incidents_empty_title')">{{ __('domains.show.incidents_empty_text') }}</x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('domains.show.col_incident') }}</th><th scope="col">{{ __('domains.show.col_severity') }}</th><th scope="col">{{ __('domains.show.col_status') }}</th><th scope="col">{{ __('domains.show.col_opened') }}</th><th scope="col">{{ __('domains.show.col_duration') }}</th></tr></thead>
                        <tbody>
                            @foreach ($incidents as $incident)
                                <tr>
                                    <td class="is-primary">
                                        @if (Route::has('incidents.show'))<a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>@else{{ $incident->title }}@endif
                                        <span class="block text-xs text-muted">{{ Label::of('incident_source', $incident->source, \App\Models\Incident::SOURCE_LABELS) }}</span>
                                    </td>
                                    <td data-label="{{ __('domains.show.col_severity') }}"><x-severity :severity="$incident->severity" /></td>
                                    <td data-label="{{ __('domains.show.col_status') }}"><x-status :status="$incident->status" /></td>
                                    <td data-label="{{ __('domains.show.col_opened') }}" class="whitespace-nowrap">{{ Time::dateTime($incident->opened_at) }}</td>
                                    <td data-label="{{ __('domains.show.col_duration') }}" class="whitespace-nowrap">{{ $incident->resolved_at ? \Carbon\CarbonInterval::seconds($incident->timeToResolve())->cascade()->locale(app()->getLocale())->forHumans(['short' => true, 'parts' => 2]) : __('domains.show.ongoing') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Check history --}}
        <section class="ss-section ss-panel" id="checks" aria-labelledby="checks-title">
            <div class="ss-panel-head">
                <div><h2 id="checks-title" class="ss-panel-title">{{ __('domains.show.checks_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.checks_text') }}</p></div>
            </div>
            <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('domains.show.checks_filter_label') }}">
                <div class="ss-segmented">
                    @foreach ([null => 'all', 'http' => 'http', 'ssl' => 'ssl', 'dns' => 'dns', 'expiry' => 'expiry'] as $key => $label)
                        <a href="{{ route('domains.show', ['domain' => $domain, 'type' => $key]) }}#checks" @if ($type === $key) aria-current="page" @endif>{{ __('domains.show.check_types.'.$label) }}</a>
                    @endforeach
                </div>
            </nav>
            @if ($checks->isEmpty())
                <x-empty-state icon="i-clock" :title="__('domains.show.checks_empty_title')">{{ __('domains.show.checks_empty_text') }}</x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('domains.show.col_time') }}</th><th scope="col">{{ __('domains.show.col_type') }}</th><th scope="col">{{ __('domains.show.col_result') }}</th><th scope="col" class="num">{{ __('domains.show.col_duration_ms') }}</th><th scope="col">{{ __('domains.show.col_code') }}</th><th scope="col">{{ __('domains.show.col_message') }}</th></tr></thead>
                        <tbody>
                            @foreach ($checks as $check)
                                <tr>
                                    <td class="is-primary whitespace-nowrap">{{ Time::dateTimeSeconds($check->checked_at) }}</td>
                                    <td data-label="{{ __('domains.show.col_type') }}"><span class="ss-chip">{{ strtoupper($check->type) }}</span></td>
                                    <td data-label="{{ __('domains.show.col_result') }}"><x-status :status="$check->success ? 'up' : 'down'" :label="str_replace('_', ' ', $check->result)" /></td>
                                    <td data-label="{{ __('domains.show.col_duration_ms') }}" class="num">{{ $ms($check->response_time_ms) }}</td>
                                    <td data-label="{{ __('domains.show.col_code') }}">{{ $check->http_status_code ?? '' }}</td>
                                    <td data-label="{{ __('domains.show.col_message') }}" class="text-xs [overflow-wrap:anywhere]">{{ $check->message ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$checks" />
            @endif
        </section>

        {{-- History: certificates, dependencies and anomalies --}}
        <div class="ss-section grid gap-4" id="history">
            <div class="grid gap-4 lg:grid-cols-2">
                <section class="ss-panel" aria-labelledby="certificates-title">
                    <div class="ss-panel-head"><div><h2 id="certificates-title" class="ss-panel-title">{{ __('domains.show.certificates_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.certificates_text') }}</p></div></div>
                    @if ($certificates->isEmpty())
                        <x-empty-state icon="i-lock" :title="__('domains.show.certificates_empty_title')">{{ $domain->ssl_enabled ? __('domains.show.certificates_empty_ssl') : __('domains.show.certificates_empty_off') }}</x-empty-state>
                    @else
                        <div class="ss-table-wrap">
                            <table class="ss-table ss-table-cards">
                                <thead><tr><th scope="col">{{ __('domains.show.col_issuer') }}</th><th scope="col">{{ __('domains.show.col_valid') }}</th><th scope="col">{{ __('domains.show.col_seen') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($certificates as $certificate)
                                        <tr>
                                            <td class="is-primary text-sm">{{ $certificate->issuer ?? '—' }}<span class="block font-mono text-xs text-muted">{{ substr($certificate->fingerprint, 0, 23) }}…</span></td>
                                            <td data-label="{{ __('domains.show.col_valid') }}" class="whitespace-nowrap text-sm">{{ Time::date($certificate->valid_from) }} – {{ Time::date($certificate->valid_to) }}</td>
                                            <td data-label="{{ __('domains.show.col_seen') }}" class="whitespace-nowrap text-sm"><span>{{ Time::dateTime($certificate->first_seen_at) }}<span class="block text-xs text-muted">{{ __('domains.show.seen_until', ['date' => Time::dateTime($certificate->last_seen_at)]) }}</span></span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <x-dependencies :resource="$domain" />

                @if (($probeChecks ?? collect())->isNotEmpty())
                    <section class="ss-panel" aria-labelledby="probe-title" data-testid="probe-checks">
                        <div class="ss-panel-head"><div><h2 id="probe-title" class="ss-panel-title">{{ __('probes.domain_title') }}</h2><p class="ss-panel-sub">{{ __('probes.domain_text') }}</p></div></div>
                        @foreach ($probeChecks as $check)
                            <p class="m-0 mt-2 text-sm"><strong>{{ __('probes.outcome.'.($check->outcome ?? 'pending')) }}</strong>
                                <span class="block text-xs text-muted">{{ Time::dateTime($check->checked_at ?? $check->requested_at) }} · {{ $check->probe?->name }}@if ($check->http_status_code) · HTTP {{ $check->http_status_code }}@endif @if ($check->response_time_ms !== null) · {{ Format::number($check->response_time_ms, 0) }} ms @endif @if ($check->error_code) · {{ $check->error_code }}@endif</span></p>
                        @endforeach
                    </section>
                @endif
            </div>

            @if ($predictions->isNotEmpty())
                <section class="ss-panel" aria-labelledby="anomalies-title">
                    <div class="ss-panel-head"><div><h2 id="anomalies-title" class="ss-panel-title">{{ __('domains.show.anomalies_title') }}</h2><p class="ss-panel-sub">{{ __('domains.show.anomalies_text') }}</p></div></div>
                    @include('servers._predictions', ['predictions' => $predictions])
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
