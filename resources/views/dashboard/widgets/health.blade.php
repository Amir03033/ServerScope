{{-- Health: one score and the sentence that explains it (mission.js keeps it live). --}}
<section class="ss-mission" data-mission data-grade="{{ $m['grade'] }}" aria-labelledby="mission-title">
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
</section>
