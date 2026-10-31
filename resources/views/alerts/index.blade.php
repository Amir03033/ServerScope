@php
    use App\Models\AlertRule;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $operatorLabels = ['>=' => '≥', '>' => '>', '<=' => '≤', '<' => '<'];
    $tabs = ['active' => $activeCount, 'resolved' => $resolvedCount, 'disabled' => $disabledCount, 'all' => $totalCount];
@endphp

<x-layouts.app :title="__('alerts.title')">
    <x-slot:breadcrumb><span>›</span> {{ __('alerts.title') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('alerts.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('alerts.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('dashboard') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('alerts.dashboard') }}</a>
    </x-slot:actions>

    <div class="grid gap-4">
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="{{ __('alerts.stats_label') }}">
            <article @class(['ss-stat', 'is-bad' => $activeCount > 0])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('alerts.active_count') }}</div><div class="ss-stat-value">{{ Format::number($activeCount) }}</div></div>
            </article>
            <article class="ss-stat is-ok">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('alerts.resolved_count') }}</div><div class="ss-stat-value">{{ Format::number($resolvedCount) }}</div></div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('alerts.total_count') }}</div><div class="ss-stat-value">{{ Format::number($totalCount) }}</div></div>
            </article>
        </section>

        <section class="ss-panel" aria-labelledby="history-title">
            <div class="ss-panel-head">
                <div><h2 id="history-title" class="ss-panel-title">{{ __('alerts.history_title') }}</h2><p class="ss-panel-sub">{{ __('alerts.history_text') }}</p></div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-muted">{{ __('alerts.results', ['count' => $alerts->total()]) }}</span>
                    @can('admin')
                        @if ($resolvedCount + $disabledCount > 0)
                            <form method="POST" action="{{ route('alerts.clear') }}" data-confirm="{{ __('alerts.clear_confirm') }}">
                                @csrf
                                @method('DELETE')
                                <button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit"><svg class="ico"><use href="#i-trash"/></svg> {{ __('alerts.clear') }}</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>

            <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('alerts.filter_label') }}">
                <div class="ss-segmented">
                    @foreach ($tabs as $key => $count)
                        <a href="{{ route('alerts.index', ['status' => $key]) }}" @if ($status === $key) aria-current="page" @endif>{{ __('alerts.tabs.'.$key) }} <span class="ss-count">{{ $count }}</span></a>
                    @endforeach
                </div>
            </nav>

            @if ($alerts->isEmpty())
                @php $empty = in_array($status, ['active', 'resolved', 'disabled'], true) ? $status : 'all'; @endphp
                <x-empty-state :icon="$status === 'active' ? 'i-check' : 'i-clock'" :title="__('alerts.empty.'.$empty.'_title')">{{ __('alerts.empty.'.$empty.'_text') }}</x-empty-state>
            @else
                <div class="grid gap-3">
                    @foreach ($alerts as $alert)
                        @php
                            $isActive = $alert->status === 'active';
                            $isDisabled = $alert->status === 'disabled';
                            $unit = AlertRule::metricUnit($alert->metric);
                            $operator = $operatorLabels[$alert->alertRule?->operator ?? ''] ?? ($alert->alertRule?->operator ?? '≥');
                            $state = $isActive ? 'active' : ($isDisabled ? 'disabled' : 'resolved');
                        @endphp
                        <article @class(['ss-diagnosis ss-panel !p-4', 'is-bad' => $isActive, 'is-warn' => $isDisabled])>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="ss-stat-icon"><svg class="ico"><use href="#{{ $isActive ? 'i-alert' : 'i-check' }}"/></svg></span>
                                    <div class="min-w-0">
                                        <h3 class="m-0 text-[15px] font-semibold [overflow-wrap:anywhere]">{{ $alert->alertRule?->name ?? __('alerts.rule_deleted') }}</h3>
                                        @if ($alert->server)
                                            <a class="text-xs text-muted no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('servers.show', $alert->server) }}">{{ $alert->server->name }} · {{ $alert->server->hostname }} →</a>
                                        @else
                                            <span class="text-xs text-muted">{{ __('alerts.server_gone') }}</span>
                                        @endif
                                    </div>
                                </div>
                                <x-status :status="['active' => 'critical', 'disabled' => 'warning', 'resolved' => 'resolved'][$state]" :label="__('alerts.status.'.$state)" />
                            </div>

                            <dl class="mt-4 grid gap-3 border-t border-line pt-3 sm:grid-cols-3">
                                <div>
                                    <dt class="text-xs text-muted">{{ __('alerts.metric') }}</dt>
                                    <dd class="m-0 font-semibold">{{ Label::of('alert_metric', $alert->metric, AlertRule::METRIC_LABELS) }}@if ($alert->metric === 'disk_percent' && $alert->dimension)<span class="block text-xs font-normal text-muted [overflow-wrap:anywhere]">{{ __('alerts.mountpoint', ['mount' => $alert->dimension]) }}</span>@endif</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted">{{ __('alerts.observed') }}</dt>
                                    <dd @class(['m-0 font-semibold tabular-nums', 'ss-text-bad' => $isActive, 'ss-text-ok' => ! $isActive && ! $isDisabled])>{{ Format::number($alert->observed_value, 1) }}{{ $unit }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted">{{ __('alerts.threshold') }}</dt>
                                    <dd class="m-0 font-semibold tabular-nums">{{ $operator }} {{ Format::number($alert->threshold, 1) }}{{ $unit }}</dd>
                                </div>
                            </dl>

                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-muted">
                                <span class="inline-flex items-center gap-1.5"><svg class="ico"><use href="#i-play"/></svg> {{ __('alerts.triggered') }} {{ $alert->triggered_at ? Time::dateTimeSeconds($alert->triggered_at) : __('alerts.unknown') }}</span>
                                @if ($alert->incident_id)
                                    <span class="inline-flex items-center gap-1.5"><svg class="ico"><use href="#i-siren"/></svg> <a class="text-accent" href="{{ route('incidents.show', $alert->incident_id) }}">{{ __('alerts.incident', ['id' => $alert->incident_id]) }}</a></span>
                                @endif
                                @if ($alert->resolved_at)
                                    <span class="inline-flex items-center gap-1.5"><svg class="ico"><use href="#i-flag"/></svg> {{ $isDisabled ? __('alerts.disabled_at') : __('alerts.resolved_at') }} {{ Time::dateTimeSeconds($alert->resolved_at) }}</span>
                                @endif
                            </div>

                            @can('admin')
                                @unless ($isActive)
                                    <form method="POST" action="{{ route('alerts.destroy', $alert) }}" class="mt-3" data-confirm="{{ __('alerts.delete_confirm') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit"><svg class="ico"><use href="#i-trash"/></svg> {{ __('alerts.delete') }}</button>
                                    </form>
                                @endunless
                            @endcan
                        </article>
                    @endforeach
                </div>

                <x-pagination :paginator="$alerts" />
            @endif
        </section>
    </div>
</x-layouts.app>
