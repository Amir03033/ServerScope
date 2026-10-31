@php
    use App\Services\Dependencies\ResourceCatalog;
    use App\Support\Label;
    use App\Support\Time;

    $w = $tm['window'];
    $tone = fn (array $e) => match (true) {
        $e['category'] === 'recovery' => 'is-ok',
        $e['problem'] => 'is-bad',
        in_array($e['category'], ['change', 'anomaly'], true) => 'is-warn',
        default => 'is-info',
    };
@endphp

<x-layouts.app :title="__('time_machine.title', ['id' => $incident->id])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">{{ __('incidents.title') }}</a> <span>›</span> <a href="{{ route('incidents.show', $incident) }}">#{{ $incident->id }}</a> <span>›</span> {{ __('time_machine.open') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $incident->title }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('time_machine.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('incidents.show', $incident) }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('time_machine.back') }}</a>
    </x-slot:actions>

    <section class="ss-panel mb-4 grid gap-3" data-testid="tm-window">
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center">
            <x-severity :severity="$incident->severity" />
            <x-status :status="$incident->status" />
        </div>
        <p class="m-0 text-sm text-muted">{{ __('time_machine.window', ['from' => Time::dateTime($w['from']), 'to' => Time::dateTime($w['to']), 'onset' => Time::dateTimeSeconds($w['onset']), 'before' => \App\Services\Incidents\TimeMachine::BEFORE_HOURS, 'after' => \App\Services\Incidents\TimeMachine::AFTER_HOURS]) }}</p>
        @if ($w['truncated'])
            <p class="m-0 text-sm text-muted">{{ __('time_machine.truncated', ['hours' => \App\Services\Incidents\TimeMachine::MAX_SPAN_HOURS]) }}</p>
        @endif
        <div class="ss-alert ss-alert-info" role="note" data-testid="tm-caveat">
            <svg class="ico"><use href="#i-alert"/></svg>
            <div>{{ __('time_machine.caveat') }}</div>
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="evidence-title" data-testid="tm-evidence">
                <div class="ss-panel-head"><div><h2 id="evidence-title" class="ss-panel-title">{{ __('time_machine.evidence_title') }}</h2><p class="ss-panel-sub">{{ __('time_machine.evidence_text') }}</p></div></div>
                @forelse ($tm['findings'] as $finding)
                    <p class="m-0 mt-2 text-sm text-fg">• {{ $finding['text'] }}</p>
                @empty
                    <p class="m-0 text-sm text-muted">{{ __('time_machine.evidence_none') }}</p>
                @endforelse
            </section>

            <section class="ss-panel" aria-labelledby="explanations-title" data-testid="tm-explanations">
                <div class="ss-panel-head"><div><h2 id="explanations-title" class="ss-panel-title">{{ __('time_machine.explanations_title') }}</h2><p class="ss-panel-sub">{{ __('time_machine.explanations_text') }}</p></div></div>
                @forelse ($tm['explanations'] as $explanation)
                    <div class="mt-3">
                        <p class="m-0 text-sm text-fg"><strong>{{ $explanation['text'] }}</strong></p>
                        <p class="m-0 text-xs text-muted">{{ __('time_machine.basis') }}: {{ $explanation['basis'] }}</p>
                    </div>
                @empty
                    <p class="m-0 text-sm text-muted">{{ __('time_machine.explanations_none') }}</p>
                @endforelse
            </section>

            @foreach ($tm['metrics'] as $m)
                <section class="ss-panel" aria-labelledby="metrics-{{ $loop->index }}" data-testid="tm-metrics">
                    <div class="ss-panel-head"><div><h2 id="metrics-{{ $loop->index }}" class="ss-panel-title">{{ __('time_machine.metrics_title', ['server' => $m['label']]) }}</h2><p class="ss-panel-sub">{{ __('time_machine.metrics_hourly', ['hours' => \App\Services\Incidents\TimeMachine::BASELINE_HOURS]) }}</p></div></div>
                    @foreach ($m['hourly'] as $metric => $points)
                        <h3 class="m-0 mt-3 text-xs text-muted">{{ __('time_machine.metric.'.$metric) }}</h3>
                        <x-line-chart :points="$points" :unit="$metric === 'load_1' ? '' : '%'" :height="120" :label="__('time_machine.metric.'.$metric)" :flag-label="__('time_machine.incident_period')" />
                    @endforeach
                    @if ($m['detail'] !== [])
                        <h3 class="m-0 mt-4 text-sm text-fg">{{ __('time_machine.metrics_detail') }}</h3>
                        @foreach ($m['detail'] as $metric => $points)
                            <h3 class="m-0 mt-3 text-xs text-muted">{{ __('time_machine.metric.'.$metric) }}</h3>
                            <x-line-chart :points="$points" unit="%" :height="120" :label="__('time_machine.metric.'.$metric)" :flag-label="__('time_machine.incident_period')" />
                        @endforeach
                    @endif
                </section>
            @endforeach

            <section class="ss-panel" id="timeline" aria-labelledby="tm-timeline-title" data-testid="tm-timeline">
                <div class="ss-panel-head"><div><h2 id="tm-timeline-title" class="ss-panel-title">{{ __('time_machine.timeline_title') }}</h2><p class="ss-panel-sub">{{ __('time_machine.timeline_text') }}</p></div></div>
                @if ($tm['events'] === [])
                    <p class="m-0 text-sm text-muted">{{ __('time_machine.timeline_none') }}</p>
                @else
                    <ul class="ss-timeline">
                        @foreach ($tm['events'] as $event)
                            <li @class([$tone($event)]) data-phase="{{ $event['phase'] }}">
                                <div class="ss-timeline-when"><time datetime="{{ $event['at']->toIso8601String() }}">{{ Time::dateTimeSeconds($event['at']) }}</time> · {{ __('time_machine.phase.'.$event['phase']) }} · {{ __('time_machine.category.'.$event['category']) }}@if ($event['resource']) · {{ $event['resource'] }}@endif</div>
                                <div class="ss-timeline-what"><strong>@if ($event['url'])<a href="{{ $event['url'] }}">{{ $event['title'] }}</a>@else{{ $event['title'] }}@endif</strong></div>
                                @if ($event['detail'])<div class="ss-timeline-body text-fg">{{ $event['detail'] }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="tm-resources-title" data-testid="tm-resources">
                <div class="ss-panel-head"><div><h2 id="tm-resources-title" class="ss-panel-title">{{ __('time_machine.resources_title') }}</h2><p class="ss-panel-sub">{{ __('time_machine.resources_text') }}</p></div></div>
                @foreach ($tm['resources'] as $resource)
                    @php $url = ResourceCatalog::url($resource['model']); @endphp
                    <p class="m-0 mt-2 text-sm">
                        @if ($url)<a href="{{ $url }}">{{ $resource['label'] }}</a>@else{{ $resource['label'] }}@endif
                        <span class="block text-xs text-muted">{{ Label::of('resource_type', $resource['type'], ResourceCatalog::TYPES) }} · {{ __('time_machine.relation.'.$resource['relation']) }}</span>
                    </p>
                @endforeach
            </section>

            @if ($tm['gaps'] !== [])
                <section class="ss-panel" aria-labelledby="tm-gaps-title" data-testid="tm-gaps">
                    <div class="ss-panel-head"><div><h2 id="tm-gaps-title" class="ss-panel-title">{{ __('time_machine.gaps_title') }}</h2></div></div>
                    @foreach ($tm['gaps'] as $gap)
                        <p class="m-0 mt-2 text-sm text-muted">{{ $gap }}</p>
                    @endforeach
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
