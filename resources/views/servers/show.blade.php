@php
    use App\Models\Server;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $sections = array_filter([
        'overview' => true,
        'metrics' => true,
        'system' => true,
        'services' => true,
        'alerts' => true,
        'agent-updates' => true,
        'manage' => $isAdmin,
    ]);
    $sectionLabels = ['overview' => 'overview', 'metrics' => 'metrics', 'system' => 'system', 'services' => 'services', 'alerts' => 'alerts', 'agent-updates' => 'updates', 'manage' => 'manage'];
@endphp

<x-layouts.app :title="$server->name">
    <x-slot:head>
        @vite('resources/js/charts.js')
    </x-slot:head>

    <x-slot:breadcrumb>
        <span>›</span>
        <a href="{{ route('servers.index') }}">{{ __('servers.title') }}</a>
        <span>›</span>
        <span>{{ $server->name }}</span>
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ $server->name }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('servers.show.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.index') }}" class="ss-btn ss-btn-ghost">
            <svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('servers.back_to_overview') }}
        </a>
        @can('admin')
            @unless ($server->trashed())
                <a href="{{ route('servers.edit', $server) }}" class="ss-btn ss-btn-secondary">
                    <svg class="ico"><use href="#i-edit"/></svg> {{ __('servers.edit_button') }}
                </a>
                @if ($server->isInMaintenance() || $server->hasScheduledMaintenance())
                    <form method="POST" action="{{ route('servers.maintenance.end', $server) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ss-btn ss-btn-secondary">
                            <svg class="ico"><use href="#i-wrench"/></svg>
                            {{ $server->isInMaintenance() ? __('servers.show.end_maintenance') : __('servers.show.cancel_maintenance') }}
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('servers.maintenance.start', $server) }}" class="flex flex-wrap items-start gap-2" data-confirm="{{ __('servers.show.maintenance_confirm', ['name' => $server->name]) }}">
                        @csrf
                        <label class="sr-only" for="duration_minutes">{{ __('servers.show.maintenance_duration') }}</label>
                        <select id="duration_minutes" name="duration_minutes" class="ss-input !w-auto !py-2 !text-sm">
                            @foreach (['30', '60', '240', '1440', 'custom'] as $minutes)
                                <option value="{{ $minutes }}" @selected(old('duration_minutes', '60') === $minutes)>{{ __('servers.show.durations.'.$minutes) }}</option>
                            @endforeach
                        </select>
                        <label class="sr-only" for="ends_at">{{ __('servers.show.maintenance_custom_end') }}</label>
                        <input id="ends_at" name="ends_at" type="datetime-local" class="ss-input !w-auto !py-2 !text-sm" value="{{ old('ends_at') }}" title="{{ __('servers.show.maintenance_custom_end') }} ({{ config('serverscope.display_timezone') }})">
                        <button type="submit" class="ss-btn ss-btn-secondary">
                            <svg class="ico"><use href="#i-wrench"/></svg> {{ __('servers.show.start_maintenance') }}
                        </button>
                        @error('ends_at')<p class="m-0 w-full text-xs text-bad" role="alert">{{ $message }}</p>@enderror
                        <p class="m-0 w-full max-w-md text-xs text-muted">{{ __('servers.show.maintenance_effect') }}</p>
                    </form>
                @endif
            @endunless
        @endcan
    </x-slot:actions>

    <div class="grid gap-4">
        @if (session('agent_token'))
            <x-secret-token :token="session('agent_token')" :title="__('servers.show.agent_token_for', ['name' => $server->name])">
                <p class="mt-2 mb-0 text-sm">
                    {!! __('servers.show.agent_token_help', ['env' => '<code>SERVERSCOPE_TOKEN</code>', 'file' => '<code>agent/.env</code>']) !!}
                    @if (config('serverscope.docs_url'))
                        <a class="text-accent" href="{{ config('serverscope.docs_url') }}/server-monitoring" target="_blank" rel="noopener">{{ __('servers.show.install_guide') }}</a>
                    @endif
                </p>
            </x-secret-token>
        @endif

        @if ($server->trashed())
            <div class="ss-alert ss-alert-warning" role="status">
                <svg class="ico"><use href="#i-trash"/></svg>
                <div>
                    <strong>{{ __('servers.show.deleted_on', ['date' => Time::dateTime($server->deleted_at)]) }}</strong>
                    {{ __('servers.show.deleted_text') }}
                    @can('admin') <a class="text-accent" href="#manage">{{ __('servers.show.deleted_manage') }}</a> @endcan
                </div>
            </div>
        @elseif ($server->isInMaintenance())
            <div class="ss-alert ss-alert-info" role="status">
                <svg class="ico"><use href="#i-wrench"/></svg>
                <div>
                    <strong>{{ __('servers.show.maintenance_active') }}</strong>
                    {{ $server->maintenance_ends_at ? __('servers.show.maintenance_until', ['date' => Time::dateTime($server->maintenance_ends_at)]) : __('servers.show.maintenance_open') }}.
                    {{ __('servers.show.maintenance_text') }}
                    @if ($server->maintenance_reason) {{ __('servers.show.maintenance_reason', ['reason' => $server->maintenance_reason]) }} @endif
                </div>
            </div>
        @elseif ($server->hasScheduledMaintenance())
            <div class="ss-alert ss-alert-info" role="status">
                <svg class="ico"><use href="#i-calendar"/></svg>
                <div>
                    {{ __('servers.show.maintenance_planned', ['start' => Time::dateTime($server->maintenance_starts_at)]) }}
                    {{ $server->maintenance_ends_at ? __('servers.show.maintenance_until', ['date' => Time::dateTime($server->maintenance_ends_at)]) : '' }}.
                    @if ($server->maintenance_reason) {{ __('servers.show.maintenance_reason', ['reason' => $server->maintenance_reason]) }} @endif
                </div>
            </div>
        @endif

        {{-- Head: who it is and how it is doing --}}
        @php
            // Same threshold as the agent heartbeat (Server::OFFLINE_AFTER_MINUTES).
            // Stale metrics are reported as stale, not as proof that the host is down.
            $staleMetrics = ! $server->trashed() && ! $server->isInMaintenance() && $latestSample && $latestSample->collected_at->lt(now()->subMinutes(\App\Models\Server::OFFLINE_AFTER_MINUTES))
                ? __('health.freshness.stale_metrics', ['age' => \App\Support\Time::relative($latestSample->collected_at)]) : null;
        @endphp
        <x-freshness class="mb-4" :rows="[
            [__('health.freshness.last_metrics'), $latestSample?->collected_at],
            [__('servers.show.last_contact'), $server->last_seen_at],
        ]" :stale="$staleMetrics" />

        <section class="ss-panel flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between" id="overview">
            <div class="flex min-w-0 items-center gap-4">
                <span class="ss-stat-icon !h-12 !w-12 !basis-12 !text-2xl"><svg class="ico"><use href="#i-server"/></svg></span>
                <div class="min-w-0">
                    <h2 class="m-0 text-lg font-semibold [overflow-wrap:anywhere]">{{ $server->name }}</h2>
                    <div class="text-sm text-muted [overflow-wrap:anywhere]">{{ $server->hostname }}@if ($server->ip_address) · <span class="font-mono text-xs">{{ $server->ip_address }}</span>@endif</div>
                    <div class="mt-1 text-xs text-muted">
                        {{ $server->operating_system ?? __('servers.show.os_unknown') }}
                        · {{ __('servers.show.agent', ['version' => $server->agent_version ?? __('servers.unknown')]) }}
                        · <span title="{{ Time::format($server->last_seen_at, 'd-m-Y H:i:s') }}">{{ __('servers.show.last_seen', ['time' => Time::relative($server->last_seen_at)]) }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="ss-chip">{{ Label::of('server_environment', $server->environment, Server::ENVIRONMENTS) }}</span>
                @if ($server->trashed())
                    <x-status status="closed" :label="__('servers.deleted')" />
                @else
                    <x-health :state="\App\Support\Health::server($server, \App\Support\Health::serverIncidentSeverities([$server->id])[$server->id] ?? null)" />
                @endif
            </div>
        </section>

        <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="{{ __('servers.show.stats_label') }}">
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-cpu"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('servers.show.cpu') }}</div>
                    <div class="ss-stat-value">{{ $latestSample?->cpu_percent !== null ? Format::percent($latestSample->cpu_percent, 1) : '—' }}</div>
                    <div class="ss-stat-caption">{{ __('servers.show.cpu_caption') }}</div>
                </div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-ram"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('servers.show.ram') }}</div>
                    <div class="ss-stat-value">{{ $memoryPercent !== null ? Format::percent($memoryPercent, 1) : '—' }}</div>
                    <div class="ss-stat-caption">{{ $memoryTotal > 0 ? __('servers.show.ram_caption', ['used' => $formatBytes($memoryUsed), 'total' => $formatBytes($memoryTotal)]) : __('servers.show.ram_none') }}</div>
                </div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-bars"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('servers.show.load') }}</div>
                    <div class="ss-stat-value">{{ $latestSample?->load_1 !== null ? Format::number($latestSample->load_1, 2) : '—' }}</div>
                    <div class="ss-stat-caption">{{ __('servers.show.load_caption') }}</div>
                </div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('servers.show.uptime') }}</div>
                    <div class="ss-stat-value">{{ $formatUptime($latestSample?->uptime_seconds) }}</div>
                    <div class="ss-stat-caption">{{ __('servers.show.uptime_caption') }}</div>
                </div>
            </article>
        </section>
    </div>

    {{-- Section navigation: anchors, so it works without JavaScript; app.js marks the current section. --}}
    <nav class="ss-section-nav" aria-label="{{ __('servers.show.sections_label') }}" data-section-nav>
        @foreach ($sections as $id => $show)
            <a href="#{{ $id }}">{{ __('servers.show.sections.'.$sectionLabels[$id]) }}</a>
        @endforeach
    </nav>

    <div class="grid gap-10">
        <div class="ss-section">
            @include('servers._health', ['part' => 'diagnosis'])
        </div>

        {{-- Performance --}}
        <section class="ss-section grid gap-4" id="metrics" aria-labelledby="metrics-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="metrics-title" class="ss-section-title">{{ __('servers.show.metrics_title') }}</h2>
                    <p class="ss-panel-sub">{{ __('servers.show.metrics_text') }}</p>
                </div>
                <form method="GET" action="{{ route('servers.show', $server) }}" class="ss-segmented" aria-label="{{ __('servers.show.range_label') }}">
                    @foreach ($rangeOptions as $key => $option)
                        <button type="submit" name="range" value="{{ $key }}" aria-pressed="{{ $range === $key ? 'true' : 'false' }}" title="{{ $option['label'] }}">{{ __('servers.show.range_short.'.$key) }}</button>
                    @endforeach
                </form>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <section class="ss-panel">
                    <div class="ss-panel-head">
                        <div><h3 class="ss-panel-title">{{ __('servers.show.cpu') }}</h3><p class="ss-panel-sub">{{ __('servers.show.cpu_text') }}</p></div>
                        <span class="ss-chip">{{ __('servers.show.points', ['count' => $sampleCount]) }}</span>
                    </div>
                    @if ($samples->contains(fn ($sample) => $sample->cpu_percent !== null))
                        <div class="ss-chart"><canvas id="cpu-chart" aria-label="{{ __('servers.show.cpu_chart') }}" role="img"></canvas></div>
                    @else
                        <x-empty-state icon="i-cpu" :title="__('servers.show.cpu_empty')">{{ __('servers.show.cpu_empty_text') }}</x-empty-state>
                    @endif
                </section>

                <section class="ss-panel">
                    <div class="ss-panel-head">
                        <div><h3 class="ss-panel-title">{{ __('servers.show.memory') }}</h3><p class="ss-panel-sub">{{ __('servers.show.memory_text') }}</p></div>
                    </div>
                    @if ($samples->contains(fn ($sample) => $sample->memory_total_bytes > 0 && $sample->memory_used_bytes !== null && $sample->memory_used_bytes <= $sample->memory_total_bytes))
                        <div class="ss-chart"><canvas id="memory-chart" aria-label="{{ __('servers.show.memory_chart') }}" role="img"></canvas></div>
                    @else
                        <x-empty-state icon="i-ram" :title="__('servers.show.memory_empty')">{{ __('servers.show.memory_empty_text') }}</x-empty-state>
                    @endif
                </section>

                <section class="ss-panel">
                    <div class="ss-panel-head">
                        <div><h3 class="ss-panel-title">{{ __('servers.show.load_title') }}</h3><p class="ss-panel-sub">{{ __('servers.show.load_text') }}</p></div>
                        <span class="flex flex-wrap gap-3 text-xs text-muted">
                            <span class="inline-flex items-center gap-1.5"><i class="ss-legend-dot"></i>{{ __('servers.show.load_1') }}</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ss-legend-dot is-2"></i>{{ __('servers.show.load_5') }}</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ss-legend-dot is-3"></i>{{ __('servers.show.load_15') }}</span>
                        </span>
                    </div>
                    @if ($samples->contains(fn ($sample) => $sample->load_1 !== null || $sample->load_5 !== null || $sample->load_15 !== null))
                        <div class="ss-chart"><canvas id="load-chart" aria-label="{{ __('servers.show.load_chart') }}" role="img"></canvas></div>
                    @else
                        <x-empty-state icon="i-bars" :title="__('servers.show.load_empty')">{{ __('servers.show.load_empty_text') }}</x-empty-state>
                    @endif
                </section>

                <section class="ss-panel">
                    <div class="ss-panel-head">
                        <div><h3 class="ss-panel-title">{{ __('servers.show.network') }}</h3><p class="ss-panel-sub">{{ __('servers.show.network_text') }}</p></div>
                    </div>
                    @foreach ([['rx', 'i-down', 'inbound', 'received_total'], ['tx', 'i-up', 'outbound', 'sent_total']] as [$key, $icon, $label, $totalLabel])
                        <div class="flex items-center justify-between gap-4 border-b border-line py-3 last:border-0">
                            <div>
                                <div class="flex items-center gap-1.5 text-xs text-muted"><svg class="ico"><use href="#{{ $icon }}"/></svg> {{ __('servers.show.'.$label) }}</div>
                                <div class="mt-0.5 text-xl font-semibold tabular-nums">{{ $networkRates[$key] !== null ? $formatBytes((int) $networkRates[$key]).'/s' : '—' }}</div>
                                @if ($networkRates[$key] === null)
                                    <div class="text-xs text-muted">{{ __('servers.show.not_enough_points') }}</div>
                                @endif
                            </div>
                            <div class="text-right text-xs text-muted">
                                {{ __('servers.show.'.$totalLabel) }}
                                <strong class="mt-0.5 block text-sm font-semibold text-fg tabular-nums">{{ $network[$key] !== null ? $formatBytes($network[$key]) : '—' }}</strong>
                            </div>
                        </div>
                    @endforeach
                </section>
            </div>

            @include('servers._history')
        </section>

        {{-- Disk & system --}}
        <section class="ss-section grid gap-4 lg:grid-cols-2" id="system" aria-label="{{ __('servers.show.sections.system') }}">
            <section class="ss-panel">
                <div class="ss-panel-head">
                    <div><h2 class="ss-panel-title">{{ __('servers.show.disks_title') }}</h2><p class="ss-panel-sub">{{ __('servers.show.disks_text') }}</p></div>
                </div>
                @if ($disks->isEmpty())
                    <x-empty-state icon="i-disk" :title="__('servers.show.disks_empty')" />
                @else
                    <div class="grid gap-5">
                        @foreach ($disks as $disk)
                            @php $pct = $disk['percent'] !== null ? min(100, max(0, (float) $disk['percent'])) : null; @endphp
                            <div class="min-w-0">
                                <div class="mb-2 flex justify-between gap-3 text-sm">
                                    <strong class="font-mono text-xs [overflow-wrap:anywhere]">{{ $disk['path'] }}</strong>
                                    <span class="whitespace-nowrap font-semibold tabular-nums {{ $pct !== null && $pct >= 90 ? 'ss-text-bad' : ($pct !== null && $pct >= 75 ? 'ss-text-warn' : '') }}">{{ $pct !== null ? Format::percent($disk['percent'], 1) : __('servers.unknown_cap') }}</span>
                                </div>
                                <svg class="ss-bar {{ $pct !== null && $pct >= 90 ? 'is-bad' : ($pct !== null && $pct >= 75 ? 'is-warn' : '') }}" aria-hidden="true"><rect class="track" width="100%" height="100%"/><rect class="fill" width="{{ round($pct ?? 0, 1) }}%" height="100%" rx="3"/></svg>
                                <div class="mt-2 flex flex-wrap justify-between gap-x-4 gap-y-1 text-xs text-muted">
                                    <span>{{ __('servers.show.disk_used', ['value' => $formatBytes($disk['used_bytes'])]) }}</span>
                                    <span>{{ __('servers.show.disk_free', ['value' => $formatBytes($disk['available_bytes'])]) }}</span>
                                    <span>{{ __('servers.show.disk_total', ['value' => $formatBytes($disk['total_bytes'])]) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ss-panel">
                <div class="ss-panel-head">
                    <div><h2 class="ss-panel-title">{{ __('servers.show.system_title') }}</h2><p class="ss-panel-sub">{{ __('servers.show.system_text') }}</p></div>
                </div>
                <dl class="ss-dl">
                    <dt><svg class="ico"><use href="#i-server"/></svg> {{ __('servers.show.name') }}</dt><dd>{{ $server->name }}</dd>
                    <dt><svg class="ico"><use href="#i-globe"/></svg> {{ __('servers.show.hostname') }}</dt><dd>{{ $server->hostname }}</dd>
                    <dt><svg class="ico"><use href="#i-swap"/></svg> {{ __('servers.show.ip') }}</dt><dd class="font-mono text-xs">{{ $server->ip_address ?? __('servers.show.ip_none') }}</dd>
                    <dt><svg class="ico"><use href="#i-os"/></svg> {{ __('servers.show.os') }}</dt><dd>{{ $server->operating_system ?? __('servers.unknown_cap') }}</dd>
                    <dt><svg class="ico"><use href="#i-tag"/></svg> {{ __('servers.show.agent_version') }}</dt><dd>{{ $server->agent_version ?? __('servers.unknown_cap') }}</dd>
                    @if ($server->cpu_count)
                        <dt><svg class="ico"><use href="#i-cpu"/></svg> {{ __('servers.show.cpu_count') }}</dt><dd>{{ $server->cpu_count }}</dd>
                    @endif
                    <dt><svg class="ico"><use href="#i-clock"/></svg> {{ __('servers.show.last_contact') }}</dt><dd title="{{ Time::relative($server->last_seen_at) }}">{{ $server->last_seen_at ? Time::format($server->last_seen_at, 'd-m-Y H:i:s') : __('servers.never') }}</dd>
                    <dt><svg class="ico"><use href="#i-metrics"/></svg> {{ __('servers.show.last_sample') }}</dt><dd>{{ $latestSample ? Time::format($latestSample->collected_at, 'd-m-Y H:i:s') : __('servers.show.no_samples') }}</dd>
                    <dt><svg class="ico"><use href="#i-bars"/></svg> {{ __('servers.show.samples_in_range') }}</dt><dd>{{ $sampleCount }}</dd>
                </dl>
            </section>
        </section>

        {{-- Services --}}
        <div class="ss-section" id="services">
            @include('servers._health', ['part' => 'services'])
        </div>

        {{-- Alerts & incidents --}}
        <section class="ss-section grid gap-4 lg:grid-cols-2" id="alerts" aria-label="{{ __('servers.show.sections.alerts') }}">
            <section class="ss-panel">
                <div class="ss-panel-head">
                    <div><h2 class="ss-panel-title">{{ __('servers.show.alerts_title') }}</h2><p class="ss-panel-sub">{{ __('servers.show.alerts_text') }}</p></div>
                    <a href="{{ route('alerts.index', ['status' => 'all']) }}" class="ss-btn ss-btn-secondary ss-btn-sm">{{ __('servers.show.all_alerts') }}</a>
                </div>
                @if ($recentAlerts->isEmpty())
                    <x-empty-state icon="i-check" :title="__('servers.show.no_alerts')">{{ __('servers.show.no_alerts_text') }}</x-empty-state>
                @else
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('servers.show.col_rule') }}</th><th scope="col">{{ __('servers.show.col_status') }}</th><th scope="col">{{ __('servers.show.col_triggered') }}</th></tr></thead>
                            <tbody>
                                @foreach ($recentAlerts as $alert)
                                    <tr>
                                        <td class="is-primary">
                                            {{ $alert->alertRule?->name ?? __('servers.show.deleted_rule') }}
                                            <span class="block text-xs text-muted">{{ Label::of('alert_metric', $alert->metric, \App\Models\AlertRule::METRIC_LABELS) }}@if ($alert->dimension) · {{ $alert->dimension }}@endif</span>
                                        </td>
                                        <td data-label="{{ __('servers.show.col_status') }}"><x-status :status="$alert->status === 'active' ? 'critical' : ($alert->status === 'resolved' ? 'resolved' : 'disabled')" :label="Lang::has('servers.show.alert_status.'.$alert->status) ? __('servers.show.alert_status.'.$alert->status) : $alert->status" /></td>
                                        <td data-label="{{ __('servers.show.col_triggered') }}" class="whitespace-nowrap">{{ Time::dateTime($alert->triggered_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <x-dependencies :resource="$server" />

            @if ($deployments->isNotEmpty())
                <section class="ss-panel" data-testid="server-deployments">
                    <div class="ss-panel-head">
                        <div><h2 class="ss-panel-title">{{ __('deployments.title') }}</h2></div>
                        <a href="{{ route('deployments.index') }}" class="ss-btn ss-btn-secondary ss-btn-sm">{{ __('deployments.title') }}</a>
                    </div>
                    @foreach ($deployments as $deployment)
                        <p class="m-0 mt-2 text-sm"><a href="{{ route('deployments.show', $deployment) }}">{{ $deployment->label() }}</a>
                            <span class="block text-xs text-muted">{{ $deployment->environment }} · {{ Time::dateTime($deployment->deployed_at) }}</span></p>
                    @endforeach
                </section>
            @endif
        </section>

        {{-- Agent & updates --}}
        <div class="ss-section">
            @include('servers._agent-updates')
        </div>

        {{-- Manage: tokens and actions that are hard to undo --}}
        @can('admin')
            <section class="ss-section ss-panel ss-panel-danger" id="manage" aria-labelledby="manage-title">
                <div class="ss-panel-head">
                    <div><h2 id="manage-title" class="ss-panel-title">{{ __('servers.show.manage_title') }}</h2><p class="ss-panel-sub">{{ __('servers.show.manage_text') }}</p></div>
                </div>

                <div id="credentials">
                    <h3 class="m-0 text-sm font-semibold">{{ __('servers.credentials.title') }}</h3>
                    <p class="ss-panel-sub mb-3">{{ __('servers.credentials.text') }}</p>

                    @if ($credentials->isEmpty())
                        <x-empty-state icon="i-key" :title="__('servers.credentials.empty')">{{ __('servers.credentials.empty_text') }}</x-empty-state>
                    @else
                        <div class="ss-table-wrap">
                            <table class="ss-table ss-table-cards">
                                <thead><tr><th scope="col">{{ __('servers.credentials.col_name') }}</th><th scope="col">{{ __('servers.credentials.col_status') }}</th><th scope="col">{{ __('servers.credentials.col_last_used') }}</th><th scope="col"><span class="sr-only">{{ __('servers.index.col_actions') }}</span></th></tr></thead>
                                <tbody>
                                    @foreach ($credentials as $credential)
                                        <tr>
                                            <td class="is-primary">
                                                {{ $credential->name }}
                                                <span class="block text-xs text-muted">{{ __('servers.credentials.created', ['date' => Time::dateTime($credential->created_at)]) }}</span>
                                            </td>
                                            <td data-label="{{ __('servers.credentials.col_status') }}">
                                                @if ($credential->revoked_at)
                                                    <x-status status="closed" :label="__('servers.credentials.revoked')" title="{{ Time::dateTime($credential->revoked_at) }}" />
                                                @elseif (! $credential->isActive())
                                                    <x-status status="expired" />
                                                @else
                                                    <x-status status="valid" :label="__('servers.credentials.active')" />
                                                @endif
                                            </td>
                                            <td data-label="{{ __('servers.credentials.col_last_used') }}" class="whitespace-nowrap">{{ Time::relative($credential->last_used_at) }}</td>
                                            <td class="is-actions">
                                                @if ($credential->revoked_at === null && ! $server->trashed())
                                                    <form method="POST" action="{{ route('servers.credentials.destroy', [$server, $credential]) }}" class="flex justify-end"
                                                          data-confirm="{{ __('servers.credentials.revoke_confirm', ['name' => $credential->name]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="ss-btn ss-btn-danger ss-btn-sm">{{ __('servers.credentials.revoke') }}</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @unless ($server->trashed())
                        <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4">
                            <form method="POST" action="{{ route('servers.credentials.store', $server) }}" class="flex min-w-0 flex-1 flex-wrap gap-2">
                                @csrf
                                <label class="sr-only" for="credential-name">{{ __('servers.credentials.new_name') }}</label>
                                <input id="credential-name" name="name" class="ss-input !w-auto min-w-0 flex-1 !py-2" maxlength="100" required placeholder="{{ __('servers.credentials.new_placeholder') }}">
                                <button type="submit" class="ss-btn ss-btn-secondary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('servers.credentials.extra') }}</button>
                            </form>
                            <form method="POST" action="{{ route('servers.credentials.rotate', $server) }}"
                                  data-confirm="{{ __('servers.credentials.rotate_confirm') }}">
                                @csrf
                                <button type="submit" class="ss-btn ss-btn-danger"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('servers.credentials.rotate') }}</button>
                            </form>
                        </div>
                    @endunless
                </div>

                @if ($server->trashed())
                    <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-line pt-4">
                        <form method="POST" action="{{ route('servers.restore', $server) }}">
                            @csrf
                            <button type="submit" class="ss-btn ss-btn-secondary">{{ __('servers.show.restore') }}</button>
                        </form>
                        <form method="POST" action="{{ route('servers.force-destroy', $server) }}" class="flex flex-wrap gap-2"
                              data-confirm="{{ __('servers.show.force_delete_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <label class="sr-only" for="confirm_name">{{ __('servers.show.confirm_name') }}</label>
                            <input id="confirm_name" name="confirm_name" class="ss-input !w-auto !py-2" placeholder="{{ __('servers.show.confirm_name_placeholder', ['name' => $server->name]) }}" required>
                            <button type="submit" class="ss-btn ss-btn-danger">{{ __('servers.show.force_delete') }}</button>
                        </form>
                    </div>
                @endif
            </section>
        @endcan
    </div>

    <script type="application/json" id="chart-data">@json($chartData)</script>
</x-layouts.app>
