{{-- The last 24 hours: incidents, deployments, DNS changes. --}}
<section class="ss-panel ss-widget-panel" aria-labelledby="timeline-title">
    <div data-mission-timeline-block>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="timeline-title" class="ss-mission-subtitle">{{ __('mission.timeline_title') }}</h2>
            <ul class="ss-mission-legend" aria-hidden="true">
                @foreach (['incident', 'deploy', 'dns'] as $kind)<li class="is-{{ $kind }}">{{ __('mission.legend.'.$kind) }}</li>@endforeach
            </ul>
        </div>
        <div class="ss-mission-timeline" data-mission-timeline>
            @foreach ($m['timeline']['events'] as $e)
                @php $left = $pos($e['at']); $right = $e['kind'] === 'incident' ? $pos($e['until']) : $left; @endphp
                <a class="ss-mevent is-{{ $e['kind'] }} tone-{{ $e['tone'] }}" href="{{ $e['url'] ?? '#' }}" style="left: {{ round($left, 2) }}%; width: {{ $e['kind'] === 'incident' ? max(0.8, round($right - $left, 2)) : 0 }}%" title="{{ __('mission.legend.'.$e['kind']) }} · {{ \App\Support\Time::format(\Carbon\Carbon::parse($e['at']), 'H:i') }} · {{ $e['label'] }}"><span class="sr-only">{{ __('mission.legend.'.$e['kind']) }}: {{ $e['label'] }}</span></a>
            @endforeach
            <div class="ss-mission-hours" aria-hidden="true">
                @for ($h = 0; $h <= 24; $h += 6)<span style="left: {{ $h / 24 * 100 }}%">{{ \App\Support\Time::format($from->copy()->addHours($h), 'H:i') }}</span>@endfor
            </div>
        </div>
        @if ($m['timeline']['events'] === [])<p class="mt-2 mb-0 text-xs text-muted" data-mission-timeline-empty>{{ __('mission.timeline_empty') }}</p>@endif
    </div>
</section>
