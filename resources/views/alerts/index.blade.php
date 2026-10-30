<x-layouts.app :title="'Alerts'">
    <x-slot:styles>
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .stat {
            position: relative; min-width: 0; overflow: hidden;
            padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--ss-surface);
        }
        .stat-body { display: flex; align-items: center; gap: 14px; }
        .stat-icon {
            display: grid; place-items: center;
            width: 46px; height: 46px; flex: 0 0 46px;
            border: 1px solid color-mix(in srgb, var(--tone) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--tone) 14%, var(--ss-surface));
            color: var(--tone); font-size: 23px;
        }
        .stat-label { color: var(--ss-muted); font-size: 12px; }
        .stat-value { margin-top: 1px; font-size: 28px; font-weight: 700; letter-spacing: -.6px; line-height: 1.15; color: var(--tone); }
        .stat-value.plain { color: var(--text); }
        .panel {
            margin-top: 14px; padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--panel);
        }
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 0 0 16px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .filter {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--ss-line-strong); border-radius: 10px;
            background: var(--ss-surface-2); color: var(--muted); text-decoration: none;
            transition: border-color .15s, color .15s, background .15s;
        }
        .filter:hover { border-color: var(--ss-subtle); color: var(--text); }
        .filter.active {
            border-color: var(--ss-accent-line);
            background: linear-gradient(100deg, var(--ss-accent-soft), var(--ss-accent-soft));
            color: var(--ss-text);
        }
        .filter-count { padding: 1px 7px; border-radius: 6px; background: var(--ss-line); font-size: 11px; }
        .filter.active .filter-count { background: var(--ss-accent-soft); }
        .alert-list { display: grid; gap: 12px; }
        .alert-card {
            --accent: var(--green);
            position: relative; min-width: 0; padding: 18px 18px 18px 20px;
            border: 1px solid var(--border); border-radius: 12px;
            background: var(--ss-surface-2); overflow: hidden;
        }
        .alert-card::before {
            content: ""; position: absolute; inset: 0 auto 0 0; width: 3px; background: var(--accent);
        }
        .alert-card.is-active {
            --accent: var(--red);
            background: linear-gradient(160deg, color-mix(in srgb, var(--ss-bad) 8%, var(--ss-surface)), var(--ss-surface-2) 60%);
            border-color: color-mix(in srgb, var(--ss-bad) 35%, var(--ss-line));
        }
        .alert-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .alert-main { display: flex; align-items: flex-start; gap: 13px; min-width: 0; }
        .alert-icon {
            display: grid; place-items: center;
            width: 38px; height: 38px; flex: 0 0 38px;
            border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
            border-radius: 11px;
            background: color-mix(in srgb, var(--accent) 14%, var(--ss-surface));
            color: var(--accent); font-size: 19px;
        }
        .alert-title { margin: 0; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
        .alert-server { display: inline-block; margin-top: 3px; color: var(--muted); font-size: 12px; text-decoration: none; overflow-wrap: anywhere; }
        a.alert-server:hover { color: var(--cyan); }
        .status {
            display: inline-flex; flex-shrink: 0; align-items: center; gap: 7px;
            padding: 5px 10px;
            border: 1px solid var(--ss-line-strong); border-radius: 99px; background: var(--ss-surface-2);
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .status-active { border-color: color-mix(in srgb, var(--ss-bad) 35%, var(--ss-line)); background: color-mix(in srgb, var(--ss-bad) 8%, var(--ss-surface)); color: var(--red); }
        .status-resolved { border-color: color-mix(in srgb, var(--ss-ok) 35%, var(--ss-line)); background: color-mix(in srgb, var(--ss-ok) 8%, var(--ss-surface)); color: var(--green); }
        .status-disabled {
            border-color: color-mix(in srgb, var(--ss-warn) 35%, var(--ss-line));
            background: color-mix(in srgb, var(--ss-warn) 8%, var(--ss-surface));
            color: var(--yellow);
        }
        .alert-metrics {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
            margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--ss-line);
        }
        .metric-label { margin-bottom: 4px; color: var(--muted); font-size: 11px; }
        .metric-value { font-size: 15px; font-weight: 600; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .dimension { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; font-weight: 400; overflow-wrap: anywhere; }
        .alert-times { display: flex; flex-wrap: wrap; gap: 8px 22px; margin-top: 14px; color: var(--muted); font-size: 11px; }
        .alert-times span { display: inline-flex; align-items: center; gap: 6px; }
        .alert-times .ico { font-size: 13px; }
        .empty { padding: 48px 20px; border: 1px dashed var(--ss-line-strong); border-radius: 12px; text-align: center; }
        .empty-icon {
            display: grid; place-items: center; width: 52px; height: 52px; margin: 0 auto 14px;
            border: 1px solid var(--border); border-radius: 14px; background: var(--ss-surface-2); color: var(--cyan); font-size: 24px;
        }
        .empty h3 { margin: 0; font-size: 15px; }
        .empty p { max-width: 400px; margin: 8px auto 0; color: var(--muted); font-size: 12px; }
        .pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 18px; color: var(--muted); font-size: 12px; }
        .pagination-links { display: flex; gap: 8px; }
        .pagination-links a, .pagination-links span {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 36px; padding: 0 12px;
            border: 1px solid var(--ss-line-strong); border-radius: 9px; background: var(--ss-surface-2); text-decoration: none;
        }
        .pagination-links a:hover { border-color: var(--ss-subtle); color: var(--cyan); }
        .pagination-links span { color: var(--ss-subtle); cursor: not-allowed; }
        @media (max-width: 600px) {
    .stats { grid-template-columns: 1fr; }
    .alert-metrics { grid-template-columns: 1fr; }
    .alert-heading { flex-direction: column; }
}
    </x-slot:styles>

    <x-slot:breadcrumb>
        <span>›</span> Alerts
    </x-slot:breadcrumb>

    <x-slot:pageTitle>Alerts</x-slot:pageTitle>
    <x-slot:subtitle>Bekijk incidenten en controleer de status van je infrastructuur.</x-slot:subtitle>

    <x-slot:actions>

        <a href="{{ route('dashboard') }}" class="button">
            <svg class="ico"><use href="#i-arrow-left"/></svg> Dashboard
        </a>
    </x-slot:actions>

    <section class="stats" aria-label="Alertstatistieken">
        <article class="stat tone-red">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
                <div>
                    <div class="stat-label">Actieve alerts</div>
                    <div class="stat-value">{{ number_format($activeCount) }}</div>
                </div>
            </div>
        </article>
        <article class="stat tone-green">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div>
                    <div class="stat-label">Opgelost</div>
                    <div class="stat-value">{{ number_format($resolvedCount) }}</div>
                </div>
            </div>
        </article>
        <article class="stat tone-cyan">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                <div>
                    <div class="stat-label">Totaal geregistreerd</div>
                    <div class="stat-value plain">{{ number_format($totalCount) }}</div>
                </div>
            </div>
        </article>
    </section>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Alertgeschiedenis</h2>
                <p>Bekijk huidige meldingen en eerder opgeloste incidenten.</p>
            </div>
            <span class="muted small">{{ $alerts->total() }} resultaten</span>
        </div>
        <nav class="filters" aria-label="Filter alerts">
            <a
                href="{{ route('alerts.index', ['status' => 'active']) }}"
                @class(['filter', 'active' => $status === 'active'])
                @if ($status === 'active') aria-current="page" @endif
            >
                Actief
                <span class="filter-count">{{ $activeCount }}</span>
            </a>
            <a
                href="{{ route('alerts.index', ['status' => 'resolved']) }}"
                @class(['filter', 'active' => $status === 'resolved'])
                @if ($status === 'resolved') aria-current="page" @endif
            >
                Opgelost
                <span class="filter-count">{{ $resolvedCount }}</span>
            </a>
            <a
                href="{{ route('alerts.index', ['status' => 'disabled']) }}"
                @class(['filter', 'active' => $status === 'disabled'])
                @if ($status === 'disabled') aria-current="page" @endif
            >
                Uitgeschakeld
                <span class="filter-count">{{ $disabledCount }}</span>
            </a>
            <a
                href="{{ route('alerts.index', ['status' => 'all']) }}"
                @class(['filter', 'active' => $status === 'all'])
                @if ($status === 'all') aria-current="page" @endif
            >
                Alles
                <span class="filter-count">{{ $totalCount }}</span>
            </a>
        </nav>
        @if ($alerts->isEmpty())
            <div class="empty">
                <div class="empty-icon">
                    @if ($status === 'active')
                        <svg class="ico"><use href="#i-check"/></svg>
                    @else
                        <svg class="ico"><use href="#i-clock"/></svg>
                    @endif
                </div>
                @if ($status === 'active')
                    <h3>Geen actieve alerts</h3>
                    <p>
                        Er zijn momenteel geen actieve alerts geregistreerd.
                        Nieuwe meldingen verschijnen hier wanneer een ingestelde regel wordt geactiveerd.
                    </p>
                @elseif ($status === 'resolved')
                    <h3>Nog geen opgeloste alerts</h3>
                    <p>Wanneer een actieve alert wordt opgelost, verschijnt deze hier in de geschiedenis.</p>
                @elseif ($status === 'disabled')
                    <h3>Geen uitgeschakelde alerts</h3>
                    <p>Wanneer een alertregel wordt uitgeschakeld, verschijnen bijbehorende actieve alerts hier.</p>
                @else
                    <h3>Nog geen alerts geregistreerd</h3>
                    <p>
                        Er zijn nog geen alerts om weer te geven.
                        Controleer of je alertregels zijn ingesteld en ingeschakeld.
                    </p>
                @endif
            </div>
        @else
            <div class="alert-list">
                @foreach ($alerts as $alert)
                    @php
                        $isActive = $alert->status === 'active';
                        $isDisabled = $alert->status === 'disabled';
                        $metricKey = $alert->metric;
                        $metricLabel = \App\Models\AlertRule::metricLabel($metricKey);
                        $unit = \App\Models\AlertRule::metricUnit($metricKey);
                        $operatorLabels = [
                            '>=' => '≥',
                            '>' => '>',
                            '<=' => '≤',
                            '<' => '<',
                        ];
                        $operator = $operatorLabels[$alert->alertRule?->operator ?? '']
                            ?? ($alert->alertRule?->operator ?? '≥');
                    @endphp
                    <article @class(['alert-card', 'is-active' => $isActive])>
                        <div class="alert-heading">
                            <div class="alert-main">
                                <span class="alert-icon">
                                    <svg class="ico"><use href="#{{ $isActive ? 'i-alert' : 'i-check' }}"/></svg>
                                </span>
                                <div>
                                    <h3 class="alert-title">
                                        {{ $alert->alertRule?->name ?? 'Alertregel verwijderd' }}
                                    </h3>
                                    @if ($alert->server)
                                        <a
                                            class="alert-server"
                                            href="{{ route('servers.show', $alert->server) }}"
                                        >
                                            {{ $alert->server->name }}
                                            · {{ $alert->server->hostname }} →
                                        </a>
                                    @else
                                        <span class="alert-server">Server niet meer beschikbaar</span>
                                    @endif
                                </div>
                            </div>
                            <span @class([
                                'status',
                                'status-active' => $isActive,
                                'status-resolved' => ! $isActive && ! $isDisabled,
                                'status-disabled' => $isDisabled,
                            ])>
                                {{ $isActive ? 'Actief' : ($isDisabled ? 'Uitgeschakeld' : 'Opgelost') }}
                            </span>
                        </div>
                        <div class="alert-metrics">
                            <div>
                                <div class="metric-label">Metric</div>
                                <div class="metric-value">
                                    {{ $metricLabel }}
                                    @if ($metricKey === 'disk_percent' && $alert->dimension)
                                        <span class="dimension">Mountpoint: {{ $alert->dimension }}</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <div class="metric-label">Waargenomen waarde</div>
                                <div class="metric-value {{ $isActive ? 'red' : ($isDisabled ? '' : 'green') }}">
                                    {{ number_format($alert->observed_value, 1, ',', '.') }}{{ $unit }}
                                </div>
                            </div>
                            <div>
                                <div class="metric-label">Drempelwaarde</div>
                                <div class="metric-value">
                                    {{ $operator }}
                                    {{ number_format($alert->threshold, 1, ',', '.') }}{{ $unit }}
                                </div>
                            </div>
                        </div>
                        <div class="alert-times">
                            <span>
                                <svg class="ico"><use href="#i-play"/></svg>
                                Geactiveerd:
                                {{ $alert->triggered_at?->format('d-m-Y H:i:s') ?? 'Onbekend' }}
                            </span>
                            @if ($alert->incident_id)
                        <span>
                            <svg class="ico"><use href="#i-siren"/></svg>
                            <a href="{{ route('incidents.show', $alert->incident_id) }}">Incident #{{ $alert->incident_id }}</a>
                        </span>
                    @endif
                    @if ($alert->resolved_at)
                                <span>
                                    <svg class="ico"><use href="#i-flag"/></svg>
                                    {{ $isDisabled ? 'Uitgeschakeld:' : 'Opgelost:' }}
                                    {{ $alert->resolved_at->format('d-m-Y H:i:s') }}
                                </span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
            @if ($alerts->hasPages())
                <div class="pagination">
                    <span>
                        Resultaten {{ $alerts->firstItem() }}–{{ $alerts->lastItem() }}
                        van {{ $alerts->total() }}
                    </span>
                    <div class="pagination-links">
                        @if ($alerts->onFirstPage())
                            <span aria-disabled="true">← Vorige</span>
                        @else
                            <a href="{{ $alerts->previousPageUrl() }}">← Vorige</a>
                        @endif
                        @if ($alerts->hasMorePages())
                            <a href="{{ $alerts->nextPageUrl() }}">Volgende →</a>
                        @else
                            <span aria-disabled="true">Volgende →</span>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </section>
    
</x-layouts.app>
