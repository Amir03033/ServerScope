<x-layouts.app :title="$server->name">
    <x-slot:head>
        @vite('resources/js/charts.js')
    </x-slot:head>

    <x-slot:styles>
        .actions form { margin: 0; }
        .server-overview {
            display: flex; align-items: center; justify-content: space-between; gap: 18px;
            padding: 20px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background:
                linear-gradient(transparent, transparent),
                var(--panel);
        }
        .server-identity { display: flex; align-items: center; gap: 16px; min-width: 0; }
        .server-title { margin: 0; font-size: 18px; font-weight: 600; overflow-wrap: anywhere; }
        .server-host { margin-top: 2px; color: var(--muted); overflow-wrap: anywhere; }
        .badge-icon {
            display: grid; place-items: center;
            width: 34px; height: 34px; flex: 0 0 34px;
            border: 1px solid color-mix(in srgb, var(--tone, var(--cyan)) 40%, transparent);
            border-radius: 10px;
            background: color-mix(in srgb, var(--tone, var(--cyan)) 14%, var(--ss-surface));
            color: var(--tone, var(--cyan)); font-size: 17px;
        }
        .badge-icon.lg { width: 56px; height: 56px; flex-basis: 56px; border-radius: 15px; font-size: 27px; }
        .badge {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 11px;
            border: 1px solid var(--ss-line-strong); border-radius: 99px; background: var(--ss-surface-2);
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .badge::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .online { color: var(--green); }
        .offline { color: var(--red); }
        .pending { color: var(--yellow); }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
        .stat {
            min-width: 0; padding: 16px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--ss-surface);
        }
        .stat-body { display: flex; align-items: center; gap: 12px; }
        .stat-icon {
            display: grid; place-items: center;
            width: 44px; height: 44px; flex: 0 0 44px;
            border: 1px solid color-mix(in srgb, var(--tone) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--tone) 14%, var(--ss-surface));
            color: var(--tone); font-size: 22px;
        }
        .stat-text { min-width: 0; }
        .stat-label { color: var(--ss-muted); font-size: 12px; }
        .stat-value { margin-top: 1px; font-size: clamp(21px, 1.8vw, 27px); font-weight: 700; letter-spacing: -.6px; line-height: 1.15; color: var(--tone); overflow-wrap: anywhere; }
        .stat-description { margin-top: 2px; color: var(--muted); font-size: 11px; }
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 26px 0 14px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .range-form {
            display: flex; flex-wrap: wrap; gap: 4px; padding: 4px;
            border: 1px solid var(--ss-line-strong); border-radius: 11px; background: var(--ss-surface-2);
        }
        .range-option {
            padding: 7px 12px; border: 0; border-radius: 8px;
            background: transparent; color: var(--muted); font-size: 12px; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .range-option:hover { color: var(--text); }
        .range-option.active { background: linear-gradient(100deg, var(--ss-accent-soft), var(--ss-accent-soft)); color: var(--ss-text); }
        .chart-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .panel { min-width: 0; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--panel); }
        .chart-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .chart-title { display: flex; align-items: center; gap: 11px; min-width: 0; }
        .chart-title h3 { margin: 0; font-size: 14px; font-weight: 600; }
        .chart-title p { margin: 1px 0 0; color: var(--muted); font-size: 11px; }
        .pill { padding: 4px 10px; border: 1px solid var(--ss-line-strong); border-radius: 8px; background: var(--ss-surface-2); color: var(--ss-muted); font-size: 11px; white-space: nowrap; }
        .legend { display: flex; flex-wrap: wrap; gap: 14px; margin: -4px 0 8px; font-size: 11px; color: var(--ss-muted); }
        .legend i { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; }
        .chart-wrap { position: relative; height: 215px; }
        .empty-chart {
            display: grid; place-items: center; min-height: 215px; padding: 20px;
            border: 1px dashed var(--ss-line-strong); border-radius: 10px;
            color: var(--muted); text-align: center; font-size: 12px;
        }
        .empty-chart strong { display: block; margin-bottom: 6px; color: var(--text); font-size: 13px; }
        .network-list { min-height: 215px; display: grid; align-content: center; }
        .network-item { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--ss-line); }
        .network-item:last-child { border-bottom: 0; }
        .network-label { display: flex; align-items: center; gap: 6px; color: var(--ss-muted); font-size: 12px; }
        .network-label .ico { font-size: 16px; }
        .network-value { margin-top: 2px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; font-variant-numeric: tabular-nums; }
        .network-total { text-align: right; color: var(--muted); font-size: 11px; }
        .network-total strong { display: block; margin-top: 2px; color: var(--text); font-size: 14px; font-weight: 600; }
        .disk-list { display: grid; gap: 20px; }
        .disk-item { min-width: 0; }
        .disk-heading { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; font-size: 12px; }
        .disk-heading strong { overflow-wrap: anywhere; }
        .disk-heading span { color: var(--purple); font-weight: 600; white-space: nowrap; }
        .track { height: 7px; overflow: hidden; border-radius: 99px; background: var(--ss-line-strong); }
        .fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--ss-accent), var(--cyan)); }
        .disk-meta { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 14px; margin-top: 7px; color: var(--muted); font-size: 11px; }
        .details-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 28px; }
        .detail-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--ss-line); font-size: 12px;
        }
        .detail-row span { display: flex; align-items: center; gap: 9px; color: var(--muted); }
        .detail-row span .ico { font-size: 15px; color: var(--ss-muted); }
        .detail-row strong { font-weight: 500; text-align: right; overflow-wrap: anywhere; color: var(--ss-text); }
        .empty { padding: 34px 20px; border: 1px dashed var(--ss-line-strong); border-radius: 12px; color: var(--muted); text-align: center; }
        @media (max-width: 1280px) {
    .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
        @media (max-width: 850px) {
    .chart-grid { grid-template-columns: 1fr; }
}
        @media (max-width: 600px) {
    .stats { grid-template-columns: 1fr; }
    .server-overview { flex-direction: column; align-items: flex-start; padding: 16px; }
    .section-heading { flex-direction: column; align-items: flex-start; }
    .range-form { width: 100%; }
    .range-option { flex: 1; text-align: center; }
    .details-list { grid-template-columns: 1fr; }
    .panel { padding: 15px; }
}
        .panel .section-heading { margin-top: 0; }
        .panel .section-heading h2 { font-size: 16px; font-weight: 600; }
    </x-slot:styles>

    <x-slot:breadcrumb>
        <span>›</span>
        <a href="{{ route('servers.index') }}">Servers</a>
        <span>›</span>
        <span>{{ $server->name }}</span>
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ $server->name }}</x-slot:pageTitle>
    <x-slot:subtitle>Serverdetails en historische prestaties.</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.index') }}" class="button">
            <svg class="ico"><use href="#i-arrow-left"/></svg> Terug naar overzicht
        </a>
        @can('admin')
            @unless ($server->trashed())
                <a href="{{ route('servers.edit', $server) }}" class="button">
                    <svg class="ico"><use href="#i-edit"/></svg> Bewerken
                </a>
                @if ($server->isInMaintenance() || $server->hasScheduledMaintenance())
                    <form method="POST" action="{{ route('servers.maintenance.end', $server) }}" class="inline-form">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button button-warning">
                            <svg class="ico"><use href="#i-wrench"/></svg>
                            {{ $server->isInMaintenance() ? 'Onderhoud beëindigen' : 'Gepland onderhoud annuleren' }}
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('servers.maintenance.start', $server) }}" class="inline-form maintenance-form">
                        @csrf
                        <label class="sr-only" for="duration_minutes">Duur onderhoud</label>
                        <select id="duration_minutes" name="duration_minutes" class="input" style="width:auto">
                            <option value="30">30 minuten</option>
                            <option value="60" selected>1 uur</option>
                            <option value="240">4 uur</option>
                            <option value="1440">24 uur</option>
                        </select>
                        <button type="submit" class="button">
                            <svg class="ico"><use href="#i-wrench"/></svg> Onderhoud starten
                        </button>
                    </form>
                @endif
            @endunless
        @endcan
    </x-slot:actions>

    @if (session('agent_token'))
        <x-secret-token :token="session('agent_token')" title="Agenttoken voor {{ $server->name }}">
            <p class="small" style="margin:8px 0 0">
                Zet dit token als <code>SERVERSCOPE_TOKEN</code> in <code>agent/.env</code> op de server en herstart de agent.
                @if (config('serverscope.docs_url'))
                    <a href="{{ config('serverscope.docs_url') }}/server-monitoring" target="_blank" rel="noopener">Installatiehandleiding</a>
                @endif
            </p>
        </x-secret-token>
    @endif

    @if ($server->trashed())
        <div class="flash warning" role="status">
            <svg class="ico"><use href="#i-trash"/></svg>
            <div>
                <strong>Deze server is verwijderd op {{ \App\Support\Time::format($server->deleted_at) }}.</strong>
                De agent kan geen metingen meer versturen. De geschiedenis hieronder blijft beschikbaar.
                @can('admin')
                    <div class="actions" style="justify-content:flex-start;margin-top:10px">
                        <form method="POST" action="{{ route('servers.restore', $server) }}" class="inline-form">
                            @csrf
                            <button type="submit" class="button button-sm">Server herstellen</button>
                        </form>
                        <form method="POST" action="{{ route('servers.force-destroy', $server) }}" class="inline-form"
                              data-confirm="Server en ALLE metingen, alerts en credentials permanent verwijderen? Dit kan niet ongedaan worden gemaakt.">
                            @csrf
                            @method('DELETE')
                            <label class="sr-only" for="confirm_name">Bevestig servernaam</label>
                            <input id="confirm_name" name="confirm_name" class="input" style="width:auto;min-height:32px" placeholder="Typ '{{ $server->name }}'" required>
                            <button type="submit" class="button button-sm button-danger">Permanent verwijderen</button>
                        </form>
                    </div>
                @endcan
            </div>
        </div>
    @elseif ($server->isInMaintenance())
        <div class="flash info" role="status">
            <svg class="ico"><use href="#i-wrench"/></svg>
            <div>
                <strong>Onderhoudsmodus actief</strong>
                {{ $server->maintenance_ends_at ? 'tot '.\App\Support\Time::format($server->maintenance_ends_at) : 'zonder eindtijd' }}.
                Er worden geen nieuwe downtime- of resource-alerts aangemaakt.
                @if ($server->maintenance_reason) Reden: {{ $server->maintenance_reason }} @endif
            </div>
        </div>
    @elseif ($server->hasScheduledMaintenance())
        <div class="flash info" role="status">
            <svg class="ico"><use href="#i-calendar"/></svg>
            <div>
                Onderhoud gepland van {{ \App\Support\Time::format($server->maintenance_starts_at) }}
                {{ $server->maintenance_ends_at ? 'tot '.\App\Support\Time::format($server->maintenance_ends_at) : '' }}.
                @if ($server->maintenance_reason) Reden: {{ $server->maintenance_reason }} @endif
            </div>
        </div>
    @endif

    <section class="server-overview">
        <div class="server-identity">
            <span class="badge-icon lg"><svg class="ico"><use href="#i-server"/></svg></span>
            <div>
                <h2 class="server-title">{{ $server->name }}</h2>
                <div class="server-host">{{ $server->hostname }}</div>
                <div class="muted small" style="margin-top:5px;">
                    {{ $server->operating_system ?? 'Besturingssysteem onbekend' }}
                    · Agent {{ $server->agent_version ?? 'onbekend' }}
                </div>
            </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <span class="chip">{{ $server->environmentLabel() }}</span>
            <x-status :status="$server->trashed() ? 'closed' : $displayStatus" :label="$server->trashed() ? 'Verwijderd' : null" />
        </div>
    </section>

    <section class="stats" aria-label="Actuele serverstatistieken">
        <article class="stat tone-cyan">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-cpu"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">CPU-gebruik</div>
                    <div class="stat-value">
                        {{ $latestSample?->cpu_percent !== null
                            ? number_format($latestSample->cpu_percent, 1, ',', '.') . '%'
                            : '—' }}
                    </div>
                    <div class="stat-description">Laatste meting</div>
                </div>
            </div>
        </article>

        <article class="stat tone-purple">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-ram"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">RAM-gebruik</div>
                    <div class="stat-value">
                        {{ $memoryPercent !== null
                            ? number_format($memoryPercent, 1, ',', '.') . '%'
                            : '—' }}
                    </div>
                    <div class="stat-description">
                        {{ $memoryTotal > 0 ? $formatBytes($memoryUsed) . ' van ' . $formatBytes($memoryTotal) : 'Geen geheugendata' }}
                    </div>
                </div>
            </div>
        </article>

        <article class="stat tone-green">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-bars"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">Load average (1m)</div>
                    <div class="stat-value">
                        {{ $latestSample?->load_1 !== null
                            ? number_format($latestSample->load_1, 2, ',', '.')
                            : '—' }}
                    </div>
                    <div class="stat-description">Systeemgemiddelde over 1 minuut</div>
                </div>
            </div>
        </article>

        <article class="stat tone-yellow">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">Uptime</div>
                    <div class="stat-value">{{ $formatUptime($latestSample?->uptime_seconds) }}</div>
                    <div class="stat-description">Sinds de laatste systeemstart</div>
                </div>
            </div>
        </article>
    </section>

    @include('servers._health')

    @include('servers._agent-updates')

    <div class="section-heading" id="metrics">
        <div>
            <h2>Prestatiemonitoring</h2>
            <p>Historische metingen van deze server.</p>
        </div>

        <form method="GET" action="{{ route('servers.show', $server) }}" class="range-form">
            @foreach ($rangeOptions as $key => $option)
                <button
                    class="range-option {{ $range === $key ? 'active' : '' }}"
                    type="submit"
                    name="range"
                    value="{{ $key }}"
                    aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                >
                    {{ $key === '1h' ? '1u' : ($key === '6h' ? '6u' : ($key === '24h' ? '24u' : $key)) }}
                </button>
            @endforeach
        </form>
    </div>

    <div class="chart-grid">
        <section class="panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon tone-cyan" style="--tone:var(--cyan)"><svg class="ico"><use href="#i-cpu"/></svg></span>
                    <div>
                        <h3>CPU-gebruik</h3>
                        <p>Percentage processorbelasting</p>
                    </div>
                </div>
                <span class="pill">{{ $sampleCount }} meetpunten</span>
            </div>

            @if ($samples->contains(fn ($sample) => $sample->cpu_percent !== null))
                <div class="chart-wrap">
                    <canvas id="cpu-chart" aria-label="Grafiek CPU-gebruik" role="img"></canvas>
                </div>
            @else
                <div class="empty-chart">
                    <div>
                        <strong>Nog geen CPU-historie</strong>
                        Er zijn geen CPU-metingen in deze periode.
                    </div>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon" style="--tone:var(--purple)"><svg class="ico"><use href="#i-ram"/></svg></span>
                    <div>
                        <h3>Geheugengebruik</h3>
                        <p>Gebruikt RAM als percentage van totaal</p>
                    </div>
                </div>
            </div>

            @if ($samples->contains(fn ($sample) =>
                $sample->memory_total_bytes > 0 &&
                $sample->memory_used_bytes !== null &&
                $sample->memory_used_bytes <= $sample->memory_total_bytes
            ))
                <div class="chart-wrap">
                    <canvas id="memory-chart" aria-label="Grafiek RAM-gebruik" role="img"></canvas>
                </div>
            @else
                <div class="empty-chart">
                    <div>
                        <strong>Nog geen geheugengeschiedenis</strong>
                        Er zijn geen geldige RAM-metingen in deze periode.
                    </div>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon" style="--tone:var(--green)"><svg class="ico"><use href="#i-bars"/></svg></span>
                    <div>
                        <h3>Load average</h3>
                        <p>Systeembelasting over 1, 5 en 15 minuten</p>
                    </div>
                </div>
            </div>

            <div class="legend">
                <span><i style="background:var(--ss-accent)"></i>1 minuut</span>
                <span><i style="background:var(--ss-subtle)"></i>5 minuten</span>
                <span><i style="background:var(--ss-ok)"></i>15 minuten</span>
            </div>

            @if ($samples->contains(fn ($sample) =>
                $sample->load_1 !== null ||
                $sample->load_5 !== null ||
                $sample->load_15 !== null
            ))
                <div class="chart-wrap" style="height:190px">
                    <canvas id="load-chart" aria-label="Grafiek load average" role="img"></canvas>
                </div>
            @else
                <div class="empty-chart">
                    <div>
                        <strong>Nog geen loadgegevens</strong>
                        Er zijn geen loadmetingen in deze periode.
                    </div>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon" style="--tone:var(--green)"><svg class="ico"><use href="#i-swap"/></svg></span>
                    <div>
                        <h3>Netwerkverkeer</h3>
                        <p>Data per seconde, op basis van opeenvolgende metingen</p>
                    </div>
                </div>
            </div>

            <div class="network-list">
                <div class="network-item">
                    <div>
                        <div class="network-label cyan" style="color:var(--cyan)">
                            <svg class="ico"><use href="#i-down"/></svg>
                            <span style="color:var(--ss-muted)">Inkomend</span>
                        </div>
                        <div class="network-value">
                            {{ $networkRates['rx'] !== null ? $formatBytes((int) $networkRates['rx']) . '/s' : '—' }}
                        </div>
                        @if ($networkRates['rx'] === null)
                            <div class="muted small">Onvoldoende geldige meetpunten</div>
                        @endif
                    </div>
                    <div class="network-total">
                        Ontvangen totaal
                        <strong>{{ $network['rx'] !== null ? $formatBytes($network['rx']) : '—' }}</strong>
                    </div>
                </div>

                <div class="network-item">
                    <div>
                        <div class="network-label" style="color:var(--purple)">
                            <svg class="ico"><use href="#i-up"/></svg>
                            <span style="color:var(--ss-muted)">Uitgaand</span>
                        </div>
                        <div class="network-value">
                            {{ $networkRates['tx'] !== null ? $formatBytes((int) $networkRates['tx']) . '/s' : '—' }}
                        </div>
                        @if ($networkRates['tx'] === null)
                            <div class="muted small">Onvoldoende geldige meetpunten</div>
                        @endif
                    </div>
                    <div class="network-total">
                        Verzonden totaal
                        <strong>{{ $network['tx'] !== null ? $formatBytes($network['tx']) : '—' }}</strong>
                    </div>
                </div>
            </div>
        </section>
    </div>

    @include('servers._history')

    <div class="section-heading">
        <div>
            <h2>Schijfgebruik</h2>
            <p>Opslagruimte volgens de meest recente meting.</p>
        </div>
    </div>

    <section class="panel">
        @if ($disks->isEmpty())
            <div class="empty">
                Er zijn nog geen schijfgegevens beschikbaar voor deze server.
            </div>
        @else
            <div class="disk-list">
                @foreach ($disks as $disk)
                    <div class="disk-item">
                        <div class="disk-heading">
                            <strong>{{ $disk['path'] }}</strong>
                            <span>
                                {{ $disk['percent'] !== null
                                    ? number_format($disk['percent'], 1, ',', '.') . '%'
                                    : 'Onbekend' }}
                            </span>
                        </div>

                        <div class="track">
                            <div class="fill" style="width: {{ $disk['percent'] ?? 0 }}%"></div>
                        </div>

                        <div class="disk-meta">
                            <span>Gebruikt: {{ $formatBytes($disk['used_bytes']) }}</span>
                            <span>Vrij: {{ $formatBytes($disk['available_bytes']) }}</span>
                            <span>Totaal: {{ $formatBytes($disk['total_bytes']) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <div class="section-heading">
        <div>
            <h2>Systeeminformatie</h2>
            <p>Identificatie en laatste communicatie.</p>
        </div>
    </div>

    <section class="panel">
        <div class="details-list">
            <div class="detail-row">
                <span><svg class="ico"><use href="#i-server"/></svg> Servernaam</span>
                <strong>{{ $server->name }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-globe"/></svg> Hostname</span>
                <strong>{{ $server->hostname }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-swap"/></svg> IP-adres</span>
                <strong>{{ $server->ip_address ?? 'Niet ingesteld' }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-os"/></svg> Besturingssysteem</span>
                <strong>{{ $server->operating_system ?? 'Onbekend' }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-tag"/></svg> Agentversie</span>
                <strong>{{ $server->agent_version ?? 'Onbekend' }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-clock"/></svg> Laatste contact</span>
                <strong title="{{ \App\Support\Time::relative($server->last_seen_at) }}">{{ $server->last_seen_at ? \App\Support\Time::format($server->last_seen_at, 'd-m-Y H:i:s') : 'Nog nooit' }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-metrics"/></svg> Laatste meting</span>
                <strong>{{ $latestSample ? \App\Support\Time::format($latestSample->collected_at, 'd-m-Y H:i:s') : 'Geen metingen' }}</strong>
            </div>

            <div class="detail-row">
                <span><svg class="ico"><use href="#i-bars"/></svg> Metingen in periode</span>
                <strong>{{ $sampleCount }}</strong>
            </div>
        </div>
    </section>

    <div class="grid-2" style="margin-top:14px">
        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Recente alerts</h2>
                    <p>De laatste vijf alerts voor deze server.</p>
                </div>
                <a href="{{ route('alerts.index', ['status' => 'all']) }}" class="button button-sm">Alle alerts</a>
            </div>
            @if ($recentAlerts->isEmpty())
                <x-empty-state icon="i-check" title="Geen alerts">Er zijn nog geen alerts voor deze server geregistreerd.</x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Regel</th><th>Status</th><th>Geactiveerd</th></tr></thead>
                        <tbody>
                            @foreach ($recentAlerts as $alert)
                                <tr>
                                    <td>
                                        {{ $alert->alertRule?->name ?? 'Verwijderde regel' }}
                                        <span class="cell-sub">{{ \App\Models\AlertRule::metricLabel($alert->metric) }}@if ($alert->dimension) · {{ $alert->dimension }}@endif</span>
                                    </td>
                                    <td><x-status :status="$alert->status === 'active' ? 'critical' : ($alert->status === 'resolved' ? 'resolved' : 'disabled')" :label="['active' => 'Actief', 'resolved' => 'Opgelost', 'disabled' => 'Uitgeschakeld'][$alert->status] ?? $alert->status" /></td>
                                    <td class="nowrap">{{ \App\Support\Time::format($alert->triggered_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @can('admin')
            <section class="panel" id="credentials">
                <div class="section-heading">
                    <div>
                        <h2>Agentcredentials</h2>
                        <p>Tokens waarmee de agent zich aanmeldt. Alleen de hash wordt opgeslagen.</p>
                    </div>
                </div>

                @if ($credentials->isEmpty())
                    <x-empty-state icon="i-key" title="Geen credentials">Genereer een token om de agent te koppelen.</x-empty-state>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Naam</th><th>Status</th><th>Laatst gebruikt</th><th><span class="sr-only">Acties</span></th></tr></thead>
                            <tbody>
                                @foreach ($credentials as $credential)
                                    <tr>
                                        <td>
                                            {{ $credential->name }}
                                            <span class="cell-sub">Aangemaakt {{ \App\Support\Time::format($credential->created_at) }}</span>
                                        </td>
                                        <td>
                                            @if ($credential->revoked_at)
                                                <x-status status="closed" label="Ingetrokken" title="{{ \App\Support\Time::format($credential->revoked_at) }}" />
                                            @elseif (! $credential->isActive())
                                                <x-status status="expired" />
                                            @else
                                                <x-status status="valid" label="Actief" />
                                            @endif
                                        </td>
                                        <td class="nowrap">{{ \App\Support\Time::relative($credential->last_used_at) }}</td>
                                        <td>
                                            @if ($credential->revoked_at === null && ! $server->trashed())
                                                <form method="POST" action="{{ route('servers.credentials.destroy', [$server, $credential]) }}" class="row-actions"
                                                      data-confirm="Credential '{{ $credential->name }}' intrekken? Een agent die dit token gebruikt kan daarna geen metingen meer versturen.">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button button-sm button-danger">Intrekken</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @unless ($server->trashed())
                    <div class="form-footer">
                        <form method="POST" action="{{ route('servers.credentials.rotate', $server) }}" class="inline-form"
                              data-confirm="Alle actieve tokens intrekken en een nieuw token genereren? De agent stopt met rapporteren totdat het nieuwe token is ingesteld.">
                            @csrf
                            <button type="submit" class="button button-warning"><svg class="ico"><use href="#i-refresh"/></svg> Token roteren</button>
                        </form>
                        <form method="POST" action="{{ route('servers.credentials.store', $server) }}" class="inline-form" style="display:flex;gap:8px">
                            @csrf
                            <label class="sr-only" for="credential-name">Naam nieuwe credential</label>
                            <input id="credential-name" name="name" class="input" style="width:auto" maxlength="100" required placeholder="Naam extra token">
                            <button type="submit" class="button"><svg class="ico"><use href="#i-plus"/></svg> Extra token</button>
                        </form>
                    </div>
                @endunless
            </section>
        @endcan

        <x-dependencies :resource="$server" />
    </div>

    <x-slot:scripts>
<script>
    window.serverScopeChartData = {{ Illuminate\Support\Js::from($chartData) }};
</script>
    </x-slot:scripts>
</x-layouts.app>
