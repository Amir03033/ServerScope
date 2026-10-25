@php
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $pct = fn (?float $v, int $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
    $period = $input['period'] ?? '30d';
@endphp

<x-layouts.app title="Rapporten">
    <x-slot:subtitle>Berekende beschikbaarheid uit monitoringgegevens — geen contractuele SLA-vaststelling.</x-slot:subtitle>
    <x-slot:actions>
        <a class="button" href="{{ route('reports.export', $input) }}"><svg class="ico"><use href="#i-file"/></svg> CSV exporteren</a>
    </x-slot:actions>

    <section class="panel" style="margin-bottom:14px">
        <form method="GET" action="{{ route('reports.index') }}" class="toolbar" style="margin:0">
            <div class="form-field"><label for="period">Periode</label>
                <select id="period" name="period" onchange="document.getElementById('custom-range').hidden = this.value !== 'custom'">
                    @foreach (['7d' => 'Laatste 7 dagen', '30d' => 'Laatste 30 dagen', '90d' => 'Laatste 90 dagen', 'month' => 'Deze maand', 'custom' => 'Aangepast'] as $v => $l)
                        <option value="{{ $v }}" @selected($period === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <span id="custom-range" style="display:contents" @if ($period !== 'custom') hidden @endif>
                <div class="form-field"><label for="from">Van</label><input id="from" name="from" type="datetime-local" value="{{ $input['from'] ?? '' }}"></div>
                <div class="form-field"><label for="to">Tot</label><input id="to" name="to" type="datetime-local" value="{{ $input['to'] ?? '' }}"></div>
            </span>
            <div class="form-field"><label for="target">Doel (%)</label><input id="target" name="target" type="number" step="0.01" min="90" max="100" value="{{ $report['target'] }}"></div>
            <button class="button button-primary" type="submit">Toon rapport</button>
        </form>
        <p class="small muted" style="margin:10px 0 0">
            {{ Time::format($report['from']) }} t/m {{ Time::format($report['to']) }} ({{ Time::zone() }}).
            Uptime telt alleen uitgevoerde controles; ontbrekende metingen verlagen de <em>dekking</em> en tellen nooit als beschikbaar.
            Serverdowntime = duur van agent-offline-incidenten; tijdens gepland onderhoud worden geen incidenten aangemaakt.
        </p>
    </section>

    <section class="kpis">
        <div class="kpi tone-cyan"><span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span><div><div class="kpi-label">Gemiddelde uptime</div><div class="kpi-value">{{ $pct($report['average_uptime']) }}</div><div class="kpi-caption">Doel {{ $pct($report['target'], 2) }}</div></div></div>
        <div class="kpi tone-red"><span class="kpi-icon"><svg class="ico"><use href="#i-siren"/></svg></span><div><div class="kpi-label">Incidenten</div><div class="kpi-value">{{ $report['incidents']['opened'] }}</div><div class="kpi-caption">{{ $report['incidents']['by_severity']['critical'] ?? 0 }} kritiek</div></div></div>
        <div class="kpi tone-yellow"><span class="kpi-icon"><svg class="ico"><use href="#i-clock"/></svg></span><div><div class="kpi-label">MTTA</div><div class="kpi-value" style="font-size:22px">{{ IncidentMetrics::humanDuration($report['incidents']['mtta_seconds']) }}</div></div></div>
        <div class="kpi tone-green"><span class="kpi-icon"><svg class="ico"><use href="#i-wrench"/></svg></span><div><div class="kpi-label">MTTR</div><div class="kpi-value" style="font-size:22px">{{ IncidentMetrics::humanDuration($report['incidents']['mttr_seconds']) }}</div></div></div>
    </section>

    <section class="panel" style="margin-bottom:14px">
        <div class="section-heading"><div><h2>Domeinen</h2><p>HTTP-beschikbaarheid en responstijden.</p></div></div>
        @if ($report['domains']->isEmpty())
            <x-empty-state icon="i-globe" title="Geen domeinen in deze periode" />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Domein</th><th class="num">Uptime</th><th class="num">Dekking</th><th class="num">Downtime</th><th class="num">Gem. respons</th><th class="num">Max</th><th class="num">Incidenten</th><th>Doel</th></tr></thead>
                    <tbody>
                        @foreach ($report['domains'] as $row)
                            <tr>
                                <td><a class="row-link" href="{{ route('domains.show', $row['domain']) }}">{{ $row['domain']->displayName() }}</a><span class="cell-sub">{{ $row['checks'] }} controles</span></td>
                                <td class="num">{{ $pct($row['uptime']) }}</td>
                                <td class="num">{{ $pct($row['coverage'], 1) }}</td>
                                <td class="num">{{ $row['downtime_minutes'] === null ? '—' : $row['downtime_minutes'].' min' }}</td>
                                <td class="num">{{ $row['avg_ms'] === null ? '—' : number_format($row['avg_ms'], 0, ',', '.').' ms' }}</td>
                                <td class="num">{{ $row['max_ms'] === null ? '—' : number_format($row['max_ms'], 0, ',', '.').' ms' }}</td>
                                <td class="num">{{ $row['incidents'] }}</td>
                                <td>@if ($row['meets_target'] === null)<span class="muted small">geen data</span>@else<x-status :status="$row['meets_target'] ? 'healthy' : 'critical'" :label="$row['meets_target'] ? 'Gehaald' : 'Niet gehaald'" />@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="section-heading"><div><h2>Servers</h2><p>Beschikbaarheid op basis van agent-offline-incidenten; dekking = ontvangen metingen / verwachte metingen (één per minuut).</p></div></div>
        @if ($report['servers']->isEmpty())
            <x-empty-state icon="i-server" title="Geen servers in deze periode" />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Server</th><th class="num">Uptime</th><th class="num">Dekking</th><th class="num">Downtime</th><th class="num">Metingen</th><th class="num">Incidenten</th><th>Doel</th></tr></thead>
                    <tbody>
                        @foreach ($report['servers'] as $row)
                            <tr>
                                <td><a class="row-link" href="{{ route('servers.show', $row['server']) }}">{{ $row['server']->name }}</a>@if ($row['server']->trashed())<span class="cell-sub">verwijderd</span>@endif</td>
                                <td class="num">{{ $pct($row['uptime']) }}</td>
                                <td class="num">{{ $pct($row['coverage'], 1) }}</td>
                                <td class="num">{{ $row['downtime_minutes'] }} min</td>
                                <td class="num">{{ number_format($row['samples'], 0, ',', '.') }}</td>
                                <td class="num">{{ $row['incidents'] }}</td>
                                <td>@if ($row['meets_target'] === null)<span class="muted small">geen data</span>@else<x-status :status="$row['meets_target'] ? 'healthy' : 'critical'" :label="$row['meets_target'] ? 'Gehaald' : 'Niet gehaald'" />@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
