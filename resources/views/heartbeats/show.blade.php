@php
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $tone = ['up' => 'up', 'down' => 'down', 'paused' => 'paused', 'new' => 'unknown'];
    $url = $heartbeat->pingUrl();
    $duration = fn (?int $ms) => $ms === null ? '—' : ($ms < 1000 ? $ms.' ms' : IncidentMetrics::humanDuration(intdiv($ms, 1000)));
    $curl = 'curl -fsS -m 10 --retry 3 -o /dev/null';
    $examples = [
        'cron' => "0 3 * * * /usr/local/bin/backup.sh && {$curl} {$url}",
        'full' => "{$curl} {$url}/start\noutput=\$(/usr/local/bin/backup.sh 2>&1); code=\$?\n{$curl} --data-raw \"\$(printf '%s' \"\$output\" | tail -c 2000)\" {$url}/\$code",
        'laravel' => "Schedule::command('backup:run')->daily()\n    ->pingBefore('{$url}/start')\n    ->pingOnSuccess('{$url}')\n    ->pingOnFailure('{$url}/fail');",
        'database' => "# Database check, every 5 minutes (MySQL / MariaDB):\n*/5 * * * * mysqladmin ping --silent && {$curl} {$url}\n# PostgreSQL: */5 * * * * pg_isready -q && {$curl} {$url}\n\n# Or a nightly backup:\n0 3 * * * mysqldump --single-transaction --all-databases | gzip > /var/backups/db-\$(date +\\%F).sql.gz && {$curl} {$url}",
        'fail' => "{$curl} {$url}/fail",
    ];
@endphp

