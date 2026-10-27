@php
    use App\Models\Incident;
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $tabs = ['active' => ['Actief', $counts['active']], 'unacknowledged' => ['Niet bevestigd', $counts['unacknowledged']], 'resolved' => ['Opgelost', null], 'closed' => ['Gesloten', null], 'all' => ['Alle', null]];
    $points = collect($perDay)->map(fn ($n, $date) => ['label' => \Carbon\Carbon::parse($date)->format('d-m'), 'value' => (float) $n, 'flag' => false])->values()->all();
@endphp

<x-layouts.app title="Incidenten">
    <x-slot:subtitle>Storingen en waarschuwingen van servers en domeinen, met hun volledige geschiedenis.</x-slot:subtitle>
    <x-slot:actions>
        @can('admin')
            <a href="{{ route('incidents.create') }}" class="button button-primary"><svg class="ico"><use href="#i-plus"/></svg> Incident melden</a>
        @endcan
    </x-slot:actions>

    <section class="kpis">
        <a class="kpi tone-red" href="{{ route('incidents.index', ['state' => 'active']) }}"><span class="kpi-icon"><svg class="ico"><use href="#i-siren"/></svg></span><div><div class="kpi-label">Actief</div><div class="kpi-value">{{ $counts['active'] }}</div><div class="kpi-caption">{{ $counts['critical'] }} kritiek</div></div></a>
        <a class="kpi tone-yellow" href="{{ route('incidents.index', ['state' => 'unacknowledged']) }}"><span class="kpi-icon"><svg class="ico"><use href="#i-bell"/></svg></span><div><div class="kpi-label">Wacht op bevestiging</div><div class="kpi-value">{{ $counts['unacknowledged'] }}</div></div></a>
        <div class="kpi tone-cyan"><span class="kpi-icon"><svg class="ico"><use href="#i-clock"/></svg></span><div><div class="kpi-label">MTTA (30 dagen)</div><div class="kpi-value" style="font-size:22px">{{ IncidentMetrics::humanDuration($summary['mtta_seconds']) }}</div><div class="kpi-caption">{{ $summary['acknowledged'] }} van {{ $summary['opened'] }} bevestigd</div></div></div>
        <div class="kpi tone-green"><span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span><div><div class="kpi-label">MTTR (30 dagen)</div><div class="kpi-value" style="font-size:22px">{{ IncidentMetrics::humanDuration($summary['mttr_seconds']) }}</div><div class="kpi-caption">{{ $counts['resolved_7d'] }} opgelost in 7 dagen · MTTD {{ IncidentMetrics::humanDuration($summary['mttd_seconds']) }}</div></div></div>
    </section>

    <section class="panel" style="margin-bottom:14px">
        <div class="section-heading">
            <div><h2>Incidentfrequentie</h2><p>Nieuw geopende incidenten per dag, afgelopen 30 dagen. MTTD = gemiddelde tijd van eerste mislukte controle tot incident; MTTA = gemiddelde tijd tot eerste bevestiging; MTTR = gemiddelde tijd tot oplossing (incidenten geopend in de periode).</p></div>
        </div>
        <x-line-chart :points="$points" :height="120" color="#fb7185" label="Incidenten per dag" />
    </section>

    <section class="panel">
        <nav class="filters" aria-label="Filter op status">
            @foreach ($tabs as $key => [$label, $count])
                <a href="{{ route('incidents.index', array_filter([...request()->except(['state', 'page']), 'state' => $key])) }}" @class(['filter', 'active' => $state === $key])>
                    {{ $label }} @if ($count !== null)<span class="filter-count">{{ $count }}</span>@endif
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('incidents.index') }}" class="toolbar" role="search">
            <input type="hidden" name="state" value="{{ $state }}">
            @foreach (['server', 'domain'] as $key)
                @if (! empty($filters[$key]))<input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">@endif
            @endforeach
            <div class="form-field grow"><label for="q">Zoeken</label><input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Titel"></div>
            <div class="form-field"><label for="severity">Ernst</label>
                <select id="severity" name="severity"><option value="">Alle</option>
                    @foreach (\App\Support\Severity::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['severity'] ?? null) === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="form-field"><label for="source">Bron</label>
                <select id="source" name="source"><option value="">Alle</option>
                    @foreach (Incident::SOURCE_LABELS as $v => $l)<option value="{{ $v }}" @selected(($filters['source'] ?? null) === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="form-field"><label for="assigned">Toegewezen</label>
                <select id="assigned" name="assigned"><option value="">Iedereen</option><option value="me" @selected(($filters['assigned'] ?? null) === 'me')>Aan mij</option><option value="none" @selected(($filters['assigned'] ?? null) === 'none')>Niemand</option></select>
            </div>
            <button class="button" type="submit"><svg class="ico"><use href="#i-search"/></svg> Filteren</button>
        </form>

        @if ($incidents->isEmpty())
            <x-empty-state :icon="$state === 'active' ? 'i-check' : 'i-search'" :title="$state === 'active' ? 'Geen actieve incidenten' : 'Geen incidenten gevonden'">
                {{ $state === 'active' ? 'Alles werkt naar behoren. Nieuwe incidenten verschijnen hier zodra een controle een drempel overschrijdt.' : 'Er zijn geen incidenten die aan de filters voldoen.' }}
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Incident</th><th>Ernst</th><th>Status</th><th>Betreft</th><th>Geopend</th><th>Duur</th><th>Toegewezen</th></tr></thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td>
                                    <a class="row-link" href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>
                                    <span class="cell-sub">#{{ $incident->id }} · {{ $incident->sourceLabel() }}@if ($incident->occurrence_count > 1) · {{ $incident->occurrence_count }}× waargenomen @endif @if ($incident->reopened_count) · {{ $incident->reopened_count }}× heropend @endif @if ($incident->parent_id) · gegroepeerd onder #{{ $incident->parent_id }} @endif @if ($incident->children_count) · {{ $incident->children_count }} gerelateerd @endif</span>
                                </td>
                                <td><x-severity :severity="$incident->severity" /></td>
                                <td><x-status :status="$incident->status" /></td>
                                <td class="small">{{ $incident->subjectName() }}</td>
                                <td class="nowrap" title="{{ Time::format($incident->opened_at, 'd-m-Y H:i:s') }}">{{ Time::relative($incident->opened_at) }}</td>
                                <td class="nowrap">{{ IncidentMetrics::humanDuration($incident->resolved_at ? $incident->timeToResolve() : (int) $incident->opened_at->diffInSeconds(now(), true)) }}</td>
                                <td class="small">{{ $incident->assignee?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$incidents" />
        @endif
    </section>
</x-layouts.app>
