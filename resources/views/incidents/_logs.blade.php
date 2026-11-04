{{-- The most frequent log problems on the servers involved, around the incident. --}}
@php use App\Support\Time; @endphp
<section class="ss-panel" id="logs" aria-labelledby="logs-title">
    <div class="ss-panel-head">
        <div>
            <h2 id="logs-title" class="ss-panel-title">{{ __('logs.incident.title') }}</h2>
            <p class="ss-panel-sub">{{ __('logs.incident.text', ['before' => \App\Services\Logs\IncidentLogs::BEFORE_MINUTES]) }}</p>
        </div>
        @if ($logs['total'] > 0)
            <a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('logs.index', ['incident' => $incident->id]) }}"><svg class="ico"><use href="#i-logs"/></svg> {{ __('logs.incident.all') }} <span class="ss-count">{{ $logs['total'] }}</span></a>
        @endif
    </div>

    @if ($logs['issues']->isEmpty())
        <p class="m-0 text-sm text-muted">{{ __('logs.incident.empty') }}</p>
    @else
        <ul class="ss-issues" role="list">
            @foreach ($logs['issues'] as $issue)
                <li>
                    <a class="ss-issue" data-level="{{ $issue['level'] }}" href="{{ route('logs.index', ['incident' => $incident->id, 'view' => 'stream', 'fingerprint' => $issue['fingerprint']]) }}">
                        <span class="ss-issue-main">
                            <span class="ss-issue-message">{{ $issue['entry']->message }}</span>
                            <span class="ss-issue-meta">
                                <span class="ss-loglevel" data-level="{{ $issue['level'] }}">{{ __('logs.levels.'.$issue['level']) }}</span>
                                <span class="ss-chip">{{ $issue['entry']->source }}</span>
                                <span>{{ $issue['entry']->server?->name }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ __('logs.issue.first', ['time' => Time::format($issue['first'], 'H:i')]) }}</span>
                            </span>
                        </span>
                        <span class="ss-issue-side"><span class="ss-issue-count">{{ __('logs.issue.times', ['count' => $issue['count']]) }}</span></span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
