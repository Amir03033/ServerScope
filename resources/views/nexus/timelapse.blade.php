@php
    $config = [
        'urls' => ['data' => route('nexus.timelapse.data')],
        'texts' => __('timelapse.js'),
        'skyline' => __('skyline.js'),
        'team' => $team,
        'initial' => $timelapse,
    ];
@endphp

<x-layouts.app :title="__('timelapse.title')">
    <x-slot:head>
        @vite('resources/js/timelapse.js')
    </x-slot:head>

    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('nexus.index') }}">{{ __('nexus.title') }}</a> <span>›</span> {{ __('timelapse.title') }}
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ __('timelapse.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('timelapse.subtitle') }}</x-slot:subtitle>

    <div class="grid gap-5" data-timelapse>
        <script type="application/json" id="timelapse-config">@json($config)</script>

        <noscript>
            <div class="ss-alert ss-alert-info"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('timelapse.noscript') }}</div></div>
        </noscript>

        <section class="ss-panel !p-0 overflow-hidden" aria-label="{{ __('timelapse.title') }}">
            <div class="ss-globe-stage ss-skyline-stage ss-tl-stage" data-tl-stage role="img" aria-label="{{ __('timelapse.canvas_label') }}">
                <div class="ss-tl-clock" data-tl-clock aria-live="off"></div>
                <div class="ss-tl-caption" data-tl-caption hidden></div>
                <div class="ss-tl-card" data-tl-card hidden></div>
                <div class="ss-nexus-overlay" data-tl-overlay hidden>
                    <div class="flex items-center gap-2.5 text-sm text-muted" role="status"><span class="ss-nexus-spinner" aria-hidden="true"></span>{{ __('timelapse.loading') }}</div>
                </div>
            </div>

            <div class="ss-tl-bar">
                <button type="button" class="ss-btn ss-btn-primary ss-btn-icon" data-tl-play aria-label="{{ __('timelapse.play') }}"><svg class="ico"><use href="#i-play"/></svg></button>
                <div class="ss-tl-track">
                    <div class="ss-tl-marks" data-tl-marks aria-hidden="true"></div>
                    <label class="sr-only" for="tl-scrub">{{ __('timelapse.scrub') }}</label>
                    <input id="tl-scrub" type="range" min="0" max="0" step="1" value="0" class="ss-tl-scrub" data-tl-scrub>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="ss-segmented" role="group" aria-label="{{ __('timelapse.mode') }}">
                        <button type="button" class="ss-segment" data-tl-mode="cut" aria-pressed="true">{{ __('timelapse.mode_cut') }}</button>
                        <button type="button" class="ss-segment" data-tl-mode="free" aria-pressed="false">{{ __('timelapse.mode_free') }}</button>
                    </div>
                    <label class="sr-only" for="tl-range">{{ __('timelapse.range') }}</label>
                    <select id="tl-range" class="ss-input ss-tl-select" data-tl-range>
                        <option value="24h">{{ __('timelapse.range_24h') }}</option>
                        <option value="7d">{{ __('timelapse.range_7d') }}</option>
                    </select>
                    <label class="sr-only" for="tl-speed">{{ __('timelapse.speed') }}</label>
                    <select id="tl-speed" class="ss-input ss-tl-select" data-tl-speed hidden>
                        @foreach ([1, 2, 4, 8] as $s)<option value="{{ $s }}">{{ $s }}×</option>@endforeach
                    </select>
                    <button type="button" class="ss-btn ss-btn-secondary" data-tl-export><svg class="ico"><use href="#i-download"/></svg> <span data-tl-export-label>{{ __('timelapse.export') }}</span></button>
                </div>
            </div>
            <p class="m-0 border-t border-line px-4 py-2.5 text-xs text-muted" data-tl-status aria-live="polite">{{ __('timelapse.hint') }}</p>
        </section>

        <section class="ss-panel" aria-labelledby="tl-incidents-title">
            <div class="ss-panel-head"><div><h2 id="tl-incidents-title" class="ss-panel-title">{{ __('timelapse.incidents_title') }}</h2><p class="ss-panel-sub">{{ __('timelapse.incidents_text') }}</p></div></div>
            <ul class="m-0 grid list-none gap-0 p-0" data-tl-incidents></ul>
        </section>
    </div>
</x-layouts.app>