<x-layouts.app :title="$heartbeat->name">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('heartbeats.index') }}">{{ __('heartbeats.title') }}</a> <span>›</span> {{ $heartbeat->name }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $heartbeat->name }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ $heartbeat->scheduleLabel() }}@if ($heartbeat->server) · {{ $heartbeat->server->name }}@endif</x-slot:subtitle>
    @if ($isAdmin)
        <x-slot:actions>
            <a class="ss-btn ss-btn-secondary" href="{{ route('heartbeats.edit', $heartbeat) }}"><svg class="ico"><use href="#i-edit"/></svg> {{ __('heartbeats.show.actions.edit') }}</a>
            <form method="POST" action="{{ route('heartbeats.pause', $heartbeat) }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#{{ $heartbeat->isPaused() ? 'i-play' : 'i-pause' }}"/></svg> {{ $heartbeat->isPaused() ? __('heartbeats.show.actions.resume') : __('heartbeats.show.actions.pause') }}</button></form>
            <x-row-actions :label="__('heartbeats.show.actions.more')">
                <form method="POST" action="{{ route('heartbeats.rotate', $heartbeat) }}" data-confirm="{{ __('heartbeats.show.actions.rotate_confirm') }}">@csrf<button type="submit" class="ss-menu-item" role="menuitem"><svg class="ico" aria-hidden="true"><use href="#i-key"/></svg>{{ __('heartbeats.show.actions.rotate') }}</button></form>
                <form method="POST" action="{{ route('heartbeats.destroy', $heartbeat) }}" data-confirm="{{ __('heartbeats.show.actions.delete_confirm') }}">@csrf @method('DELETE')<button type="submit" class="ss-menu-item" role="menuitem"><svg class="ico" aria-hidden="true"><use href="#i-trash"/></svg>{{ __('heartbeats.show.actions.delete') }}</button></form>
            </x-row-actions>
        </x-slot:actions>
    @endif

    <div class="grid gap-4">
        {{-- State and the last runs --}}
        <section class="ss-panel grid gap-5" aria-labelledby="hb-state-title">
            <h2 id="hb-state-title" class="sr-only">{{ __('heartbeats.show.facts.status') }}</h2>
            <dl class="ss-inc-facts ss-hb-facts">
                <div><dt>{{ __('heartbeats.show.facts.status') }}</dt><dd><x-status :status="$tone[$heartbeat->status]" :label="__('heartbeats.status.'.$heartbeat->status)" /></dd></div>
                <div><dt>{{ __('heartbeats.show.facts.last') }}</dt><dd>{{ $heartbeat->last_ping_at ? Time::relative($heartbeat->last_ping_at) : __('heartbeats.never') }}@if ($heartbeat->last_ping_at)<span>{{ Time::dateTime($heartbeat->last_ping_at) }}</span>@endif</dd></div>
                @if ($heartbeat->status === 'down' && $heartbeat->next_due_at)
                    <div><dt>{{ __('heartbeats.show.facts.late') }}</dt><dd class="ss-text-bad">{{ Time::relative($heartbeat->next_due_at) }}<span>{{ Time::dateTime($heartbeat->next_due_at) }}</span></dd></div>
                @else
                    <div><dt>{{ __('heartbeats.show.facts.next') }}</dt><dd>{{ $heartbeat->lateAt() ? Time::relative($heartbeat->lateAt()) : '—' }}@if ($heartbeat->lateAt())<span>{{ Time::dateTime($heartbeat->lateAt()) }}</span>@endif</dd></div>
                @endif
                <div><dt>{{ __('heartbeats.show.facts.duration') }}</dt><dd>{{ $duration($heartbeat->last_duration_ms) }}</dd></div>
                <div><dt>{{ __('heartbeats.show.facts.grace') }}</dt><dd>{{ $heartbeat->grace_minutes }} {{ __('heartbeats.form.minutes') }}</dd></div>
            </dl>

            @if ($heartbeat->isPaused())
                <p class="ss-hb-note"><svg class="ico" aria-hidden="true"><use href="#i-pause"/></svg> {{ __('heartbeats.show.paused_text') }}</p>
            @elseif ($heartbeat->status === 'new')
                <p class="ss-hb-note"><svg class="ico" aria-hidden="true"><use href="#i-clock"/></svg> {{ __('heartbeats.show.waiting_text') }}</p>
            @endif

            @if ($incident)
                <a class="ss-hb-incident" data-active="{{ $incident->isActive() ? 'true' : 'false' }}" href="{{ route('incidents.show', $incident) }}">
                    <svg class="ico" aria-hidden="true"><use href="#i-siren"/></svg>
                    <span>{{ __($incident->isActive() ? 'heartbeats.show.incident_open' : 'heartbeats.show.incident_last', ['title' => $incident->title]) }}</span>
                    <x-status :status="$incident->status" />
                </a>
            @endif

            <div>
                <h3 class="ss-ai-label mb-2">{{ __('heartbeats.show.runs_title') }}</h3>
                @if ($runs->isEmpty())
                    <p class="m-0 text-sm text-muted">{{ __('heartbeats.show.runs_empty') }}</p>
                @else
                    <div class="ss-hb-timeline" role="img" aria-label="{{ __('heartbeats.runs_legend') }}">
                        @foreach ($runs as $run)
                            <i data-kind="{{ $run->kind }}" title="{{ __('heartbeats.show.kind.'.$run->kind) }} · {{ Time::dateTimeSeconds($run->received_at) }}@if ($run->duration_ms !== null) · {{ $duration($run->duration_ms) }}@endif"></i>
                        @endforeach
                    </div>
                    <p class="m-0 mt-2 text-xs text-subtle">{{ __('heartbeats.runs_legend') }}</p>
                @endif
            </div>
        </section>

        {{-- How to make the job report in --}}
        <section class="ss-panel grid gap-4" aria-labelledby="hb-url-title">
            <div>
                <h2 id="hb-url-title" class="ss-panel-title">{{ __('heartbeats.show.url_title') }}</h2>
                <p class="ss-panel-sub">{{ __('heartbeats.show.url_text') }}</p>
            </div>
            <div class="ss-hb-url">
                <input id="hb-url" class="ss-input font-mono" readonly value="{{ $url }}" aria-label="{{ __('heartbeats.show.url_title') }}">
                <button class="ss-btn ss-btn-primary" type="button" data-copy-from="hb-url"><svg class="ico"><use href="#i-copy"/></svg> {{ __('common.copy') }}</button>
            </div>

            <details class="ss-fold" @if ($heartbeat->status === 'new') open @endif>
                <summary><span><strong>{{ __('heartbeats.show.setup') }}</strong></span></summary>
                <div class="grid gap-5">
                    @foreach ($examples as $key => $code)
                        <div class="min-w-0">
                            <p class="m-0 text-sm font-semibold">{{ __('heartbeats.show.examples.'.$key) }}</p>
                            <p class="m-0 mb-2 text-xs text-muted">{{ __('heartbeats.show.examples.'.$key.'_text') }}</p>
                            <x-terminal :id="'hb-example-'.$key" :label="__('heartbeats.show.examples.'.$key)">{{ $code }}</x-terminal>
                        </div>
                    @endforeach
                </div>
            </details>
        </section>

        {{-- Everything the job reported --}}
        <section class="ss-panel" aria-labelledby="hb-pings-title">
            <div class="ss-panel-head"><div><h2 id="hb-pings-title" class="ss-panel-title">{{ __('heartbeats.show.pings_title') }}</h2><p class="ss-panel-sub">{{ __('heartbeats.show.pings_text') }}</p></div></div>
            @if ($pings->isEmpty())
                <p class="m-0 text-sm text-muted">{{ __('heartbeats.show.runs_empty') }}</p>
            @else
                <ul class="ss-hb-pings" role="list">
                    @foreach ($pings as $ping)
                        <li data-kind="{{ $ping->kind }}">
                            <span class="ss-hb-ping-dot" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="ss-hb-ping-head">
                                    <strong>{{ __('heartbeats.show.kind.'.$ping->kind) }}</strong>
                                    @if ($ping->exit_code !== null)<span class="ss-ai-ref">{{ __('heartbeats.show.exit', ['code' => $ping->exit_code]) }}</span>@endif
                                    @if ($ping->duration_ms !== null)<span class="text-muted">{{ __('heartbeats.show.took', ['duration' => $duration($ping->duration_ms)]) }}</span>@endif
                                    <time datetime="{{ $ping->received_at->toIso8601String() }}">{{ Time::dateTimeSeconds($ping->received_at) }}</time>
                                </p>
                                @if ($ping->body)
                                    <details class="ss-hb-output">
                                        <summary>{{ __('heartbeats.show.output') }}</summary>
                                        <pre>{{ $ping->body }}</pre>
                                    </details>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <x-pagination :paginator="$pings" />
            @endif
        </section>
    </div>
</x-layouts.app>
