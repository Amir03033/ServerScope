@php
    $meta = $topology['meta'];
    $counts = $meta['counts'];
    $nodes = collect($topology['nodes']);
    $critical = $nodes->where('health', 'critical')->count();
    $warning = $nodes->where('health', 'warning')->count();
    $affected = $nodes->where('incidents.active', '>', 0)->count();
    $confirmed = collect($topology['edges'])->where('origin', 'confirmed')->count();
    $byType = $counts['by_type'];

    $pageStatus = [...__('labels.page_status'), 'paused' => __('status.paused'), 'pending' => __('status.pending')];

    // Texts and labels for resources/js/nexus.js, in the active language. The
    // topology itself holds codes only (one cache entry for every language).
    $config = [
        'urls' => [
            'graph' => route('nexus.graph'),
            'incidents' => route('incidents.index'),
            'serversAdd' => Route::has('servers.add') ? route('servers.add') : null,
            'domainsCreate' => Route::has('domains.create') ? route('domains.create') : null,
        ],
        'texts' => [...__('nexus.js'), 'stats' => __('nexus.stats')],
        'labels' => [
            'status' => [
                'common' => __('status'),
                'server' => __('labels.server_status'),
                'domain' => __('labels.domain_status'),
                'service' => [...__('labels.service_status'), 'paused' => __('status.disabled')],
                'page' => $pageStatus,
                'page_group' => $pageStatus,
            ],
            'severity' => __('labels.severity'),
            'environment' => __('labels.server_environment'),
            'service_kind' => __('labels.service_kind'),
            'checks' => [
                'http' => ['title' => __('domains.show.http_title'), 'values' => ['up' => __('status.up'), 'down' => __('status.down'), 'unknown' => __('status.unknown')]],
                'ssl' => ['title' => __('domains.show.ssl_title'), 'values' => __('domains.show.ssl')],
                'dns' => ['title' => __('domains.show.dns_title'), 'values' => ['ok' => __('domains.show.dns_ok'), 'failed' => __('domains.show.dns_failed'), 'unknown' => __('domains.show.dns_unknown')]],
                'expiry' => ['title' => __('domains.show.expiry_title'), 'values' => __('domains.show.expiry')],
            ],
        ],
    ];
@endphp

