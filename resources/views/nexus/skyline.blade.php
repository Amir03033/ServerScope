@php
    $config = [
        'urls' => ['graph' => route('nexus.graph')],
        'texts' => __('skyline.js'),
        'initial' => $topology,
    ];
@endphp

<x-layouts.app :title="__('skyline.title')">
    <x-slot:head>
        @vite('resources/js/skyline.js')
    </x-slot:head>

    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('nexus.index') }}">{{ __('nexus.title') }}</a> <span>›</span> {{ __('skyline.title') }}
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ __('skyline.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('skyline.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('nexus.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('skyline.back') }}</a>
    </x-slot:actions>

    <div class="grid gap-5" data-skyline>
        <script type="application/json" id="skyline-config">@json($config)</script>

        <noscript>
            <div class="ss-alert ss-alert-info"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('skyline.noscript') }}</div></div>
        </noscript>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="ss-panel !p-0 overflow-hidden" aria-label="{{ __('skyline.map_label') }}">
                <div class="ss-globe-stage ss-skyline-stage" data-skyline-stage role="img" aria-label="{{ __('skyline.canvas_label') }}">
                    <div class="ss-nexus-overlay" data-skyline-overlay hidden>
                        <div class="flex items-center gap-2.5 text-sm text-muted" role="status"><span class="ss-nexus-spinner" aria-hidden="true"></span>{{ __('skyline.loading') }}</div>
                    </div>
                </div>
                <dl class="ss-skyline-legend border-t border-line p-3 text-xs text-muted">
                    @foreach (['height', 'windows', 'beacons', 'dark'] as $key)
                        <div><dt>{{ __('skyline.legend.'.$key.'_term') }}</dt><dd>{{ __('skyline.legend.'.$key) }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="ss-panel" aria-labelledby="skyline-list-title">
                <div class="ss-panel-head"><div><h2 id="skyline-list-title" class="ss-panel-title">{{ __('skyline.list_title') }}</h2><p class="ss-panel-sub">{{ __('skyline.list_text') }}</p></div></div>
                <p class="m-0 text-sm text-muted" data-skyline-empty hidden>{{ __('skyline.empty') }}</p>
                <ul class="m-0 grid list-none gap-0 p-0" data-skyline-list></ul>
            </section>
        </div>
    </div>
</x-layouts.app>
