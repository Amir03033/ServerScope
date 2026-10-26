@php
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $pct = fn (?float $v, int $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
    $period = $input['period'] ?? '30d';
@endphp

<x-layouts.app title="Rapporten">
    <x-slot:subtitle>Berekende beschikbaarheid uit monitoringgegevens — geen contractuele SLA-vaststelling.</x-slot:subtitle>
    <x-slot:actions>
        <a class="button" href="{{ route('reports.print', $input) }}" target="_blank" rel="noopener"><svg class="ico"><use href="#i-file"/></svg> Afdrukken / PDF</a>
        <a class="button" href="{{ route('reports.export', [...$input, 'kind' => 'incidents']) }}"><svg class="ico"><use href="#i-file"/></svg> Incidenten (CSV)</a>
        <a class="button" href="{{ route('reports.export', $input) }}"><svg class="ico"><use href="#i-file"/></svg> Beschikbaarheid (CSV)</a>
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
            Controles van domeinen en pagina's tijdens onderhoud tellen gewoon mee (er wordt niets stilzwijgend uitgesloten).
            Een eigen beschikbaarheidsdoel van een resource gaat vóór het doel hierboven.
        </p>
    </section>

    @include('reports._body')
</x-layouts.app>
