@php
    use App\Services\Reports\StatusPagePresenter;

    $colors = ['operational' => '#22c55e', 'degraded' => '#fbbf24', 'partial_outage' => '#fb923c', 'major_outage' => '#fb7185', 'maintenance' => '#a78bfa', 'unknown' => '#64748b'];
    $headline = __('status_page.headline.'.$status['overall']);
    $active = collect($status['incidents'])->where('status', '!=', 'resolved');
    $resolved = collect($status['incidents'])->where('status', 'resolved');
    $fmt = fn (?string $iso) => $iso ? \App\Support\Time::dateTime(\Carbon\Carbon::parse($iso)) : '';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="60">
    <title>{{ $status['name'] }} — {{ __('status_page.title_suffix') }}</title>
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
        .legend { display: flex; justify-content: space-between; color: #94a3b8; font-size: 12px; margin-top: 4px; }
        h2 { font-size: 17px; margin: 32px 0 12px; }
        .incident { padding: 14px 0; border-bottom: 1px solid #172338; }
        .incident:last-child { border-bottom: 0; }
        footer { margin-top: 40px; color: #94a3b8; font-size: 12px; text-align: center; }
        a { color: var(--accent); }
        .locale { display: inline-flex; gap: 4px; margin-top: 14px; }
        .locale button { padding: 3px 9px; border: 1px solid #1f2e47; border-radius: 7px; background: transparent; color: #8397b1; font: inherit; font-size: 12px; cursor: pointer; }
        .locale button[aria-pressed="true"] { background: #edf4ff; border-color: #edf4ff; color: #070c17; }
        .locale button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $status['name'] }}</h1>
    @if ($status['description'])<p class="desc">{{ $status['description'] }}</p>@endif

    <div class="banner" style="--state: {{ $colors[$status['overall']] }}"><span class="dot"></span>{{ $headline }}</div>

    @if ($active->isNotEmpty())
        <h2>{{ __('status_page.active_incidents') }}</h2>
        <div class="card">
            @foreach ($active as $incident)
                <div class="incident">
                    <div class="row"><strong>{{ $incident['title'] }}</strong><span class="state" style="--state: {{ $incident['severity'] === 'critical' ? $colors['major_outage'] : $colors['degraded'] }}">{{ __('status_page.incident_status.'.$incident['status']) }}</span></div>
                    <div class="sub">{{ __('status_page.since', ['time' => $fmt($incident['started_at'])]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <h2>{{ __('status_page.components') }}</h2>
    <div class="card">
        @forelse ($status['components'] as $component)
            <div class="component" style="--state: {{ $colors[$component['state']] }}">
                <div class="row">
                    <div><strong>{{ $component['name'] }}</strong>@if ($component['description'])<div class="sub">{{ $component['description'] }}</div>@endif</div>
                    <span class="state">{{ __('status_page.state.'.$component['state']) }}</span>
                </div>
                @if ($component['uptime'])
                    @php $known = collect($component['uptime'])->pluck('uptime')->filter(fn ($v) => $v !== null); @endphp
                    <div class="bars" role="img" aria-label="{{ __('status_page.availability_per_day') }}">
                        @foreach ($component['uptime'] as $day)
                            <i style="background: {{ $day['uptime'] === null ? '#25364b' : ($day['uptime'] >= 99.5 ? '#22c55e' : ($day['uptime'] >= 95 ? '#fbbf24' : '#fb7185')) }}" title="{{ $day['date'] }}: {{ $day['uptime'] === null ? __('status_page.no_data') : \App\Support\Format::percent($day['uptime'], 2) }}"></i>
                        @endforeach
                    </div>
                    <div class="legend"><span>{{ __('status_page.days_ago') }}</span><span>{{ $known->isNotEmpty() ? __('status_page.available', ['percent' => \App\Support\Format::percent($known->avg(), 2)]) : __('status_page.no_data_yet') }}</span><span>{{ __('status_page.today') }}</span></div>
                @endif
            </div>
        @empty
            <p class="sub" style="padding:12px 0">{{ __('status_page.no_components') }}</p>
        @endforelse
    </div>

    @if ($resolved->isNotEmpty())
        <h2>{{ __('status_page.resolved_recent') }}</h2>
        <div class="card">
            @foreach ($resolved as $incident)
                <div class="incident">
                    <strong>{{ $incident['title'] }}</strong>
                    <div class="sub">{{ $fmt($incident['started_at']) }} – {{ $fmt($incident['resolved_at']) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <footer>{{ __('status_page.updated', ['time' => $fmt($status['generated_at'])]) }} · {{ __('status_page.refreshes') }} · <a href="{{ request()->url() }}.json">JSON</a> · {{ __('status_page.powered_by') }}
        <form method="POST" action="{{ route('locale.update') }}" class="locale" aria-label="{{ __('locale.label') }}">
            @csrf
            @foreach (\App\Support\Locale::SUPPORTED as $code)
                <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" aria-pressed="{{ $code === app()->getLocale() ? 'true' : 'false' }}" title="{{ __('locale.names.'.$code) }}">{{ strtoupper($code) }}</button>
            @endforeach
        </form>
    </footer>
</main>
</body>
</html>
