@php
    use App\Models\AlertRule;
    use App\Support\Format;
    use App\Support\Label;

    $metricIcons = ['cpu_percent' => 'i-cpu', 'memory_percent' => 'i-ram', 'disk_percent' => 'i-disk'];
    $operatorLabels = ['>=' => '≥', '>' => '>', '<=' => '≤', '<' => '<'];
@endphp

<x-layouts.app :title="__('alert_rules.title')">
    <x-slot:breadcrumb><span>›</span> {{ __('alert_rules.title') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('alert_rules.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('alert_rules.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('dashboard') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('alert_rules.dashboard') }}</a>
        <a href="{{ route('alert-rules.create') }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('alert_rules.new') }}</a>
    </x-slot:actions>

    <div class="grid gap-4">
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="{{ __('alert_rules.stats_label') }}">
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('alert_rules.total') }}</div><div class="ss-stat-value">{{ Format::number($rules->total()) }}</div></div>
            </article>
            <article class="ss-stat is-ok">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('alert_rules.enabled_on_page') }}</div><div class="ss-stat-value">{{ Format::number($rules->getCollection()->where('is_enabled', true)->count()) }}</div></div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-pause"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('alert_rules.disabled_on_page') }}</div><div class="ss-stat-value">{{ Format::number($rules->getCollection()->where('is_enabled', false)->count()) }}</div></div>
            </article>
        </section>

        <section class="ss-panel" aria-labelledby="config-title">
            <div class="ss-panel-head">
                <div><h2 id="config-title" class="ss-panel-title">{{ __('alert_rules.config_title') }}</h2><p class="ss-panel-sub">{{ __('alert_rules.config_text') }}</p></div>
                <span class="text-xs text-muted">{{ __('alert_rules.rules_count', ['count' => $rules->total()]) }}</span>
            </div>

            @if ($rules->isEmpty())
                <x-empty-state icon="i-sliders" :title="__('alert_rules.empty_title')">
                    {{ __('alert_rules.empty_text') }}
                    <x-slot:actions><a href="{{ route('alert-rules.create') }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('alert_rules.create_first') }}</a></x-slot:actions>
                </x-empty-state>
            @else
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($rules as $rule)
                        @php
                            $metricLabel = Label::of('alert_metric', $rule->metric, AlertRule::METRIC_LABELS);
                            $unit = AlertRule::metricUnit($rule->metric);
                        @endphp
                        <article @class(['ss-diagnosis ss-panel !p-4', 'is-ok' => $rule->is_enabled])>
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="ss-stat-icon"><svg class="ico"><use href="#{{ $metricIcons[$rule->metric] ?? 'i-sliders' }}"/></svg></span>
                                    <div class="min-w-0">
                                        <h3 class="m-0 text-[15px] font-semibold [overflow-wrap:anywhere]">{{ $rule->name }}</h3>
                                        <div class="text-xs text-muted">{{ $metricLabel }} · {{ __('alert_rules.alerts_recorded', ['count' => $rule->alerts_count]) }}</div>
                                    </div>
                                </div>
                                <x-status :status="$rule->is_enabled ? 'enabled' : 'disabled'" :label="$rule->is_enabled ? __('alert_rules.enabled') : __('alert_rules.disabled')" />
                            </div>

                            <dl class="mt-4 grid grid-cols-3 gap-3 border-t border-line pt-3">
                                <div><dt class="text-xs text-muted">{{ __('alert_rules.condition') }}</dt><dd class="m-0 font-semibold tabular-nums">{{ $operatorLabels[$rule->operator] ?? $rule->operator }} {{ Format::number($rule->threshold, 1) }}{{ $unit }}</dd></div>
                                <div><dt class="text-xs text-muted">{{ __('alert_rules.duration') }}</dt><dd class="m-0 font-semibold">{{ __('alert_rules.duration_value', ['minutes' => $rule->duration_minutes]) }}</dd></div>
                                <div><dt class="text-xs text-muted">{{ __('alert_rules.metric') }}</dt><dd class="m-0 font-semibold">{{ $metricLabel }}</dd></div>
                            </dl>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="{{ route('alert-rules.edit', $rule) }}" class="ss-btn ss-btn-secondary ss-btn-sm"><svg class="ico"><use href="#i-edit"/></svg> {{ __('alert_rules.edit') }}</a>
                                <form method="POST" action="{{ route('alert-rules.destroy', $rule) }}" data-confirm="{{ __('alert_rules.delete_confirm') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit"><svg class="ico"><use href="#i-trash"/></svg> {{ __('alert_rules.delete') }}</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>

                <x-pagination :paginator="$rules" />
            @endif
        </section>
    </div>
</x-layouts.app>
