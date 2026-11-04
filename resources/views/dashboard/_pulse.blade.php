{{-- Heartbeat: one live line per server (rate: CPU, width: slowest website),
     then one per scheduled job (calm while it checks in on time, haywire when late or failed).
     Compact inside mission control, right under the last 24 hours. --}}
@php $pulseJobs ??= collect(); @endphp
@if (($servers->isNotEmpty() || $pulseJobs->isNotEmpty()) && Route::has('nexus.graph'))
        <section class="{{ $compact ? 'ss-panel ss-pulse-compact' : 'ss-panel mb-6' }}" data-pulse data-focus-hide aria-labelledby="pulse-title">
            <script type="application/json" id="pulse-config">@json(['urls' => ['graph' => route('nexus.graph')], 'texts' => __('dashboard.pulse.js')])</script>
            @if ($compact)
                <h3 id="pulse-title" class="ss-mission-subtitle">{{ __('dashboard.pulse.title') }}</h3>
            @else
            <div class="ss-panel-head">
                <div>
                    <h2 id="pulse-title" class="ss-panel-title flex items-center gap-2"><svg class="ico text-accent"><use href="#i-pulse"/></svg>{{ __('dashboard.pulse.title') }}</h2>
                    <p class="ss-panel-sub">{{ __('dashboard.pulse.text') }}@if ($pulseJobs->isNotEmpty()) {{ __('dashboard.pulse.jobs_text') }}@endif</p>
                </div>
            </div>
            @endif
            <ul class="ss-pulse-list{{ $compact ? ' is-compact' : '' }}" role="list">
                @foreach ($servers->take($compact ? 6 : 12) as $server)
                    <li class="ss-pulse-row" data-pulse-row="server:{{ $server->id }}" data-rhythm="unknown">
                        <div class="min-w-0">
                            <a class="block truncate font-semibold text-fg no-underline hover:underline" href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
                            <span class="ss-pulse-state" data-pulse-state>{{ __('dashboard.pulse.js.rhythm.unknown') }}</span>
                            <span class="ss-pulse-reason" data-pulse-reason></span>
                        </div>
                        <canvas class="ss-pulse-canvas" data-pulse-canvas aria-hidden="true"></canvas>
                        <dl class="ss-pulse-numbers">
                            <div><dt>{{ __('dashboard.pulse.cpu') }}</dt><dd data-pulse-cpu>—</dd></div>
                            <div><dt>{{ __('dashboard.pulse.response') }}</dt><dd data-pulse-ms>—</dd></div>
                            <div><dt>{{ __('dashboard.pulse.rate') }}</dt><dd data-pulse-bpm>—</dd></div>
                        </dl>
                    </li>
                @endforeach
                @foreach ($pulseJobs as $job)
                    @php $rhythm = ['up' => 'steady', 'down' => 'chaos', 'paused' => 'rest'][$job->status] ?? 'unknown'; @endphp
                    <li class="ss-pulse-row is-job" data-pulse-row="heartbeat:{{ $job->id }}" data-rhythm="{{ $rhythm }}" data-job>
                        <div class="min-w-0">
                            <a class="ss-pulse-job-name font-semibold text-fg no-underline hover:underline" href="{{ route('heartbeats.show', $job) }}" title="{{ $job->name }}"><svg class="ico ss-pulse-job-ico" aria-hidden="true"><use href="#i-clock"/></svg>{{ $job->name }}</a>
                            <span class="ss-pulse-state" data-pulse-state>{{ __('dashboard.pulse.js.job.'.$rhythm) }}</span>
                            <span class="ss-pulse-reason" data-pulse-reason>{{ $job->server?->name }}</span>
                        </div>
                        <canvas class="ss-pulse-canvas" data-pulse-canvas aria-hidden="true"></canvas>
                        <dl class="ss-pulse-numbers">
                            <div><dt>{{ __('dashboard.pulse.last_run') }}</dt><dd>{{ $job->last_ping_at ? $job->last_ping_at->locale(app()->getLocale())->shortRelativeDiffForHumans() : '—' }}</dd></div>
                            <div><dt>{{ __('dashboard.pulse.schedule') }}</dt><dd>{{ $job->schedule_type === \App\Models\Heartbeat::SCHEDULE_CRON ? $job->cron_expression : mb_strtolower(\App\Models\Heartbeat::periodLabel((int) $job->period_minutes)) }}</dd></div>
                        </dl>
                    </li>
                @endforeach
            </ul>
            @if ($servers->count() > ($compact ? 6 : 12))
                <p class="mt-3 mb-0 text-xs text-muted">{{ __('dashboard.pulse.more', ['count' => $servers->count() - ($compact ? 6 : 12)]) }} <a href="{{ route('nexus.skyline') }}">{{ __('dashboard.pulse.skyline_link') }}</a></p>
            @endif
        </section>
    @endif
