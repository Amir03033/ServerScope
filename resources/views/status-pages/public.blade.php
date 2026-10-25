@php
    use App\Services\Reports\StatusPagePresenter;

    $colors = ['operational' => '#22c55e', 'degraded' => '#fbbf24', 'partial_outage' => '#fb923c', 'major_outage' => '#fb7185', 'maintenance' => '#a78bfa', 'unknown' => '#64748b'];
    $headline = [
        'operational' => 'Alle systemen werken normaal',
        'degraded' => 'Sommige systemen werken trager',
        'partial_outage' => 'Gedeeltelijke storing',
        'major_outage' => 'Er is een storing',
        'maintenance' => 'Gepland onderhoud',
        'unknown' => 'Status onbekend',
    ][$status['overall']];
    $statusLabels = ['detected' => 'Gedetecteerd', 'identified' => 'Onderzocht', 'investigating' => 'In onderzoek', 'resolved' => 'Opgelost'];
    $active = collect($status['incidents'])->where('status', '!=', 'resolved');
    $resolved = collect($status['incidents'])->where('status', 'resolved');
    $fmt = fn (?string $iso) => $iso ? \App\Support\Time::format(\Carbon\Carbon::parse($iso), 'd-m-Y H:i') : '';
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="60">
    <title>{{ $status['name'] }} — Status</title>
    <style>
        :root { --accent: {{ $status['accent'] }}; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #070c17; color: #edf4ff; font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 40px 16px 60px; }
        h1 { margin: 0; font-size: 28px; letter-spacing: -.5px; }
        .desc { color: #8397b1; margin: 6px 0 0; }
        .banner { display: flex; align-items: center; gap: 12px; margin: 26px 0; padding: 18px 20px; border-radius: 14px; border: 1px solid color-mix(in srgb, var(--state) 45%, #1f2e47); background: color-mix(in srgb, var(--state) 12%, #0d1627); font-size: 18px; font-weight: 650; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--state); flex: 0 0 12px; box-shadow: 0 0 12px color-mix(in srgb, var(--state) 60%, transparent); }
        .card { border: 1px solid #1f2e47; border-radius: 14px; background: #0d1627; padding: 6px 20px; }
        .component { padding: 16px 0; border-bottom: 1px solid #172338; }
        .component:last-child { border-bottom: 0; }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .state { font-size: 13px; font-weight: 600; color: var(--state); white-space: nowrap; }
        .sub { color: #8397b1; font-size: 13px; }
        .bars { display: flex; gap: 2px; height: 30px; margin-top: 10px; }
        .bars i { flex: 1; border-radius: 2px; background: #25364b; }
        .legend { display: flex; justify-content: space-between; color: #657b95; font-size: 11px; margin-top: 4px; }
        h2 { font-size: 17px; margin: 32px 0 12px; }
        .incident { padding: 14px 0; border-bottom: 1px solid #172338; }
        .incident:last-child { border-bottom: 0; }
        footer { margin-top: 40px; color: #657b95; font-size: 12px; text-align: center; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<main>
    <h1>{{ $status['name'] }}</h1>
    @if ($status['description'])<p class="desc">{{ $status['description'] }}</p>@endif

    <div class="banner" style="--state: {{ $colors[$status['overall']] }}"><span class="dot"></span>{{ $headline }}</div>

    @if ($active->isNotEmpty())
        <h2>Lopende incidenten</h2>
        <div class="card">
            @foreach ($active as $incident)
                <div class="incident">
                    <div class="row"><strong>{{ $incident['title'] }}</strong><span class="state" style="--state: {{ $incident['severity'] === 'critical' ? $colors['major_outage'] : $colors['degraded'] }}">{{ $statusLabels[$incident['status']] }}</span></div>
                    <div class="sub">Sinds {{ $fmt($incident['started_at']) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <h2>Onderdelen</h2>
    <div class="card">
        @forelse ($status['components'] as $component)
            <div class="component" style="--state: {{ $colors[$component['state']] }}">
                <div class="row">
                    <div><strong>{{ $component['name'] }}</strong>@if ($component['description'])<div class="sub">{{ $component['description'] }}</div>@endif</div>
                    <span class="state">{{ StatusPagePresenter::STATE_LABELS[$component['state']] }}</span>
                </div>
                @if ($component['uptime'])
                    @php $known = collect($component['uptime'])->pluck('uptime')->filter(fn ($v) => $v !== null); @endphp
                    <div class="bars" role="img" aria-label="Beschikbaarheid per dag">
                        @foreach ($component['uptime'] as $day)
                            <i style="background: {{ $day['uptime'] === null ? '#25364b' : ($day['uptime'] >= 99.5 ? '#22c55e' : ($day['uptime'] >= 95 ? '#fbbf24' : '#fb7185')) }}" title="{{ $day['date'] }}: {{ $day['uptime'] === null ? 'geen gegevens' : number_format($day['uptime'], 2, ',', '.').'%' }}"></i>
                        @endforeach
                    </div>
                    <div class="legend"><span>90 dagen geleden</span><span>{{ $known->isNotEmpty() ? number_format($known->avg(), 2, ',', '.').'% beschikbaar' : 'nog geen gegevens' }}</span><span>vandaag</span></div>
                @endif
            </div>
        @empty
            <p class="sub" style="padding:12px 0">Er zijn nog geen onderdelen gepubliceerd.</p>
        @endforelse
    </div>

    @if ($resolved->isNotEmpty())
        <h2>Opgelost (laatste 7 dagen)</h2>
        <div class="card">
            @foreach ($resolved as $incident)
                <div class="incident">
                    <strong>{{ $incident['title'] }}</strong>
                    <div class="sub">{{ $fmt($incident['started_at']) }} – {{ $fmt($incident['resolved_at']) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <footer>Bijgewerkt {{ $fmt($status['generated_at']) }} · ververst elke minuut · <a href="{{ request()->url() }}.json">JSON</a> · aangedreven door ServerScope</footer>
</main>
</body>
</html>
