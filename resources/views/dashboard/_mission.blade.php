{{-- Mission control: one score, every server and website, the last 24 hours.
     Rendered here (works without JavaScript); mission.js keeps it live. --}}
@php
    $m = $mission;
    $ring = 2 * M_PI * 52;
    $from = \Carbon\Carbon::parse($m['timeline']['from']);
    $span = max(1, \Carbon\Carbon::parse($m['timeline']['to'])->getTimestamp() - $from->getTimestamp());
    // Only what mission.js needs (the other texts arrive translated in the data).
    $missionConfig = ['url' => route('dashboard.mission'), 'texts' => ['counts' => __('mission.counts'), 'legend' => __('mission.legend')], 'initial' => $m];
    $pos = fn (?string $iso) => $iso ? max(0, min(100, (\Carbon\Carbon::parse($iso)->getTimestamp() - $from->getTimestamp()) / $span * 100)) : 100;
@endphp
<section class="ss-mission mb-6" data-mission data-grade="{{ $m['grade'] }}" aria-labelledby="mission-title">
    <script type="application/json" id="mission-config">@json($missionConfig)</script>

    <div class="ss-mission-hero">
        <div class="ss-mission-score" role="img" aria-label="{{ $m['score'] === null ? __('mission.score_none') : __('mission.score_label', ['score' => $m['score']]) }}">
            <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="track" cx="60" cy="60" r="52"/>
                <circle class="value" cx="60" cy="60" r="52" stroke-dasharray="{{ round($ring, 2) }}" stroke-dashoffset="{{ round($ring * (1 - ($m['score'] ?? 0) / 100), 2) }}" data-mission-ring/>
            </svg>
            <div class="ss-mission-number"><strong data-mission-score>{{ $m['score'] ?? '—' }}</strong><span>/100</span></div>
        </div>

        <div class="min-w-0">
            <p class="ss-mission-kicker">{{ __('mission.kicker') }}</p>
            <h2 id="mission-title" class="ss-mission-headline" data-mission-headline>{{ $m['headline'] }}</h2>
            <p class="ss-mission-counts" data-mission-counts>{{ __('mission.counts', $m['counts']) }}</p>
            <ul class="ss-mission-issues" data-mission-issues>
                @foreach ($m['issues'] as $issue)
                    <li><a class="ss-mission-issue is-{{ $issue['tone'] }}" href="{{ $issue['url'] }}">{{ $issue['label'] }}</a></li>
                @endforeach
            </ul>
        </div>

        <button type="button" class="ss-mission-focus" data-mission-focus aria-pressed="false" title="{{ __('mission.focus_hint') }}">
            <svg class="ico" aria-hidden="true"><use href="#i-target"/></svg> {{ __('mission.focus') }}
        </button>
    </div>

    <div class="ss-mission-calm" data-mission-calm hidden>
        <strong>{{ __('mission.focus_calm') }}</strong>
        <span>{{ __('mission.focus_calm_text') }}</span>
    </div>

    <div class="ss-mission-block" data-mission-tiles-block>
        <h3 class="ss-mission-subtitle">{{ __('mission.tiles_title') }}</h3>
        <div class="ss-mission-tiles" data-mission-tiles>
            @forelse ($m['tiles'] as $tile)
                <a class="ss-mtile" href="{{ $tile['url'] }}" data-health="{{ $tile['health'] }}" data-key="{{ $tile['key'] }}" title="{{ $tile['name'] }}">
                    <span class="ss-mtile-dot" aria-hidden="true"></span>
                    <span class="ss-mtile-name">{{ $tile['name'] }}</span>
                    <span class="ss-mtile-value">{{ $tile['value'] === null ? '' : ($tile['type'] === 'server' ? round($tile['value']).'%' : round($tile['value']).' ms') }}</span>
                </a>
            @empty
                <p class="m-0 text-sm text-muted">{{ __('mission.tiles_empty') }}</p>
            @endforelse
        </div>
    </div>

    <div class="ss-mission-block" data-mission-timeline-block>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="ss-mission-subtitle">{{ __('mission.timeline_title') }}</h3>
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

<div class="ss-mission-details-head" data-dashboard-details-head>
    <h2 class="ss-section-title m-0">{{ __('mission.details') }}</h2>
    <p class="m-0 text-xs text-muted">{{ __('mission.details_text') }}</p>
</div>
