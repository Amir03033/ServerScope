@php
    $config = [
        'urls' => [
            'graph' => route('nexus.graph'),
            'globe' => route('nexus.globe.data'),
            'wall' => route('nexus.wall.data'),
            'exit' => route('dashboard'),
        ],
        'texts' => __('wall.js'),
        'skyline' => __('skyline.js'),
        'globeTexts' => __('globe.js'),
        'pulse' => __('dashboard.pulse.js'),
        'initial' => ['graph' => $topology, 'globe' => $globe, 'wall' => $wall],
    ];
@endphp
<!DOCTYPE html>
{{-- The wall screen is always dark: it is made for a TV in a room. --}}
<html lang="{{ app()->getLocale() }}" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#05070f">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('wall.title') }} — ServerScope</title>
    <link rel="icon" href="{{ asset('app-icons/icon-192.png') }}" type="image/png" data-live-icon>
    <meta name="ss-live" content="{{ route('live.pulse') }}">
    @include('partials.vite-assets')
    @vite('resources/js/wall.js')
</head>
<body class="ss-wall-body">
@include('partials.icons')

<div class="ss-wall" data-wall>
    <script type="application/json" id="wall-config">@json($config)</script>

    <header class="ss-wall-top">
        <div class="ss-wall-brand">
            <x-live-logo class="h-7 w-7" />
            <div class="min-w-0">
                <div class="ss-wall-product">ServerScope</div>
                <div class="ss-wall-team">{{ $team ?? 'ServerScope' }}</div>
            </div>
        </div>
        <div class="ss-wall-status" data-wall-status data-tone="ok" role="status" aria-live="polite"><span class="ss-wall-status-dot" aria-hidden="true"></span><span data-wall-status-text>{{ __('wall.js.status.loading') }}</span></div>
        <div class="ss-wall-right">
            <div class="ss-wall-controls" data-wall-controls>
                <label class="sr-only" for="wall-saver">{{ __('wall.screensaver') }}</label>
                <select id="wall-saver" class="ss-input ss-wall-select" data-wall-saver title="{{ __('wall.screensaver_hint') }}">
                    <option value="0">{{ __('wall.screensaver_off') }}</option>
                    @foreach ([5, 10, 15, 30] as $m)<option value="{{ $m }}">{{ __('wall.screensaver_after', ['minutes' => $m]) }}</option>@endforeach
                </select>
                <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-wall-fullscreen>{{ __('wall.fullscreen') }}</button>
                <a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('dashboard') }}" data-wall-exit>{{ __('wall.exit') }}</a>
            </div>
            <div class="ss-wall-when"><time class="ss-wall-clock" data-wall-clock></time><span class="ss-wall-date" data-wall-date></span></div>
        </div>
    </header>

    <main class="ss-wall-stage">
        <h1 class="sr-only">{{ __('wall.title') }}</h1>
        <section class="ss-wall-layer" data-wall-layer="skyline" aria-label="{{ __('skyline.title') }}"><div class="ss-wall-canvas ss-skyline-stage" data-wall-skyline></div></section>
        <section class="ss-wall-layer" data-wall-layer="globe" aria-label="{{ __('globe.title') }}" hidden><div class="ss-wall-canvas ss-globe-stage" data-wall-globe></div></section>
        <section class="ss-wall-layer" data-wall-layer="pulse" aria-label="{{ __('dashboard.pulse.title') }}" hidden><ul class="ss-pulse-list ss-wall-pulse" role="list" data-wall-pulse></ul></section>
        <section class="ss-wall-layer" data-wall-layer="summary" aria-label="{{ __('wall.summary') }}" hidden><div class="ss-wall-tiles" data-wall-tiles></div></section>
        <section class="ss-wall-layer ss-wall-board" data-wall-layer="servers" aria-label="{{ __('wall.js.scenes.servers') }}" hidden><div class="ss-wall-cards" data-wall-servers></div></section>
        <section class="ss-wall-layer ss-wall-board" data-wall-layer="sites" aria-label="{{ __('wall.js.scenes.sites') }}" hidden><div class="ss-wall-sites" data-wall-sites></div></section>
        <section class="ss-wall-layer ss-wall-board" data-wall-layer="activity" aria-label="{{ __('wall.js.scenes.activity') }}" hidden><div class="ss-wall-columns" data-wall-activity></div></section>
        <section class="ss-wall-layer ss-wall-board" data-wall-layer="incidents" aria-label="{{ __('wall.js.scenes.incidents') }}" hidden><div class="ss-wall-columns" data-wall-incident-board></div></section>

        <aside class="ss-wall-alert" data-wall-alert role="alert" hidden>
            <div class="ss-wall-alert-kicker" data-wall-alert-kicker></div>
            <div class="ss-wall-alert-title" data-wall-alert-title></div>
            <div class="ss-wall-alert-meta" data-wall-alert-meta></div>
        </aside>
    </main>

    <footer class="ss-wall-bottom">
        <ol class="ss-wall-tabs" data-wall-tabs aria-hidden="true"></ol>
        <div class="ss-wall-incidents" data-wall-incidents></div>
    </footer>
</div>

@include('partials.i18n')
</body>
</html>
