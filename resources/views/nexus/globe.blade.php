@php
    $config = [
        'urls' => ['data' => route('nexus.globe.data')],
        'texts' => __('globe.js'),
        'initial' => $globe,
    ];
@endphp

<x-layouts.app :title="__('globe.title')">
    <x-slot:head>
        @vite('resources/js/globe.js')
    </x-slot:head>

    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('nexus.index') }}">{{ __('nexus.title') }}</a> <span>›</span> {{ __('globe.title') }}
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ __('globe.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('globe.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('nexus.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('globe.back') }}</a>
    </x-slot:actions>

    <div class="grid gap-5" data-globe>
        <script type="application/json" id="globe-config">@json($config)</script>

        <noscript>
            <div class="ss-alert ss-alert-info"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('globe.noscript') }}</div></div>
        </noscript>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <section class="ss-panel !p-0 overflow-hidden" aria-label="{{ __('globe.map_label') }}">
                <div class="ss-globe-stage" data-globe-stage role="img" aria-label="{{ __('globe.canvas_label') }}">
                    <div class="ss-nexus-overlay" data-globe-overlay hidden>
                        <div class="flex items-center gap-2.5 text-sm text-muted" role="status"><span class="ss-nexus-spinner" aria-hidden="true"></span>{{ __('globe.loading') }}</div>
                    </div>
                </div>
                <div class="grid gap-1 border-t border-line p-3 text-xs text-muted" data-globe-notice aria-live="polite"></div>
            </section>

            <aside class="grid min-w-0 gap-4">
                <section class="ss-panel" aria-labelledby="globe-locations-title">
                    <div class="ss-panel-head"><div><h2 id="globe-locations-title" class="ss-panel-title">{{ __('globe.locations_title') }}</h2><p class="ss-panel-sub">{{ __('globe.locations_text') }}</p></div></div>
                    <div class="grid gap-2" data-globe-locations></div>
                </section>

                <section class="ss-panel" aria-labelledby="globe-sites-title">
                    <div class="ss-panel-head"><div><h2 id="globe-sites-title" class="ss-panel-title">{{ __('globe.sites_title') }}</h2><p class="ss-panel-sub">{{ __('globe.sites_text') }}</p></div></div>
                    <label class="ss-label" for="globe-select">{{ __('globe.select_label') }}</label>
                    <select id="globe-select" class="ss-input mt-1.5 mb-3" data-globe-select></select>
                    <div class="grid gap-1.5" data-globe-sites></div>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