<x-layouts.app :title="__('nexus.title')">
    <x-slot:head>
        @vite('resources/js/nexus.js')
    </x-slot:head>

    <x-slot:breadcrumb>
        <span>›</span> {{ __('nexus.title') }}
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ __('nexus.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('nexus.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        <div class="ss-auto-refresh" data-nexus-live data-state="loading" role="status" aria-live="polite" hidden>
            <span class="ss-dot ss-dot-accent ss-nexus-live-dot" aria-hidden="true"></span>
            <span data-nexus-live-text></span>
            <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-nexus-pause aria-pressed="false"></button>
            <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-nexus-refresh>{{ __('nexus.refresh') }}</button>
        </div>
    </x-slot:actions>

    <div class="grid gap-5" data-nexus>
        <script type="application/json" id="nexus-config">@json($config)</script>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-nexus"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('nexus.stats.resources') }}</div>
                    <div class="ss-stat-value" data-kpi="nodes">{{ $counts['nodes'] }}</div>
                    <div class="ss-stat-caption" data-kpi="nodes-caption">{{ __('nexus.stats.resources_caption', ['servers' => $byType['server'], 'services' => $byType['service'], 'domains' => $byType['domain']]) }}</div>
                </div>
            </div>
            <div @class(['ss-stat', 'is-bad' => $critical > 0, 'is-warn' => $critical === 0 && $warning > 0, 'is-ok' => $critical + $warning === 0]) data-kpi-tile="problems">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('nexus.stats.problems') }}</div>
                    <div class="ss-stat-value" data-kpi="problems">{{ $critical + $warning }}</div>
                    <div class="ss-stat-caption" data-kpi="problems-caption">{{ __('nexus.stats.problems_caption', ['critical' => $critical, 'warning' => $warning]) }}</div>
                </div>
            </div>
            <a @class(['ss-stat', 'is-bad' => $counts['active_incidents'] > 0, 'is-ok' => $counts['active_incidents'] === 0]) href="{{ route('incidents.index') }}" data-kpi-tile="incidents">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('nexus.stats.incidents') }}</div>
                    <div class="ss-stat-value" data-kpi="incidents">{{ $counts['active_incidents'] }}</div>
                    <div class="ss-stat-caption" data-kpi="incidents-caption">{{ __('nexus.stats.incidents_caption', ['count' => $affected]) }}</div>
                </div>
            </a>
            <a class="ss-stat" href="{{ route('dependencies.index') }}">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-link"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('nexus.stats.edges') }}</div>
                    <div class="ss-stat-value" data-kpi="edges">{{ $counts['edges'] }}</div>
                    <div class="ss-stat-caption" data-kpi="edges-caption">{{ __('nexus.stats.edges_caption', ['confirmed' => $confirmed, 'structural' => $counts['edges'] - $confirmed]) }}</div>
                </div>
            </a>
        </div>

        <div class="grid gap-3" data-nexus-notices @if ($meta['truncated'] === []) hidden @endif>
            @if ($meta['truncated'] !== [])
                <div class="ss-alert ss-alert-warning" role="status"><svg class="ico"><use href="#i-alert"/></svg><div><strong>{{ __('nexus.truncated.title') }}</strong> {{ __('nexus.truncated.text') }}</div></div>
            @endif
        </div>

        <noscript>
            <div class="ss-alert ss-alert-info"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('nexus.noscript') }}</div></div>
        </noscript>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="ss-panel !p-0 overflow-hidden" aria-label="{{ __('nexus.map_label') }}">
                <div class="flex flex-wrap items-center gap-2 border-b border-line p-3">
                    <div class="relative min-w-[12rem] flex-1 sm:max-w-xs">
                        <label class="sr-only" for="nexus-search">{{ __('nexus.js.toolbar.search_label') }}</label>
                        <svg class="ico pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"><use href="#i-search"/></svg>
                        <input id="nexus-search" type="search" class="ss-input !py-1.5 !pl-9 !text-sm" placeholder="{{ __('nexus.js.toolbar.search_placeholder') }}" autocomplete="off" data-nexus-search>
                    </div>
                    <button type="button" class="ss-nexus-filter" aria-pressed="false" data-nexus-incidents title="{{ __('nexus.js.toolbar.incident_view_hint') }}">
                        <svg class="ico"><use href="#i-siren"/></svg>{{ __('nexus.js.toolbar.incident_view') }}<span class="ss-count">0</span>
                    </button>
                    <div class="ml-auto flex flex-wrap items-center gap-1.5">
                        <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-nexus-reset>{{ __('nexus.js.toolbar.reset') }}</button>
                        <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm" data-nexus-fit><svg class="ico"><use href="#i-search"/></svg>{{ __('nexus.js.toolbar.fit') }}</button>
                        <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" aria-pressed="true" data-nexus-flows title="{{ __('nexus.js.toolbar.flows_hint') }}" hidden>{{ __('nexus.js.toolbar.flows') }}</button>
                        <a href="{{ route('nexus.globe') }}" class="ss-btn ss-btn-secondary ss-btn-sm"><svg class="ico"><use href="#i-globe"/></svg>{{ __('globe.title') }}</a>
                        <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm" aria-pressed="false" data-nexus-3d title="{{ __('nexus.js.toolbar.view_3d_hint') }}"><svg class="ico"><use href="#i-nexus"/></svg>{{ __('nexus.js.toolbar.view_3d') }}</button>
                        <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm" aria-pressed="false" data-nexus-view><svg class="ico"><use href="#i-list"/></svg>{{ __('nexus.js.toolbar.list') }}</button>
                    </div>
                </div>

                <div class="grid gap-2 border-b border-line p-3">
                    <div class="grid items-center gap-2 sm:grid-cols-[6.5rem_minmax(0,1fr)]" role="group" aria-label="{{ __('nexus.js.toolbar.type') }}">
                        <span class="ss-nexus-heading !m-0" aria-hidden="true">{{ __('nexus.js.toolbar.type') }}</span>
                        <div class="flex flex-wrap gap-1.5" data-nexus-types></div>
                    </div>
                    <div class="grid items-center gap-2 sm:grid-cols-[6.5rem_minmax(0,1fr)]" role="group" aria-label="{{ __('nexus.js.toolbar.health') }}">
                        <span class="ss-nexus-heading !m-0" aria-hidden="true">{{ __('nexus.js.toolbar.health') }}</span>
                        <div class="flex flex-wrap gap-1.5" data-nexus-health></div>
                    </div>
                </div>

                <div class="ss-nexus-stage">
                    {{-- Cytoscape makes its container position:relative (unlayered CSS beats the
                         utilities layer), so it gets an inner element of its own. --}}
                    <div class="absolute inset-0" data-nexus-canvas-wrap>
                        <div class="h-full w-full" data-nexus-canvas role="img" aria-label="{{ __('nexus.js.toolbar.canvas_label') }}"></div>
                    </div>
                    {{-- 3D view: three.js draws into this element once it is opened. --}}
                    <div class="ss-nexus-3d absolute inset-0" data-nexus-3d-stage role="img" aria-label="{{ __('nexus.js.toolbar.canvas3d_label') }}" hidden></div>
                    <div class="absolute inset-0 overflow-auto bg-surface p-3" data-nexus-list hidden></div>
                    <div class="ss-nexus-tooltip" data-nexus-tooltip hidden></div>
                    <span class="ss-chip pointer-events-none absolute bottom-3 left-3" data-nexus-visible aria-live="polite"></span>
                    <div class="ss-nexus-overlay" data-nexus-overlay hidden></div>
                </div>
            </section>

            <aside class="ss-panel xl:sticky xl:top-20 xl:max-h-[calc(100vh-6rem)] xl:overflow-y-auto" data-nexus-panel aria-label="{{ __('nexus.details_label') }}"></aside>
        </div>
    </div>
</x-layouts.app>
