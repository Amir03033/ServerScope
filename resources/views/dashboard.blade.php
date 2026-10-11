<!DOCTYPE html>
<html lang="nl">
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — ServerScope</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #080d18;
            --panel: #101827;
            --border: #263246;
            --muted: #94a3b8;
            --text: #e5e7eb;
            --cyan: #67e8f9;
            --green: #4ade80;
            --red: #f87171;
            --yellow: #facc15;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 32px 20px;
            background: var(--bg);
            color: var(--text);
            font: 15px system-ui, sans-serif;
        }

        main { max-width: 1180px; margin: 0 auto; }

        .topbar, .server-heading, .server-meta, .metric-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            color: var(--cyan);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        h1 { margin: 8px 0; font-size: clamp(28px, 4vw, 38px); }
        h2 { margin-top: 0; font-size: 19px; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }

        .button {
            padding: 10px 15px;
            border: 1px solid #334155;
            border-radius: 9px;
            background: #172235;
            color: white;
            cursor: pointer;
            text-decoration: none;
            font: inherit;
        }

        .button:hover { background: #22314a; }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-top: 30px;
        }

        .stat, .panel, .server-card {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--panel);
        }

        .stat { padding: 20px; }
        .stat-label { color: var(--muted); font-size: 13px; }
        .stat-value { margin-top: 12px; font-size: 30px; font-weight: 750; }

        .panel { margin-top: 26px; padding: 24px; }
        .server-list { display: grid; gap: 14px; margin-top: 20px; }
        .server-card { padding: 20px; }
        .server-heading { align-items: flex-start; }
        .server-name { margin: 0 0 5px; font-size: 17px; }
        .server-meta { justify-content: flex-start; flex-wrap: wrap; margin-top: 10px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #1e293b;
            font-size: 12px;
            font-weight: 700;
        }

        .badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .online { color: var(--green); }
        .offline { color: var(--red); }
        .pending { color: var(--yellow); }

        .metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 22px;
        }

        .metric-label {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 9px;
            font-size: 13px;
        }

        .track {
            height: 7px;
            overflow: hidden;
            border-radius: 99px;
            background: #263246;
        }

        .fill {
            height: 100%;
            border-radius: inherit;
            background: var(--cyan);
        }

        .empty {
            padding: 36px 16px;
            text-align: center;
            border: 1px dashed #334155;
            border-radius: 12px;
        }

        .footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 24px;
        }

        @media (max-width: 720px) {
            body { padding: 24px 14px; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .stat { padding: 16px; }
            .stat-value { font-size: 25px; }
            .panel { padding: 16px; }
            .server-heading { flex-direction: column; }
            .metrics { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main>
    @if (auth()->user()->is_admin)
        @include('servers.register-form')
    @endif
    <header class="topbar">
        <div>
            <div class="brand">SERVERSCOPE</div>
            <h1>Dashboard</h1>
            <p class="muted">Systeemoverzicht en serverstatussen</p>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button" type="submit">Uitloggen</button>
        </form>
    </header>

    <p class="muted small">
        Ingelogd als {{ auth()->user()->email }}
    </p>

    <section class="stats" aria-label="Serverstatistieken">
        <article class="stat">
            <div class="stat-label">Totaal servers</div>
            <div class="stat-value">{{ $serverCount }}</div>
        </article>

        <article class="stat">
            <div class="stat-label">Online</div>
            <div class="stat-value online">{{ $onlineCount }}</div>
        </article>

        <article class="stat">
            <div class="stat-label">Offline</div>
            <div class="stat-value offline">{{ $offlineCount }}</div>
        </article>

        <article class="stat">
            <div class="stat-label">Nog niet verbonden</div>
            <div class="stat-value pending">{{ $pendingCount }}</div>
        </article>
    </section>

    <section class="panel">
        <div>
            <h2>Jouw servers</h2>
            <p class="muted small">
                Online betekent dat er in de afgelopen twee minuten contact is geweest.
            </p>
        </div>

        @if ($servers->isEmpty())
            <div class="empty">
                <h2>Nog geen servers geregistreerd</h2>
                <p class="muted">
                    Registreer eerst een server om hier de monitoringgegevens te zien.
                </p>
            </div>
        @else
            <div class="server-list">
                @foreach ($servers as $server)
                    @php
                        $sample = $server->latestMetricSample;
                        $memoryTotal = $sample?->memory_total_bytes ?? 0;
                        $memoryUsed = $sample?->memory_used_bytes ?? 0;
                        $memoryPercent = $memoryTotal > 0
                            ? min(100, max(0, $memoryUsed / $memoryTotal * 100))
                            : null;
                        $cpuPercent = $sample?->cpu_percent;
                    @endphp

                    <article class="server-card">
                        <div class="server-heading">
                            <div>
                                <h3 class="server-name">{{ $server->name }}</h3>
                                <div class="muted small">{{ $server->hostname }}</div>

                                <div class="server-meta muted small">
                                    <span>
                                        Laatste contact:
                                        {{ $server->last_seen_at?->diffForHumans() ?? 'Nog nooit' }}
                                    </span>

                                    <span>
                                        Agent:
                                        {{ $server->agent_version ?? 'Onbekend' }}
                                    </span>

                                    @if ($server->operating_system)
                                        <span>{{ $server->operating_system }}</span>
                                    @endif
                                </div>
                            </div>

                            <span class="badge {{ $server->display_status }}">
                                @switch($server->display_status)
                                    @case('online')
                                        Online
                                        @break
                                    @case('offline')
                                        Offline
                                        @break
                                    @default
                                        Pending
                                @endswitch
                            </span>
                        </div>

                        @if ($sample)
                            <div class="metrics">
                                <div>
                                    <div class="metric-label">
                                        <span>CPU-gebruik</span>
                                        <strong>
                                            {{ $cpuPercent !== null ? number_format($cpuPercent, 1) . '%' : 'Geen data' }}
                                        </strong>
                                    </div>

                                    <div class="track">
                                        <div class="fill"
                                             style="width: {{ min(100, max(0, $cpuPercent ?? 0)) }}%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="metric-label">
                                        <span>RAM-gebruik</span>
                                        <strong>
                                            {{ $memoryPercent !== null ? number_format($memoryPercent, 1) . '%' : 'Geen data' }}
                                        </strong>
                                    </div>

                                    <div class="track">
                                        <div class="fill"
                                             style="width: {{ $memoryPercent ?? 0 }}%"></div>
                                    </div>
                                </div>
                            </div>

                            <p class="muted small">
                                Meting ontvangen {{ $sample->collected_at?->diffForHumans() ?? 'onbekend tijdstip' }}.
                            </p>
                        @else
                            <p class="muted small">
                                Er zijn nog geen meetgegevens voor deze server.
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <div class="footer muted small">
        ServerScope · Monitoringdashboard
    </div>
</main>
</body>
</html>
