@php
    use App\Support\Format;
    use App\Support\Time;

    ['servers' => $srv, 'domains' => $dom, 'incidents' => $inc, 'expirations' => $expirations, 'dnsChanges' => $dnsChanges, 'trends' => $trends] = $overview;
    $healthy = $srv['online'] + $dom['healthy'];
    $warnings = $dom['warning'];
    $critical = $srv['offline'] + $dom['critical'];
@endphp

<section aria-label="{{ __('dashboard.overview.label') }}" class="grid gap-4">
    {{-- Key figures: grey, colour only where a number points at a problem. --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
        <a class="ss-stat" href="{{ route('servers.index') }}">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-server"/></svg></span>
            <span class="min-w-0">
                <span class="ss-stat-label block">{{ __('dashboard.overview.servers') }}</span>
                <span class="ss-stat-value block">{{ $srv['total'] }}</span>
                <span class="ss-stat-caption block"><span class="ss-text-ok">{{ __('dashboard.overview.online', ['count' => $srv['online']]) }}</span> · <span @class(['ss-text-bad' => $srv['offline'] > 0])>{{ __('dashboard.overview.offline', ['count' => $srv['offline']]) }}</span>@if ($srv['maintenance']) · {{ __('dashboard.overview.maintenance', ['count' => $srv['maintenance']]) }}@endif</span>
            </span>
        </a>
        <a class="ss-stat" href="{{ route('domains.index') }}">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-globe"/></svg></span>
            <span class="min-w-0">
                <span class="ss-stat-label block">{{ __('dashboard.overview.domains') }}</span>
                <span class="ss-stat-value block">{{ $dom['total'] }}</span>
                <span class="ss-stat-caption block"><span class="ss-text-ok">{{ __('dashboard.overview.healthy', ['count' => $dom['healthy']]) }}</span> · <span @class(['ss-text-bad' => $dom['critical'] + $dom['warning'] > 0])>{{ __('dashboard.overview.with_problems', ['count' => $dom['critical'] + $dom['warning']]) }}</span></span>
            </span>
        </a>
        <a class="ss-stat {{ $inc['critical'] > 0 ? 'is-bad' : ($inc['active'] > 0 ? 'is-warn' : 'is-ok') }}" href="{{ route('incidents.index') }}">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
            <span class="min-w-0">
                <span class="ss-stat-label block">{{ __('dashboard.overview.active_incidents') }}</span>
                <span class="ss-stat-value block">{{ $inc['active'] }}</span>
                <span class="ss-stat-caption block">{{ __('dashboard.overview.incidents_caption', ['critical' => $inc['critical'], 'unacknowledged' => $inc['unacknowledged']]) }}</span>
            </span>
        </a>
        <div class="ss-stat {{ $trends['uptime_24h'] !== null && $trends['uptime_24h'] < 99 ? 'is-warn' : 'is-ok' }}">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
            <span class="min-w-0">
                <span class="ss-stat-label block">{{ __('dashboard.overview.uptime_24h') }}</span>
                <span class="ss-stat-value block">{{ $trends['uptime_24h'] !== null ? Format::percent($trends['uptime_24h'], 2) : '—' }}</span>
                <span class="ss-stat-caption block">{{ __('dashboard.overview.http_checks', ['count' => $trends['checks_24h']]) }}</span>
            </span>
        </div>
        <div class="ss-stat col-span-2 xl:col-span-1">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-pulse"/></svg></span>
            <span class="min-w-0">
                <span class="ss-stat-label block">{{ __('dashboard.overview.resources') }}</span>
                <span class="ss-stat-value block"><span class="ss-text-ok">{{ $healthy }}</span> · <span @class(['ss-text-warn' => $warnings > 0])>{{ $warnings }}</span> · <span @class(['ss-text-bad' => $critical > 0])>{{ $critical }}</span></span>
                <span class="ss-stat-caption block">{{ __('dashboard.overview.resources_caption') }}</span>
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section class="ss-panel">
            <div class="ss-panel-head">
                <div><h2 class="ss-panel-title">{{ __('dashboard.overview.active_incidents') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.critical_first') }}</p></div>
                <a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('incidents.index') }}">{{ __('dashboard.overview.all') }}</a>
            </div>
            @forelse ($inc['list'] as $incident)
                <a href="{{ route('incidents.show', $incident) }}" class="ss-row">
                    <x-severity :severity="$incident->severity" />
                    <span class="ss-row-main"><strong>{{ $incident->title }}</strong><span class="ss-row-sub">{{ $incident->subjectName() }} · {{ Time::relative($incident->opened_at) }} · {{ Lang::has('dashboard.overview.incident_status.'.$incident->status) ? __('dashboard.overview.incident_status.'.$incident->status) : $incident->status }}</span></span>
                </a>
            @empty
                <x-empty-state icon="i-check" :title="__('dashboard.overview.no_incidents')">{{ __('dashboard.overview.no_incidents_text') }}</x-empty-state>
            @endforelse
        </section>

        <section class="ss-panel" id="expiring">
            <div class="ss-panel-head">
                <div><h2 class="ss-panel-title">{{ __('dashboard.overview.expiring') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.expiring_text') }}</p></div>
            </div>
            @forelse ($expirations as $item)
                <a href="{{ route('domains.show', $item['domain']) }}" class="ss-row">
                    <span class="ss-chip">{{ $item['type'] === 'ssl' ? __('dashboard.overview.ssl') : __('dashboard.overview.registration') }}</span>
                    <span class="ss-row-main"><strong>{{ $item['domain']->displayName() }}</strong><span class="ss-row-sub">{{ Time::date($item['at']) }}</span></span>
                    <span @class(['whitespace-nowrap text-sm tabular-nums', 'ss-text-bad font-semibold' => $item['days'] < 8, 'ss-text-warn font-semibold' => $item['days'] >= 8 && $item['days'] <= 30, 'text-muted' => $item['days'] > 30])>{{ $item['days'] < 0 ? __('dashboard.overview.expired') : __('dashboard.overview.days', ['count' => $item['days']]) }}</span>
                </a>
            @empty
                <x-empty-state icon="i-calendar" :title="__('dashboard.overview.nothing_expiring')">{{ __('dashboard.overview.nothing_expiring_text') }}</x-empty-state>
            @endforelse
        </section>

        <section class="ss-panel">
            <div class="ss-panel-head">
                <div><h2 class="ss-panel-title">{{ __('dashboard.overview.dns_changes') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.newest_first') }}</p></div>
            </div>
            @forelse ($dnsChanges as $change)
                <a href="{{ $change->domain ? route('domains.show', $change->domain) : '#' }}" class="ss-row">
                    <span class="ss-chip">{{ $change->record_type }}</span>
                    <span class="ss-row-main"><strong>{{ $change->domain?->displayName() ?? __('dashboard.overview.deleted_domain') }}</strong><span class="ss-row-sub">+{{ count($change->added) }} / −{{ count($change->removed) }} · {{ Time::relative($change->detected_at) }}</span></span>
                </a>
            @empty
                <x-empty-state icon="i-dns" :title="__('dashboard.overview.no_dns_changes')">{{ __('dashboard.overview.no_dns_changes_text') }}</x-empty-state>
            @endforelse
        </section>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="ss-panel">
            <div class="ss-panel-head">
                <div><h2 class="ss-panel-title">{{ __('dashboard.overview.response_time') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.response_time_text') }}</p></div>
            </div>
            <x-line-chart :points="$trends['response']" unit=" ms" :height="140" :label="__('dashboard.overview.response_time_label')" />
        </section>
        <section class="ss-panel">
            <div class="ss-panel-head">
                <div><h2 class="ss-panel-title">{{ __('dashboard.overview.uptime_per_day') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.uptime_per_day_text') }}</p></div>
            </div>
            <div class="ss-strip">
                @foreach ($trends['daily'] as $day)
                    <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                       title="{{ $day['uptime'] === null ? __('dashboard.overview.day_no_data', ['date' => $day['date']]) : __('dashboard.overview.day_uptime', ['date' => $day['date'], 'percent' => Format::percent($day['uptime'], 2), 'checks' => $day['checks']]) }}"></i>
                @endforeach
            </div>
            <div class="mt-1.5 flex justify-between text-xs text-muted"><span>{{ \Carbon\Carbon::parse($trends['daily'][0]['date'])->format('d-m') }}</span><span>{{ __('dashboard.overview.today') }}</span></div>
            <p class="m-0 mt-3 text-xs text-muted">{{ __('dashboard.overview.problems_now', ['http' => $dom['http_problems'], 'ssl' => $dom['ssl_problems'], 'dns' => $dom['dns_problems']]) }}</p>
        </section>
    </div>
</section>
