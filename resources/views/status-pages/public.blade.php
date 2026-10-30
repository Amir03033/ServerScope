@php
    use App\Support\Contrast;
    use App\Support\Format;
    use App\Support\Time;

    $tones = ['operational' => 'ok', 'degraded' => 'warn', 'partial_outage' => 'warn', 'major_outage' => 'bad', 'maintenance' => 'info', 'unknown' => 'muted'];
    $headline = __('status_page.headline.'.$status['overall']);
    $active = collect($status['incidents'])->where('status', '!=', 'resolved');
    $resolved = collect($status['incidents'])->where('status', 'resolved');
    $fmt = fn (?string $iso) => $iso ? Time::dateTime(\Carbon\Carbon::parse($iso)) : '';

    // The customer's accent: used as text only where it has enough contrast
    // (otherwise the neutral accent), with a readable text colour on top of it.
    $accent = Contrast::isHex($status['accent']) ? strtolower($status['accent']) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0b" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex">
    <noscript><meta http-equiv="refresh" content="60"></noscript>
    <title>{{ $status['name'] }} — {{ __('status_page.title_suffix') }}</title>
    <script src="{{ asset('js/theme-init.js') }}?v={{ @filemtime(public_path('js/theme-init.js')) }}"></script>
    @include('partials.vite-assets')
</head>
<body class="ss-public"
      @if ($accent)
          data-accent="{{ $accent }}"
          data-on-accent="{{ Contrast::readableOn($accent) }}"
          @if ($light = Contrast::textOn($accent, Contrast::SURFACE_LIGHT)) data-accent-light="{{ $light }}" @endif
          @if ($dark = Contrast::textOn($accent, Contrast::SURFACE_DARK)) data-accent-dark="{{ $dark }}" @endif
      @endif>
<main class="mx-auto max-w-[54rem] px-4 pt-10 pb-16">
    <header class="ss-public-head">
        <h1 class="m-0 text-[1.75rem] font-semibold tracking-tight">{{ $status['name'] }}</h1>
        @if ($status['description'])<p class="mt-1.5 mb-0 text-muted">{{ $status['description'] }}</p>@endif
    </header>

    <div class="ss-public-banner is-{{ $tones[$status['overall']] ?? 'muted' }}" role="status">
        <span class="ss-dot" aria-hidden="true"></span>{{ $headline }}
    </div>

    @if ($active->isNotEmpty())
        <h2 class="ss-public-h2">{{ __('status_page.active_incidents') }}</h2>
        <section class="ss-panel !py-1.5">
            @foreach ($active as $incident)
                <div class="ss-public-row">
                    <div class="flex items-center justify-between gap-3">
                        <strong>{{ $incident['title'] }}</strong>
                        <span @class(['ss-status', 'ss-status-bad' => $incident['severity'] === 'critical', 'ss-status-warn' => $incident['severity'] !== 'critical'])>{{ __('status_page.incident_status.'.$incident['status']) }}</span>
                    </div>
                    <div class="text-[13px] text-muted">{{ __('status_page.since', ['time' => $fmt($incident['started_at'])]) }}</div>
                </div>
            @endforeach
        </section>
    @endif

    <h2 class="ss-public-h2">{{ __('status_page.components') }}</h2>
    <section class="ss-panel !py-1.5">
        @forelse ($status['components'] as $component)
            <div class="ss-public-row">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0"><strong>{{ $component['name'] }}</strong>@if ($component['description'])<div class="text-[13px] text-muted">{{ $component['description'] }}</div>@endif</div>
                    <span class="ss-status ss-status-{{ $tones[$component['state']] ?? 'muted' }}">{{ __('status_page.state.'.$component['state']) }}</span>
                </div>
                @if ($component['uptime'])
                    @php $known = collect($component['uptime'])->pluck('uptime')->filter(fn ($v) => $v !== null); @endphp
                    <div class="ss-strip mt-2.5 !h-[1.875rem]" role="img" aria-label="{{ __('status_page.availability_per_day') }}">
                        @foreach ($component['uptime'] as $day)
                            <i @class(['up' => $day['uptime'] !== null && $day['uptime'] >= 99.5, 'partial' => $day['uptime'] !== null && $day['uptime'] < 99.5 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                               title="{{ $day['date'] }}: {{ $day['uptime'] === null ? __('status_page.no_data') : Format::percent($day['uptime'], 2) }}"></i>
                        @endforeach
                    </div>
                    <div class="mt-1 flex justify-between gap-2 text-xs text-muted"><span>{{ __('status_page.days_ago') }}</span><span>{{ $known->isNotEmpty() ? __('status_page.available', ['percent' => Format::percent($known->avg(), 2)]) : __('status_page.no_data_yet') }}</span><span>{{ __('status_page.today') }}</span></div>
                @endif
            </div>
        @empty
            <p class="my-3 text-[13px] text-muted">{{ __('status_page.no_components') }}</p>
        @endforelse
    </section>

    @if ($resolved->isNotEmpty())
        <h2 class="ss-public-h2">{{ __('status_page.resolved_recent') }}</h2>
        <section class="ss-panel !py-1.5">
            @foreach ($resolved as $incident)
                <div class="ss-public-row">
                    <strong>{{ $incident['title'] }}</strong>
                    <div class="text-[13px] text-muted">{{ $fmt($incident['started_at']) }} – {{ $fmt($incident['resolved_at']) }}</div>
                </div>
            @endforeach
        </section>
    @endif

    <footer class="mt-10 grid justify-items-center gap-3 text-center text-xs text-muted">
        <x-auto-refresh :seconds="60" class="justify-center" />
        <p class="m-0">{{ __('status_page.updated', ['time' => $fmt($status['generated_at'])]) }}<noscript> · {{ __('status_page.refreshes') }}</noscript> · <a class="ss-public-link" href="{{ request()->url() }}.json">JSON</a> · {{ __('status_page.powered_by') }}</p>
        <form method="POST" action="{{ route('locale.update') }}" class="ss-public-locale" aria-label="{{ __('locale.label') }}">
            @csrf
            @foreach (\App\Support\Locale::SUPPORTED as $code)
                <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" aria-pressed="{{ $code === app()->getLocale() ? 'true' : 'false' }}" title="{{ __('locale.names.'.$code) }}">{{ strtoupper($code) }}</button>
            @endforeach
        </form>
    </footer>
</main>
</body>
</html>
