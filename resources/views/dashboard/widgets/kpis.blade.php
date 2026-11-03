@php
    use App\Support\Format;
@endphp
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
