@php
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $pct ??= fn (?float $v, int $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
@endphp

    <section class="kpis">
        <div class="kpi tone-cyan"><span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span><div><div class="kpi-label">Gemiddelde uptime</div><div class="kpi-value">{{ $pct($report['average_uptime']) }}</div><div class="kpi-caption">Doel {{ $pct($report['target'], 2) }}</div></div></div>
        <div class="kpi tone-red"><span class="kpi-icon"><svg class="ico"><use href="#i-siren"/></svg></span><div><div class="kpi-label">Incidenten</div><div class="kpi-value">{{ $report['incidents']['opened'] }}</div><div class="kpi-caption">{{ $report['incidents']['by_severity']['critical'] ?? 0 }} kritiek · {{ IncidentMetrics::humanDuration($report['incident_duration_seconds']) }} totale duur</div></div></div>
        <div class="kpi tone-purple"><span class="kpi-icon"><svg class="ico"><use href="#i-search"/></svg></span><div><div class="kpi-label">MTTD</div><div class="kpi-value" style="font-size:22px">{{ IncidentMetrics::humanDuration($report['incidents']['mttd_seconds']) }}</div><div class="kpi-caption">eerste fout → incident</div></div></div>
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
                                <td>@if ($row['meets_target'] === null)<span class="muted small">geen data</span>@else<x-status :status="$row['meets_target'] ? 'healthy' : 'critical'" :label="$row['meets_target'] ? 'Gehaald' : 'Niet gehaald'" />@endif<span class="cell-sub">{{ $pct($row['target'], 2) }}</span></td>
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
                                <td>@if ($row['meets_target'] === null)<span class="muted small">geen data</span>@else<x-status :status="$row['meets_target'] ? 'healthy' : 'critical'" :label="$row['meets_target'] ? 'Gehaald' : 'Niet gehaald'" />@endif<span class="cell-sub">{{ $pct($row['target'], 2) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel" style="margin-top:14px">
        <div class="section-heading"><div><h2>Websitepagina's</h2><p>Beschikbaarheid per gemonitorde pagina uit de eigen paginacontroles; p95 alleen bij minstens 20 metingen.</p></div></div>
        @if ($report['pages']->isEmpty())
            <x-empty-state icon="i-list" title="Geen gemonitorde pagina's" />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Pagina</th><th class="num">Uptime</th><th class="num">Dekking</th><th class="num">Downtime</th><th class="num">Gem.</th><th class="num">p95</th><th class="num">Incidenten</th><th>Doel</th></tr></thead>
                    <tbody>
                        @foreach ($report['pages'] as $row)
                            <tr>
                                <td><a class="row-link break" href="{{ route('pages.show', $row['page']) }}">{{ $row['page']->url }}</a><span class="cell-sub">{{ $row['checks'] }} controles{{ $row['page']->is_active ? '' : ' · gepauzeerd' }}</span></td>
                                <td class="num">{{ $pct($row['uptime']) }}</td>
                                <td class="num">{{ $pct($row['coverage'], 1) }}</td>
                                <td class="num">{{ $row['downtime_minutes'] === null ? '—' : $row['downtime_minutes'].' min' }}</td>
                                <td class="num">{{ $row['avg_ms'] === null ? '—' : number_format($row['avg_ms'], 0, ',', '.').' ms' }}</td>
                                <td class="num">{{ $row['p95_ms'] === null ? '—' : number_format($row['p95_ms'], 0, ',', '.').' ms' }}</td>
                                <td class="num">{{ $row['incidents'] }}</td>
                                <td>@if ($row['meets_target'] === null)<span class="muted small">geen data</span>@else<x-status :status="$row['meets_target'] ? 'healthy' : 'critical'" :label="$row['meets_target'] ? 'Gehaald' : 'Niet gehaald'" />@endif<span class="cell-sub">{{ $pct($row['target'], 2) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid-2" style="margin-top:14px">
        <section class="panel">
            <div class="section-heading"><div><h2>Monitoringdekking</h2><p>Hoeveel van de omgeving in deze periode echt gecontroleerd is. Lage dekking = minder dan 90% van de verwachte controles.</p></div></div>
            @php $c = $report['coverage']; @endphp
            <dl class="dl">
                <dt>Servers met agentdata</dt><dd>{{ $c['servers_reporting'] }} van {{ $c['servers'] }} @if ($c['servers_low_coverage'])<span class="yellow">({{ $c['servers_low_coverage'] }} met lage dekking)</span>@endif</dd>
                <dt>Servers met servicecontroles</dt><dd>{{ $c['servers_with_services'] }} ({{ $c['services'] }} services)</dd>
                <dt>Domeinen gecontroleerd</dt><dd>{{ $c['domains_checked'] }} van {{ $c['domains'] }} @if ($c['domains_low_coverage'])<span class="yellow">({{ $c['domains_low_coverage'] }} met lage dekking)</span>@endif</dd>
                <dt>Pagina's gecontroleerd</dt><dd>{{ $c['pages_checked'] }} van {{ $c['pages'] }} @if ($c['pages_low_coverage'])<span class="yellow">({{ $c['pages_low_coverage'] }} met lage dekking)</span>@endif</dd>
                <dt>Niet gemonitord</dt><dd>{{ $c['pages_pending'] }} pagina's wachten op goedkeuring · {{ $c['pages_paused'] }} gepauzeerd</dd>
            </dl>
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>Terugkerende problemen</h2><p>Problemen die in deze periode meer dan één keer optraden (incl. heropeningen).</p></div></div>
            @if ($report['recurring']->isEmpty())
                <x-empty-state icon="i-check" title="Geen terugkerende problemen" />
            @else
                <ul style="margin:0;padding:0;list-style:none" class="small">
                    @foreach ($report['recurring'] as $row)
                        <li style="padding:7px 0;border-bottom:1px solid #1a2840"><a href="{{ route('incidents.show', $row['latest_id']) }}">{{ $row['title'] }}</a><div class="muted">{{ $row['occurrences'] }}× · totaal {{ IncidentMetrics::humanDuration($row['total_seconds']) }}</div></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="panel" style="margin-top:14px">
        <div class="section-heading"><div><h2>Incidenten naar ernst en bron</h2></div></div>
        <div class="grid-2">
            <dl class="dl">@foreach (\App\Support\Severity::options() as $key => $label)<dt>{{ $label }}</dt><dd>{{ $report['incidents']['by_severity'][$key] ?? 0 }}</dd>@endforeach</dl>
            <dl class="dl">@forelse ($report['incidents']['by_source'] as $source => $count)<dt>{{ \App\Models\Incident::SOURCE_LABELS[$source] ?? $source }}</dt><dd>{{ $count }}</dd>@empty<dt>—</dt><dd>Geen incidenten</dd>@endforelse</dl>
        </div>
    </section>
