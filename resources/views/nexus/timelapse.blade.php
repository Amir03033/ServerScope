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

        <section class="ss-tl" aria-label="{{ __('timelapse.title') }}">
            <div class="ss-tl-head">
                <div class="ss-segmented" role="group" aria-label="{{ __('timelapse.mode') }}">
                    <button type="button" data-tl-mode="cut" aria-pressed="true">{{ __('timelapse.mode_cut') }}</button>
                    <button type="button" data-tl-mode="free" aria-pressed="false">{{ __('timelapse.mode_free') }}</button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="sr-only" for="tl-range">{{ __('timelapse.range') }}</label>
                    <select id="tl-range" class="ss-input ss-tl-select" data-tl-range>
                        <option value="24h">{{ __('timelapse.range_24h') }}</option>
                        <option value="7d">{{ __('timelapse.range_7d') }}</option>
                    </select>
                    <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm" data-tl-export><svg class="ico"><use href="#i-download"/></svg> <span data-tl-export-label>{{ __('timelapse.export') }}</span></button>
                </div>
            </div>

            <div class="ss-skyline-stage ss-tl-stage" data-tl-stage role="img" aria-label="{{ __('timelapse.canvas_label') }}">
                <div class="ss-tl-letterbox" data-tl-letterbox aria-hidden="true"></div>
                <div class="ss-tl-stamp" data-tl-stamp aria-hidden="true"><span data-tl-day></span><strong data-tl-time></strong></div>
                <div class="ss-tl-caption" data-tl-caption hidden></div>
                <div class="ss-tl-card" data-tl-card hidden></div>
                <div class="ss-nexus-overlay" data-tl-overlay hidden>
                    <div class="flex items-center gap-2.5 text-sm text-muted" role="status"><span class="ss-nexus-spinner" aria-hidden="true"></span>{{ __('timelapse.loading') }}</div>
                </div>
            </div>

            <div class="ss-tl-player">
                <button type="button" class="ss-tl-play" data-tl-play aria-label="{{ __('timelapse.play') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path data-tl-play-icon d="M8 5.5v13l11-6.5z"/></svg></button>
                <span class="ss-tl-time" data-tl-now>--:--</span>
                <div class="ss-tl-track">
                    <canvas class="ss-tl-strip" data-tl-strip aria-hidden="true"></canvas>
                    <label class="sr-only" for="tl-scrub">{{ __('timelapse.scrub') }}</label>
                    <input id="tl-scrub" type="range" min="0" max="0" step="1" value="0" class="ss-tl-scrub" data-tl-scrub>
                </div>
                <span class="ss-tl-time is-end" data-tl-end>--:--</span>
                <label class="sr-only" for="tl-speed">{{ __('timelapse.speed') }}</label>
                <select id="tl-speed" class="ss-input ss-tl-select ss-tl-speed" data-tl-speed hidden>
                    @foreach ([1, 2, 4, 8] as $s)<option value="{{ $s }}">{{ $s }}×</option>@endforeach
                </select>
            </div>
            <p class="ss-tl-status" data-tl-status aria-live="polite">{{ __('timelapse.hint') }}</p>
        </section>

        <section class="ss-panel" aria-labelledby="tl-incidents-title">
            <div class="ss-panel-head"><div><h2 id="tl-incidents-title" class="ss-panel-title">{{ __('timelapse.incidents_title') }}</h2><p class="ss-panel-sub">{{ __('timelapse.incidents_text') }}</p></div></div>
            <ul class="m-0 grid list-none gap-0 p-0" data-tl-incidents></ul>
        </section>
    </div>
</x-layouts.app>
