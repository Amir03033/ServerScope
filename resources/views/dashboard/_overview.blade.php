@php
    use App\Support\Time;

    ['servers' => $srv, 'domains' => $dom, 'incidents' => $inc, 'expirations' => $expirations, 'dnsChanges' => $dnsChanges, 'trends' => $trends] = $overview;
    $healthy = $srv['online'] + $dom['healthy'];
    $warnings = $dom['warning'];
    $critical = $srv['offline'] + $dom['critical'];
@endphp

<section class="overview" aria-label="Infrastructuuroverzicht">
    <div class="kpis">
        <a class="kpi tone-cyan" href="{{ route('servers.index') }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-server"/></svg></span>
            <div><div class="kpi-label">Servers</div><div class="kpi-value">{{ $srv['total'] }}</div>
                <div class="kpi-caption"><span class="green">{{ $srv['online'] }} online</span> · <span @class(['red' => $srv['offline'] > 0])>{{ $srv['offline'] }} offline</span>@if ($srv['maintenance']) · {{ $srv['maintenance'] }} onderhoud @endif</div></div>
        </a>
        <a class="kpi tone-purple" href="{{ route('domains.index') }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-globe"/></svg></span>
            <div><div class="kpi-label">Domeinen</div><div class="kpi-value">{{ $dom['total'] }}</div>
                <div class="kpi-caption"><span class="green">{{ $dom['healthy'] }} gezond</span> · <span @class(['red' => $dom['critical'] + $dom['warning'] > 0])>{{ $dom['critical'] + $dom['warning'] }} met problemen</span></div></div>
        </a>
        <a class="kpi {{ $inc['critical'] > 0 ? 'tone-red' : ($inc['active'] > 0 ? 'tone-yellow' : 'tone-green') }}" href="{{ route('incidents.index') }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
            <div><div class="kpi-label">Actieve incidenten</div><div class="kpi-value">{{ $inc['active'] }}</div>
                <div class="kpi-caption">{{ $inc['critical'] }} kritiek · {{ $inc['unacknowledged'] }} niet bevestigd</div></div>
        </a>
        <div class="kpi tone-green">
            <span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span>
            <div><div class="kpi-label">Uptime domeinen (24 uur)</div><div class="kpi-value">{{ $trends['uptime_24h'] !== null ? number_format($trends['uptime_24h'], 2, ',', '.').'%' : '—' }}</div>
                <div class="kpi-caption">{{ $trends['checks_24h'] }} HTTP-controles</div></div>
        </div>
        <div class="kpi tone-muted">
            <span class="kpi-icon"><svg class="ico"><use href="#i-pulse"/></svg></span>
            <div><div class="kpi-label">Resources</div><div class="kpi-value" style="font-size:20px"><span class="green">{{ $healthy }}</span> · <span class="yellow">{{ $warnings }}</span> · <span class="red">{{ $critical }}</span></div>
                <div class="kpi-caption">gezond · waarschuwing · kritiek</div></div>
        </div>
    </div>

    <div class="grid-3" style="margin-bottom:14px">
        <section class="panel">
            <div class="section-heading"><div><h2>Actieve incidenten</h2><p>Kritiek eerst.</p></div><a class="button button-sm" href="{{ route('incidents.index') }}">Alle</a></div>
            @forelse ($inc['list'] as $incident)
                <a href="{{ route('incidents.show', $incident) }}" class="ov-row">
                    <x-status :status="$incident->severity" />
                    <span class="ov-main"><strong>{{ $incident->title }}</strong><span class="cell-sub">{{ $incident->subjectName() }} · {{ Time::relative($incident->opened_at) }} · {{ ['open' => 'open', 'acknowledged' => 'bevestigd', 'investigating' => 'in onderzoek'][$incident->status] ?? $incident->status }}</span></span>
                </a>
            @empty
                <x-empty-state icon="i-check" title="Geen actieve incidenten">Alle servers en domeinen functioneren normaal.</x-empty-state>
            @endforelse
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>Verloopt binnenkort</h2><p>Certificaten en registraties, komende 60 dagen.</p></div></div>
            @forelse ($expirations as $item)
                <a href="{{ route('domains.show', $item['domain']) }}" class="ov-row">
                    <span class="chip">{{ $item['type'] === 'ssl' ? 'SSL' : 'Domein' }}</span>
                    <span class="ov-main"><strong>{{ $item['domain']->displayName() }}</strong><span class="cell-sub">{{ Time::format($item['at'], 'd-m-Y') }}</span></span>
                    <span @class(['nowrap', 'red' => $item['days'] < 8, 'yellow' => $item['days'] >= 8 && $item['days'] <= 30])>{{ $item['days'] < 0 ? 'verlopen' : $item['days'].' d' }}</span>
                </a>
            @empty
                <x-empty-state icon="i-calendar" title="Niets verloopt binnenkort">Er verlopen de komende 60 dagen geen gecontroleerde certificaten of registraties.</x-empty-state>
            @endforelse
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>Recente DNS-wijzigingen</h2><p>Nieuwste eerst.</p></div></div>
            @forelse ($dnsChanges as $change)
                <a href="{{ $change->domain ? route('domains.show', $change->domain) : '#' }}" class="ov-row">
                    <span class="chip">{{ $change->record_type }}</span>
                    <span class="ov-main"><strong>{{ $change->domain?->displayName() ?? 'Verwijderd domein' }}</strong><span class="cell-sub">+{{ count($change->added) }} / −{{ count($change->removed) }} · {{ Time::relative($change->detected_at) }}</span></span>
                </a>
            @empty
                <x-empty-state icon="i-dns" title="Geen DNS-wijzigingen">Gedetecteerde wijzigingen verschijnen hier.</x-empty-state>
            @endforelse
        </section>
    </div>

    <div class="grid-2" style="margin-bottom:22px">
        <section class="panel">
            <div class="section-heading"><div><h2>Responstijd domeinen</h2><p>Gemiddelde per uur over alle domeinen, afgelopen 24 uur.</p></div></div>
            <x-line-chart :points="$trends['response']" unit=" ms" :height="140" label="Gemiddelde responstijd per uur" />
        </section>
        <section class="panel">
            <div class="section-heading"><div><h2>Uptime per dag</h2><p>Alle domeinen, afgelopen 14 dagen (UTC-dagen). Grijs = geen metingen.</p></div></div>
            <div class="strip" style="height:60px">
                @foreach ($trends['daily'] as $day)
                    <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                       title="{{ $day['date'] }}: {{ $day['uptime'] === null ? 'geen metingen' : number_format($day['uptime'], 2, ',', '.').'% van '.$day['checks'].' controles' }}"></i>
                @endforeach
            </div>
            <div class="small muted" style="display:flex;justify-content:space-between;margin-top:6px"><span>{{ \Carbon\Carbon::parse($trends['daily'][0]['date'])->format('d-m') }}</span><span>vandaag</span></div>
            <p class="small muted" style="margin:12px 0 0">Problemen nu: {{ $dom['http_problems'] }} onbereikbaar · {{ $dom['ssl_problems'] }} certificaat · {{ $dom['dns_problems'] }} DNS.</p>
        </section>
    </div>
</section>
