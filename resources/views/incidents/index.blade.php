@php
    use App\Models\Incident;
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Label;
    use App\Support\Severity;
    use App\Support\Time;

    $tabs = ['active' => $counts['active'], 'unacknowledged' => $counts['unacknowledged'], 'resolved' => null, 'closed' => null, 'all' => null];
    $points = collect($perDay)->map(fn ($n, $date) => ['label' => \Carbon\Carbon::parse($date)->format('d-m'), 'value' => (float) $n, 'flag' => false])->values()->all();
@endphp

<x-layouts.app :title="__('incidents.title')">
    <x-slot:subtitle>{{ __('incidents.index.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        @can('admin')
            <a href="{{ route('incidents.create') }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('incidents.index.report') }}</a>
        @endcan
    </x-slot:actions>

    <div class="grid gap-4">
        <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="{{ __('incidents.index.stats_label') }}">
            <a @class(['ss-stat', 'is-bad' => $counts['critical'] > 0, 'is-warn' => $counts['critical'] === 0 && $counts['active'] > 0]) href="{{ route('incidents.index', ['state' => 'active']) }}">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('incidents.index.active') }}</div><div class="ss-stat-value">{{ $counts['active'] }}</div><div class="ss-stat-caption">{{ __('incidents.index.critical_count', ['count' => $counts['critical']]) }}</div></div>
            </a>
            <a @class(['ss-stat', 'is-warn' => $counts['unacknowledged'] > 0]) href="{{ route('incidents.index', ['state' => 'unacknowledged']) }}">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-bell"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('incidents.index.unacknowledged') }}</div><div class="ss-stat-value">{{ $counts['unacknowledged'] }}</div></div>
            </a>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('incidents.index.mtta') }}</div><div class="ss-stat-value">{{ IncidentMetrics::humanDuration($summary['mtta_seconds']) }}</div><div class="ss-stat-caption">{{ __('incidents.index.mtta_caption', ['acknowledged' => $summary['acknowledged'], 'opened' => $summary['opened']]) }}</div></div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('incidents.index.mttr') }}</div><div class="ss-stat-value">{{ IncidentMetrics::humanDuration($summary['mttr_seconds']) }}</div><div class="ss-stat-caption">{{ __('incidents.index.mttr_caption', ['count' => $counts['resolved_7d'], 'mttd' => IncidentMetrics::humanDuration($summary['mttd_seconds'])]) }}</div></div>
            </article>
        </section>

        <section class="ss-panel" aria-labelledby="frequency-title">
            <div class="ss-panel-head"><div><h2 id="frequency-title" class="ss-panel-title">{{ __('incidents.index.frequency_title') }}</h2><p class="ss-panel-sub">{{ __('incidents.index.frequency_text') }}</p></div></div>
            <x-line-chart :points="$points" :height="120" :label="__('incidents.index.frequency_label')" />
        </section>

        <section class="ss-panel">
            <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('incidents.index.filter_label') }}">
                <div class="ss-segmented">
                    @foreach ($tabs as $key => $count)
                        <a href="{{ route('incidents.index', array_filter([...request()->except(['state', 'page']), 'state' => $key])) }}" @if ($state === $key) aria-current="page" @endif>
                            {{ __('incidents.index.tabs.'.$key) }} @if ($count !== null)<span class="ss-count">{{ $count }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </nav>

            <form method="GET" action="{{ route('incidents.index') }}" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto] lg:items-end" role="search">
                <input type="hidden" name="state" value="{{ $state }}">
                @foreach (['server', 'domain'] as $key)
                    @if (! empty($filters[$key]))<input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">@endif
                @endforeach
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="ss-label" for="q">{{ __('incidents.index.search') }}</label>
                    <input id="q" class="ss-input mt-1.5" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('incidents.index.search_placeholder') }}">
                </div>
                <div>
                    <label class="ss-label" for="severity">{{ __('incidents.index.severity') }}</label>
                    <select id="severity" class="ss-input mt-1.5" name="severity"><option value="">{{ __('incidents.index.all') }}</option>
                        @foreach (Severity::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['severity'] ?? null) === $v)>{{ Label::of('severity', $v, Severity::LABELS) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="source">{{ __('incidents.index.source') }}</label>
                    <select id="source" class="ss-input mt-1.5" name="source"><option value="">{{ __('incidents.index.all') }}</option>
                        @foreach (Incident::SOURCE_LABELS as $v => $l)<option value="{{ $v }}" @selected(($filters['source'] ?? null) === $v)>{{ Label::of('incident_source', $v, Incident::SOURCE_LABELS) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="assigned">{{ __('incidents.index.assigned') }}</label>
                    <select id="assigned" class="ss-input mt-1.5" name="assigned"><option value="">{{ __('incidents.index.everyone') }}</option><option value="me" @selected(($filters['assigned'] ?? null) === 'me')>{{ __('incidents.index.to_me') }}</option><option value="none" @selected(($filters['assigned'] ?? null) === 'none')>{{ __('incidents.nobody') }}</option></select>
                </div>
                <div>
                    <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('incidents.index.filter') }}</button>
                </div>
            </form>

            @if ($incidents->isEmpty())
                <x-empty-state :icon="$state === 'active' ? 'i-check' : 'i-search'" :title="$state === 'active' ? __('incidents.index.empty_active_title') : __('incidents.index.none_found')">
                    {{ $state === 'active' ? __('incidents.index.empty_active_text') : __('incidents.index.none_found_text') }}
                </x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('incidents.index.col_incident') }}</th><th scope="col">{{ __('incidents.index.col_severity') }}</th><th scope="col">{{ __('incidents.index.col_status') }}</th><th scope="col">{{ __('incidents.index.col_subject') }}</th><th scope="col">{{ __('incidents.index.col_opened') }}</th><th scope="col">{{ __('incidents.index.col_duration') }}</th><th scope="col">{{ __('incidents.index.col_assigned') }}</th></tr></thead>
                        <tbody>
                            @foreach ($incidents as $incident)
                                <tr>
                                    <td class="is-primary">
                                        <a class="font-semibold text-fg no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>
                                        <span class="block text-xs text-muted">#{{ $incident->id }} · {{ Label::of('incident_source', $incident->source, Incident::SOURCE_LABELS) }}@if ($incident->occurrence_count > 1){{ __('incidents.index.seen_times', ['count' => $incident->occurrence_count]) }}@endif @if ($incident->reopened_count){{ __('incidents.index.reopened_times', ['count' => $incident->reopened_count]) }}@endif @if ($incident->parent_id){{ __('incidents.index.grouped_under', ['id' => $incident->parent_id]) }}@endif @if ($incident->children_count){{ __('incidents.index.related_count', ['count' => $incident->children_count]) }}@endif</span>
                                    </td>
                                    <td data-label="{{ __('incidents.index.col_severity') }}"><x-severity :severity="$incident->severity" /></td>
                                    <td data-label="{{ __('incidents.index.col_status') }}"><x-status :status="$incident->status" /></td>
                                    <td data-label="{{ __('incidents.index.col_subject') }}" class="text-sm">{{ $incident->subjectName() }}</td>
                                    <td data-label="{{ __('incidents.index.col_opened') }}" class="whitespace-nowrap" title="{{ Time::format($incident->opened_at, 'd-m-Y H:i:s') }}">{{ Time::relative($incident->opened_at) }}</td>
                                    <td data-label="{{ __('incidents.index.col_duration') }}" class="whitespace-nowrap">{{ IncidentMetrics::humanDuration($incident->resolved_at ? $incident->timeToResolve() : (int) $incident->opened_at->diffInSeconds(now(), true)) }}</td>
                                    <td data-label="{{ __('incidents.index.col_assigned') }}" class="text-sm">{{ $incident->assignee?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$incidents" />
            @endif
        </section>
    </div>
</x-layouts.app>
