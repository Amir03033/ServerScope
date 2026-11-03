{{-- Heartbeat: one live line per server (rate: CPU, width: slowest website).
     Compact inside mission control, right under the last 24 hours. --}}
@if ($servers->isNotEmpty() && Route::has('nexus.graph'))
        <section class="{{ $compact ? 'ss-panel ss-pulse-compact' : 'ss-panel mb-6' }}" data-pulse data-focus-hide aria-labelledby="pulse-title">
            <script type="application/json" id="pulse-config">@json(['urls' => ['graph' => route('nexus.graph')], 'texts' => __('dashboard.pulse.js')])</script>
            @if ($compact)
                <h3 id="pulse-title" class="ss-mission-subtitle">{{ __('dashboard.pulse.title') }}</h3>
            @else
            <div class="ss-panel-head">
                <div>
                    <h2 id="pulse-title" class="ss-panel-title flex items-center gap-2"><svg class="ico text-accent"><use href="#i-pulse"/></svg>{{ __('dashboard.pulse.title') }}</h2>
                    <p class="ss-panel-sub">{{ __('dashboard.pulse.text') }}</p>
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
            </ul>
            @if ($servers->count() > ($compact ? 6 : 12))
                <p class="mt-3 mb-0 text-xs text-muted">{{ __('dashboard.pulse.more', ['count' => $servers->count() - ($compact ? 6 : 12)]) }} <a href="{{ route('nexus.skyline') }}">{{ __('dashboard.pulse.skyline_link') }}</a></p>
            @endif
        </section>
    @endif
